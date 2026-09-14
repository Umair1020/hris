<?php
/**
 * ============================================================================
 * GRIEVANCE / WHISTLEBLOWER MODULE
 * Employees submit complaints (can be anonymous). Only Admin/HR can view.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';

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
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['g_action'] ?? '';

    if ($action === 'submit') {
        // Employee submits complaint
        $isAnon = isset($_POST['is_anonymous']) ? 1 : 0;
        insert('grievances', [
            'employee_id' => current_employee_id(),
            'category' => clean($_POST['category']),
            'subject' => clean($_POST['subject']),
            'description' => clean($_POST['description']),
            'priority' => clean($_POST['priority']),
            'is_anonymous' => $isAnon,
            'status' => 'open',
        ]);
        
        // Notify Admin & HR (without revealing identity if anonymous)
        $notifMsg = $isAnon ? "New anonymous grievance submitted" : "New grievance from " . current_name();
        foreach (fetch_all("SELECT id FROM users WHERE role IN('admin','hr')") as $admin) {
            notify($admin['id'], '🔔 New Grievance', $notifMsg, 'modules/grievances/');
        }
        
        log_activity('Grievance Submitted', $isAnon ? 'Anonymous' : clean($_POST['subject']));
        set_flash('success', '✅ Your grievance has been submitted securely. HR will review it.');
        redirect(APP_URL . 'modules/grievances/');

    } elseif ($action === 'respond' && is_hr()) {
        // HR/Admin responds
        $gid = (int)$_POST['gid'];
        update('grievances', [
            'admin_response' => clean($_POST['response']),
            'status' => clean($_POST['new_status']),
            'responded_by' => current_user_id(),
            'responded_at' => now(),
        ], 'id = ?', [$gid]);

        // Notify the employee
        $g = fetch_one("SELECT employee_id, subject FROM grievances WHERE id=?", [$gid]);
        if ($g) {
            notify($g['employee_id'], 'Grievance Update', "Your grievance '{$g['subject']}' has been updated.", 'modules/grievances/');
        }

        set_flash('success', 'Response submitted.');
        redirect(APP_URL . 'modules/grievances/');
    }
}

// Fetch data
if (is_hr()) {
    $grievances = fetch_all("SELECT g.*, e.full_name, e.employee_code FROM grievances g LEFT JOIN employees e ON e.id=g.employee_id ORDER BY g.status='open' DESC, g.id DESC");
    $myGrievances = [];
} else {
    $myGrievances = fetch_all("SELECT * FROM grievances WHERE employee_id=? ORDER BY id DESC", [current_employee_id()]);
    $grievances = [];
}

auth_header('Grievances & Whistleblower');

$categories = ['Harassment', 'Discrimination', 'Workplace Safety', 'Policy Violation', 'Management Issue', 'Theft/Fraud', 'Unfair Treatment', 'Other'];
$priorities = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];
$priorityColors = ['low' => 'gray', 'normal' => 'blue', 'high' => 'amber', 'urgent' => 'red'];
$statusColors = ['open' => 'red', 'under_review' => 'amber', 'resolved' => 'green', 'rejected' => 'gray'];
?>

<div class="page-head">
  <div><h1><i class="fa-solid fa-shield-halved"></i> Grievances &amp; Whistleblower</h1>
    <div class="sub"><?= is_hr() ? 'Review and respond to employee complaints' : 'Submit a confidential complaint to HR' ?></div></div>
  <?php if (!is_hr()): ?>
  <button class="btn btn-primary" onclick="openModal('gModal')"><i class="fa-solid fa-plus"></i> Submit Grievance</button>
  <?php endif; ?>
</div>

<?php if (!is_hr()): ?>
<!-- Employee View -->
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
        <span class="badge badge-<?= $priorityColors[$g['priority']] ?? 'blue' ?>"><?= ucfirst($g['priority']) ?></span>
        <span class="badge badge-<?= $statusColors[$g['status']] ?? 'gray' ?>"><?= ucfirst(str_replace('_',' ',$g['status'])) ?></span>
        <span class="muted small"><?= format_date($g['created_at']) ?></span>
      </div>
    </div>
    <h3 style="margin:8px 0 4px;font-size:16px"><?= e($g['subject']) ?></h3>
    <div class="muted small" style="margin-bottom:6px"><i class="fa-solid fa-tag"></i> <?= e($g['category']) ?></div>
    <p style="font-size:13px;line-height:1.6"><?= nl2br(e($g['description'])) ?></p>
    
    <?php if ($g['admin_response']): ?>
    <div style="background:#f0fdf4;border-left:3px solid #10b981;padding:12px;border-radius:8px;margin-top:10px">
      <div class="bold small" style="color:#10b981;margin-bottom:4px"><i class="fa-solid fa-reply"></i> HR Response:</div>
      <p class="small" style="margin:0"><?= nl2br(e($g['admin_response'])) ?></p>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<!-- HR/Admin View -->
<?php if (!$grievances): ?>
<div class="card card-pad empty">
  <i class="fa-solid fa-check-circle"></i>
  <p>No grievances submitted yet.</p>
</div>
<?php else: ?>
<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>From</th><th>Category</th><th>Subject</th><th>Priority</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($grievances as $g): ?>
        <tr>
          <td class="small muted"><?= format_date($g['created_at']) ?></td>
          <td><?= $g['is_anonymous'] ? '<span class="badge badge-gray"><i class="fa-solid fa-user-secret"></i> Anonymous</span>' : '<strong>'.e($g['full_name']).'</strong><div class="muted small">'.e($g['employee_code']).'</div>' ?></td>
          <td class="small"><?= e($g['category']) ?></td>
          <td class="small"><?= e($g['subject']) ?></td>
          <td><span class="badge badge-<?= $priorityColors[$g['priority']] ?? 'blue' ?>"><?= ucfirst($g['priority']) ?></span></td>
          <td><span class="badge badge-<?= $statusColors[$g['status']] ?? 'gray' ?>"><?= ucfirst(str_replace('_',' ',$g['status'])) ?></span></td>
          <td><button class="btn btn-sm btn-primary" onclick='openRespond(<?= json_encode($g) ?>, "<?= e($g['full_name'] ?: 'Anonymous') ?>")'><i class="fa-solid fa-reply"></i></button></td>
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
    <div class="modal-head"><h3><i class="fa-solid fa-shield-halved"></i> Submit Grievance</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('gModal')"></i></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="g_action" value="submit">
        <div class="alert alert-warning" style="font-size:12px"><i class="fa-solid fa-info-circle"></i> Your complaint goes directly to HR/Admin. You can choose to stay anonymous.</div>
        
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
        
        <div style="margin-bottom:12px"><label>Description</label><textarea name="description" class="form-control" rows="4" placeholder="Explain the issue in detail..." required></textarea></div>
        
        <div style="margin-bottom:12px">
          <label class="flex center gap" style="gap:8px;cursor:pointer;text-transform:none;font-weight:400">
            <input type="checkbox" name="is_anonymous" style="width:20px;height:20px">
            <span><i class="fa-solid fa-user-secret"></i> Submit Anonymously (HR won't see my name)</span>
          </label>
        </div>
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
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-reply"></i> Review Grievance</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('respondModal')"></i></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="g_action" value="respond">
        <input type="hidden" name="gid" id="r_id">
        
        <p>From: <strong id="r_name"></strong></p>
        <p>Category: <strong id="r_cat"></strong></p>
        <p>Subject: <strong id="r_subj"></strong></p>
        <div style="background:#f8f9fa;padding:10px;border-radius:8px;margin:10px 0">
          <p class="small" id="r_desc"></p>
        </div>
        
        <label>Your Response</label>
        <textarea name="response" class="form-control" rows="3" placeholder="Type your response or action taken..." required></textarea>
        
        <label style="margin-top:10px">Update Status</label>
        <select name="new_status" class="form-select">
          <option value="under_review">Under Review</option>
          <option value="resolved">Resolved</option>
          <option value="rejected">Rejected / Invalid</option>
        </select>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-light" onclick="closeModal('respondModal')">Cancel</button>
        <button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Send Response</button>
      </div>
    </form>
  </div>
</div>
<script>
function openRespond(g, name) {
  document.getElementById('r_id').value = g.id;
  document.getElementById('r_name').textContent = name;
  document.getElementById('r_cat').textContent = g.category;
  document.getElementById('r_subj').textContent = g.subject;
  document.getElementById('r_desc').textContent = g.description;
  openModal('respondModal');
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
