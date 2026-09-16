<?php
/**
 * ============================================================================
 * GRIEVANCE / COMPLAINT MODULE
 *  - Employees & managers: submit complaints (optionally anonymous), track status
 *  - HR / Admin: Grievance Desk — view all, filter, assign, respond, resolve
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('employee');

// Create table if not exists (auto-migrate for safety)
try {
    db()->exec("CREATE TABLE IF NOT EXISTS grievances (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      employee_id INTEGER,
      category TEXT,
      subject TEXT,
      description TEXT,
      priority TEXT DEFAULT 'normal',
      status TEXT DEFAULT 'open',
      is_anonymous INTEGER DEFAULT 0,
      admin_response TEXT,
      responded_by INTEGER,
      responded_at TEXT,
      assigned_to INTEGER,
      resolution_note TEXT,
      updated_at TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$categories = ['Harassment', 'Discrimination', 'Workplace Safety', 'Policy Violation', 'Management Issue', 'Salary / Payroll', 'Attendance / Leave', 'Theft/Fraud', 'Unfair Treatment', 'Other'];
$priorities = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
$priorityColors = ['low' => 'gray', 'normal' => 'blue', 'high' => 'amber', 'urgent' => 'red'];
$statuses = ['open' => 'Open', 'under_review' => 'Under Review', 'resolved' => 'Resolved', 'rejected' => 'Rejected / Invalid'];
$statusColors = ['open' => 'red', 'under_review' => 'amber', 'resolved' => 'green', 'rejected' => 'gray'];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['g_action'] ?? '';

    if ($action === 'submit') {
        // Employee (or HR on behalf of an employee) submits complaint
        $isAnon = isset($_POST['is_anonymous']) ? 1 : 0;
        $onBehalf = is_hr() && !empty($_POST['on_behalf_employee_id']) ? (int)$_POST['on_behalf_employee_id'] : 0;
        $empIdForG = $onBehalf ?: current_employee_id();
        $priority = array_key_exists($_POST['priority'] ?? '', $priorities) ? $_POST['priority'] : 'normal';
        $gid = insert('grievances', [
            'employee_id' => $empIdForG,
            'category' => clean($_POST['category']),
            'subject' => clean($_POST['subject']),
            'description' => clean($_POST['description']) . ($onBehalf ? "\n\n[Logged by HR: " . current_name() . "]" : ''),
            'priority' => $priority,
            'is_anonymous' => $onBehalf ? 0 : $isAnon,
            'status' => 'open',
            'updated_at' => now(),
        ]);

        // Notify Admin & HR (without revealing identity if anonymous)
        $notifMsg = $isAnon ? "New anonymous grievance submitted" : "New grievance from " . ($onBehalf ? employee_name($onBehalf) : current_name());
        foreach (fetch_all("SELECT id FROM users WHERE role IN('admin','hr') AND status='active'") as $admin) {
            if ($admin['id'] == current_user_id()) continue;
            notify($admin['id'], '🔔 New Grievance #' . $gid, $notifMsg . ': ' . clean($_POST['subject']), 'modules/grievances/');
        }

        log_activity('Grievance Submitted', $isAnon ? 'Anonymous' : clean($_POST['subject']));
        set_flash('success', '✅ Grievance #' . $gid . ' has been submitted securely. HR will review it.');
        redirect(APP_URL . 'modules/grievances/');

    } elseif ($action === 'respond' && is_hr()) {
        // HR/Admin responds / changes status / assigns
        $gid = (int)$_POST['gid'];
        if (!fetch_one("SELECT id FROM grievances WHERE id=?", [$gid])) { set_flash('danger', 'Grievance not found.'); redirect(APP_URL . 'modules/grievances/'); }
        $newStatus = array_key_exists($_POST['new_status'] ?? '', $statuses) ? $_POST['new_status'] : 'under_review';
        $data = [
            'status' => $newStatus,
            'updated_at' => now(),
        ];
        if (trim($_POST['response'] ?? '') !== '') {
            $data['admin_response'] = clean($_POST['response']);
            $data['responded_by'] = current_user_id();
            $data['responded_at'] = now();
        }
        if (isset($_POST['assigned_to'])) $data['assigned_to'] = (int)$_POST['assigned_to'] ?: null;
        if (isset($_POST['resolution_note'])) $data['resolution_note'] = clean($_POST['resolution_note']);
        if (isset($_POST['priority']) && array_key_exists($_POST['priority'], $priorities)) $data['priority'] = $_POST['priority'];
        update('grievances', $data, 'id = ?', [$gid]);

        // Notify the employee (via their user account)
        $g = fetch_one("SELECT employee_id, subject FROM grievances WHERE id=?", [$gid]);
        if ($g && $g['employee_id']) {
            notify_employee($g['employee_id'], 'Grievance Update', "Your grievance '{$g['subject']}' is now " . $statuses[$newStatus] . '.', 'modules/grievances/');
        }
        if (!empty($data['assigned_to'])) {
            notify($data['assigned_to'], 'Grievance Assigned', "Grievance #$gid has been assigned to you.", 'modules/grievances/');
        }
        log_activity('Grievance ' . ucfirst(str_replace('_', ' ', $newStatus)), "#$gid");
        set_flash('success', 'Grievance #' . $gid . ' updated → ' . $statuses[$newStatus] . '.');
        redirect(APP_URL . 'modules/grievances/?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'priority', 'q']))));

    } elseif ($action === 'withdraw') {
        // Employee withdraws own open complaint
        $gid = (int)$_POST['gid'];
        $g = fetch_one("SELECT * FROM grievances WHERE id=? AND employee_id=? AND status='open'", [$gid, current_employee_id()]);
        if ($g) {
            update('grievances', ['status' => 'rejected', 'resolution_note' => 'Withdrawn by employee', 'updated_at' => now()], 'id=?', [$gid]);
            set_flash('success', 'Grievance withdrawn.');
        }
        redirect(APP_URL . 'modules/grievances/');
    }
}

// Fetch data
$myGrievances = current_employee_id() ? fetch_all("SELECT * FROM grievances WHERE employee_id=? ORDER BY id DESC", [current_employee_id()]) : [];
$grievances = []; $stats = []; $hrUsers = [];
$fStatus = clean($_GET['status'] ?? ''); $fPriority = clean($_GET['priority'] ?? ''); $q = clean($_GET['q'] ?? '');
$tab = clean($_GET['tab'] ?? (is_hr() ? 'desk' : 'mine'));
if (is_hr()) {
    $where = " WHERE 1=1"; $params = [];
    if ($fStatus === 'active') { $where .= " AND g.status IN ('open','under_review')"; }
    elseif ($fStatus) { $where .= " AND g.status=?"; $params[] = $fStatus; }
    if ($fPriority) { $where .= " AND g.priority=?"; $params[] = $fPriority; }
    if ($q) { $where .= " AND (g.subject LIKE ? OR g.description LIKE ? OR g.category LIKE ? OR (g.is_anonymous=0 AND e.full_name LIKE ?))"; $like = "%$q%"; array_push($params, $like, $like, $like, $like); }
    $grievances = fetch_all("SELECT g.*, e.full_name, e.employee_code, d.name dept, u.username assigned_name
        FROM grievances g LEFT JOIN employees e ON e.id=g.employee_id LEFT JOIN departments d ON d.id=e.department_id
        LEFT JOIN users u ON u.id=g.assigned_to
        $where ORDER BY CASE g.status WHEN 'open' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END,
                        CASE g.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END, g.id DESC", $params);
    $stats = fetch_one("SELECT SUM(status='open') open_c, SUM(status='under_review') review_c, SUM(status='resolved') resolved_c, SUM(status='rejected') rejected_c,
                        SUM(status IN('open','under_review') AND priority IN('high','urgent')) urgent_c, COUNT(*) total_c FROM grievances") ?: [];
    $hrUsers = fetch_all("SELECT u.id, u.username, e.full_name FROM users u LEFT JOIN employees e ON e.id=u.employee_id WHERE u.role IN('hr','admin') AND u.status='active' ORDER BY u.role, u.username");
    $allEmployees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");
}

auth_header(is_hr() ? 'Grievance Desk' : 'Grievances & Complaints');
?>

<div class="page-head">
  <div><h1><i class="fa-solid fa-shield-halved"></i> <?= is_hr() ? 'Grievance Desk' : 'Grievances &amp; Complaints' ?></h1>
    <div class="sub"><?= is_hr() ? 'Review, assign and resolve employee complaints' : 'Submit a confidential complaint to HR and track its progress' ?></div></div>
  <div class="flex gap">
    <?php if (is_hr()): ?>
      <a href="?tab=<?= $tab === 'mine' ? 'desk' : 'mine' ?>" class="btn btn-light"><i class="fa-solid fa-<?= $tab === 'mine' ? 'table-list' : 'user' ?>"></i> <?= $tab === 'mine' ? 'Back to Desk' : 'My Own Complaints' ?></a>
    <?php endif; ?>
    <button class="btn btn-primary" onclick="openModal('gModal')"><i class="fa-solid fa-plus"></i> <?= is_hr() ? 'Log Grievance' : 'Submit Grievance' ?></button>
  </div>
</div>

<?php if (!is_hr() || $tab === 'mine'): ?>
<!-- ======================= Employee View ======================= -->
<div class="alert alert-info">
  <i class="fa-solid fa-shield-halved"></i>
  <strong>Confidential &amp; Secure:</strong> Your identity is protected. You can submit a grievance anonymously. Only HR and Admin can see your complaint.
</div>

<?php if (!$myGrievances): ?>
<div class="card card-pad empty">
  <i class="fa-solid fa-inbox"></i>
  <p>You haven't submitted any grievances yet.</p>
</div>
<?php else: ?>
<div class="card card-pad">
  <h3 class="section-title" style="margin-bottom:14px">My Submitted Grievances</h3>
  <?php foreach ($myGrievances as $g): ?>
  <div class="card card-pad" style="margin-bottom:12px;border-left:4px solid var(--<?= $priorityColors[$g['priority']] ?? 'blue' ?>)">
    <div class="flex between center wrap gap">
      <div>
        <span class="muted small">#<?= $g['id'] ?></span>
        <span class="badge badge-<?= $priorityColors[$g['priority']] ?? 'blue' ?>"><?= ucfirst($g['priority']) ?></span>
        <span class="badge badge-<?= $statusColors[$g['status']] ?? 'gray' ?>"><?= $statuses[$g['status']] ?? ucfirst($g['status']) ?></span>
        <?php if ($g['is_anonymous']): ?><span class="badge badge-gray"><i class="fa-solid fa-user-secret"></i> Anonymous</span><?php endif; ?>
        <span class="muted small"><?= format_datetime($g['created_at']) ?></span>
      </div>
      <?php if ($g['status'] === 'open'): ?>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="g_action" value="withdraw"><input type="hidden" name="gid" value="<?= $g['id'] ?>"><button class="btn btn-sm btn-light" data-confirm="Withdraw this grievance?"><i class="fa-solid fa-rotate-left"></i> Withdraw</button></form>
      <?php endif; ?>
    </div>
    <h3 style="margin:8px 0 4px;font-size:16px"><?= e($g['subject']) ?></h3>
    <div class="muted small" style="margin-bottom:6px"><i class="fa-solid fa-tag"></i> <?= e($g['category']) ?></div>
    <p style="font-size:13px;line-height:1.6"><?= nl2br(e($g['description'])) ?></p>

    <?php if ($g['admin_response']): ?>
    <div style="background:#f0fdf4;border-left:3px solid #10b981;padding:12px;border-radius:8px;margin-top:10px">
      <div class="bold small" style="color:#10b981;margin-bottom:4px"><i class="fa-solid fa-reply"></i> HR Response <span class="muted" style="font-weight:400">· <?= $g['responded_at'] ? format_datetime($g['responded_at']) : '' ?></span></div>
      <p class="small" style="margin:0"><?= nl2br(e($g['admin_response'])) ?></p>
    </div>
    <?php elseif ($g['status'] === 'under_review'): ?>
    <div class="muted small" style="margin-top:8px"><i class="fa-solid fa-hourglass-half"></i> HR is reviewing your complaint.</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- ======================= HR / Admin Desk ======================= -->
<div class="grid cols-4" style="margin-bottom:18px">
  <a href="?status=open" class="stat" style="color:inherit"><div class="ico bg-red"><i class="fa-solid fa-envelope-open"></i></div><div><div class="num"><?= (int)($stats['open_c'] ?? 0) ?></div><div class="lbl">Open (new)</div></div></a>
  <a href="?status=under_review" class="stat" style="color:inherit"><div class="ico bg-amber"><i class="fa-solid fa-magnifying-glass"></i></div><div><div class="num"><?= (int)($stats['review_c'] ?? 0) ?></div><div class="lbl">Under Review</div></div></a>
  <a href="?status=active&priority=urgent" class="stat" style="color:inherit"><div class="ico bg-purple"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num"><?= (int)($stats['urgent_c'] ?? 0) ?></div><div class="lbl">High / Urgent pending</div></div></a>
  <a href="?status=resolved" class="stat" style="color:inherit"><div class="ico bg-green"><i class="fa-solid fa-circle-check"></i></div><div><div class="num"><?= (int)($stats['resolved_c'] ?? 0) ?></div><div class="lbl">Resolved · <?= (int)($stats['total_c'] ?? 0) ?> total</div></div></a>
</div>

<div class="card card-pad" style="margin-bottom:18px">
  <form method="get" class="flex center gap wrap">
    <input type="hidden" name="tab" value="desk">
    <div><label style="font-size:11px">Status</label>
      <select name="status" class="form-select" style="width:auto">
        <option value="">All</option>
        <option value="active" <?= $fStatus==='active'?'selected':'' ?>>Active (Open + Under Review)</option>
        <?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>" <?= $fStatus===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label style="font-size:11px">Priority</label>
      <select name="priority" class="form-select" style="width:auto">
        <option value="">All</option>
        <?php foreach ($priorities as $k => $v): ?><option value="<?= $k ?>" <?= $fPriority===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:200px"><label style="font-size:11px">Search</label><input type="text" name="q" class="form-control" value="<?= e($q) ?>" placeholder="Subject, category, employee..."></div>
    <div style="align-self:flex-end;display:flex;gap:8px"><button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button><a href="?tab=desk" class="btn btn-light">Reset</a></div>
  </form>
</div>

<?php if (!$grievances): ?>
<div class="card card-pad empty">
  <i class="fa-solid fa-check-circle"></i>
  <p>No grievances match this view.</p>
</div>
<?php else: ?>
<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>#</th><th>Date</th><th>From</th><th>Category</th><th>Subject</th><th>Priority</th><th>Status</th><th>Assigned</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($grievances as $g): $age = floor((time() - strtotime($g['created_at'] . ' UTC') + 5*3600) / 86400); ?>
        <tr <?= in_array($g['status'], ['open','under_review']) && in_array($g['priority'], ['urgent']) ? 'style="background:#fff5f5"' : '' ?>>
          <td class="small muted">#<?= $g['id'] ?></td>
          <td class="small muted" nowrap><?= format_date($g['created_at']) ?><div class="small"><?= $age > 0 ? $age . 'd ago' : 'today' ?></div></td>
          <td><?= $g['is_anonymous'] ? '<span class="badge badge-gray"><i class="fa-solid fa-user-secret"></i> Anonymous</span>' : '<strong>'.e($g['full_name'] ?: '—').'</strong><div class="muted small">'.e($g['employee_code']).($g['dept'] ? ' · '.e($g['dept']) : '').'</div>' ?></td>
          <td class="small"><?= e($g['category']) ?></td>
          <td class="small" style="max-width:260px"><div class="bold"><?= e($g['subject']) ?></div><div class="muted" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px"><?= e(mb_substr($g['description'], 0, 90)) ?></div></td>
          <td><span class="badge badge-<?= $priorityColors[$g['priority']] ?? 'blue' ?>"><?= ucfirst($g['priority']) ?></span></td>
          <td><span class="badge badge-<?= $statusColors[$g['status']] ?? 'gray' ?>"><?= $statuses[$g['status']] ?? ucfirst($g['status']) ?></span><?= $g['admin_response'] ? '<div class="small muted"><i class="fa-solid fa-reply"></i> replied</div>' : '' ?></td>
          <td class="small"><?= $g['assigned_name'] ? e($g['assigned_name']) : '<span class="muted">—</span>' ?></td>
          <td nowrap>
            <button class="btn btn-sm btn-primary" onclick='openRespond(<?= json_encode($g, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)' title="Review / Respond"><i class="fa-solid fa-reply"></i></button>
            <?php if ($g['status'] === 'open'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="g_action" value="respond"><input type="hidden" name="gid" value="<?= $g['id'] ?>"><input type="hidden" name="new_status" value="under_review"><input type="hidden" name="assigned_to" value="<?= current_user_id() ?>"><button class="btn btn-sm btn-light" title="Take up (mark Under Review & assign to me)"><i class="fa-solid fa-hand"></i></button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>


<!-- Submit Grievance Modal -->
<div class="modal-bg" id="gModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-shield-halved"></i> <?= is_hr() ? 'Log Grievance' : 'Submit Grievance' ?></h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('gModal')"></i></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="g_action" value="submit">
        <?php if (is_hr()): ?>
        <div style="margin-bottom:12px"><label>On behalf of employee (optional — for complaints received verbally / by email)</label>
          <select name="on_behalf_employee_id" class="form-select">
            <option value="">— Myself —</option>
            <?php foreach ($allEmployees as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'].' — '.$emp['full_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <div class="alert alert-warning" style="font-size:12px"><i class="fa-solid fa-info-circle"></i> Your complaint goes directly to HR/Admin. You can choose to stay anonymous.</div>
        <?php endif; ?>

        <div class="grid cols-2" style="margin-bottom:12px">
          <div><label>Category</label>
            <select name="category" class="form-select" required>
              <?php foreach($categories as $cat): ?><option value="<?= e($cat) ?>"><?= e($cat) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><label>Priority</label>
            <select name="priority" class="form-select">
              <?php foreach($priorities as $val => $label): ?><option value="<?= $val ?>" <?= $val==='normal'?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="margin-bottom:12px"><label>Subject</label><input type="text" name="subject" class="form-control" placeholder="Brief title of your complaint" required></div>

        <div style="margin-bottom:12px"><label>Description</label><textarea name="description" class="form-control" rows="4" placeholder="Explain the issue in detail (what, when, who was involved)..." required></textarea></div>

        <?php if (!is_hr()): ?>
        <div style="margin-bottom:12px">
          <label class="flex center gap" style="gap:8px;cursor:pointer;text-transform:none;font-weight:400">
            <input type="checkbox" name="is_anonymous" style="width:20px;height:20px">
            <span><i class="fa-solid fa-user-secret"></i> Submit Anonymously (HR won't see my name)</span>
          </label>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-light" onclick="closeModal('gModal')">Cancel</button>
        <button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit</button>
      </div>
    </form>
  </div>
</div>

<!-- Respond Modal (HR only) -->
<?php if (is_hr()): ?>
<div class="modal-bg" id="respondModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-head"><h3><i class="fa-solid fa-reply"></i> Review Grievance <span id="r_no" class="muted"></span></h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('respondModal')"></i></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="g_action" value="respond">
        <input type="hidden" name="gid" id="r_id">

        <div class="grid cols-2" style="gap:6px 16px;font-size:13px">
          <div>From: <strong id="r_name"></strong></div>
          <div>Submitted: <strong id="r_date"></strong></div>
          <div>Category: <strong id="r_cat"></strong></div>
          <div>Current status: <strong id="r_status"></strong></div>
        </div>
        <p style="margin:10px 0 4px">Subject: <strong id="r_subj"></strong></p>
        <div style="background:#f8f9fa;padding:10px;border-radius:8px;margin:6px 0 12px;max-height:160px;overflow:auto">
          <p class="small" id="r_desc" style="margin:0;white-space:pre-wrap"></p>
        </div>
        <div id="r_prev_wrap" style="display:none;background:#f0fdf4;border-left:3px solid #10b981;padding:10px;border-radius:8px;margin-bottom:12px">
          <div class="bold small" style="color:#10b981">Previous response</div>
          <p class="small" id="r_prev" style="margin:4px 0 0;white-space:pre-wrap"></p>
        </div>

        <div class="grid cols-2" style="margin-bottom:12px">
          <div><label>Update Status</label>
            <select name="new_status" id="r_new_status" class="form-select">
              <?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div><label>Priority</label>
            <select name="priority" id="r_priority" class="form-select">
              <?php foreach ($priorities as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select>
          </div>
          <div style="grid-column:span 2"><label>Assign To (HR / Admin)</label>
            <select name="assigned_to" id="r_assigned" class="form-select">
              <option value="0">— Unassigned —</option>
              <?php foreach ($hrUsers as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['full_name'] ?: $u['username']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>

        <label>Response to Employee <span class="muted" style="text-transform:none;font-weight:400">(they will see this)</span></label>
        <textarea name="response" class="form-control" rows="3" placeholder="Type your response or action taken... (leave blank to only change status/assignment)"></textarea>

        <label style="margin-top:10px">Internal Note <span class="muted" style="text-transform:none;font-weight:400">(HR only — investigation / resolution details)</span></label>
        <textarea name="resolution_note" id="r_note" class="form-control" rows="2" placeholder="Internal notes, meetings held, evidence..."></textarea>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-light" onclick="closeModal('respondModal')">Cancel</button>
        <button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Save &amp; Notify</button>
      </div>
    </form>
  </div>
</div>
<script>
var G_STATUS = <?= json_encode($statuses) ?>;
function openRespond(g) {
  document.getElementById('r_id').value = g.id;
  document.getElementById('r_no').textContent = '#' + g.id;
  document.getElementById('r_name').textContent = g.is_anonymous == 1 ? 'Anonymous' : ((g.full_name || '—') + (g.employee_code ? ' (' + g.employee_code + ')' : ''));
  document.getElementById('r_date').textContent = g.created_at || '';
  document.getElementById('r_cat').textContent = g.category;
  document.getElementById('r_status').textContent = G_STATUS[g.status] || g.status;
  document.getElementById('r_subj').textContent = g.subject;
  document.getElementById('r_desc').textContent = g.description;
  document.getElementById('r_prev_wrap').style.display = g.admin_response ? '' : 'none';
  document.getElementById('r_prev').textContent = g.admin_response || '';
  document.getElementById('r_new_status').value = g.status === 'open' ? 'under_review' : g.status;
  document.getElementById('r_priority').value = g.priority || 'normal';
  document.getElementById('r_assigned').value = g.assigned_to || 0;
  document.getElementById('r_note').value = g.resolution_note || '';
  openModal('respondModal');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
