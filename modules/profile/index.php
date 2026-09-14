<?php
/**
 * ============================================================================
 * MY PROFILE — self-service hub: personal info, resignation, password
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Profile');
$empId=ensure_self_employee();
$e=fetch_one("SELECT e.*, d.name dept_name, m.full_name manager_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN employees m ON m.id=e.manager_id WHERE e.id=?",[$empId]);
if(!$e){ set_flash('danger','Could not load your employee profile.'); redirect(APP_URL.'dashboard.php'); }
$year=date('Y');
$bal=fetch_all("SELECT lb.*,lt.name,lt.code FROM leave_balances lb JOIN leave_types lt ON lt.id=lb.leave_type_id WHERE lb.employee_id=? AND lb.year=?",[$empId,$year]);
$loanRow=fetch_one("SELECT SUM(remaining) r FROM loans WHERE employee_id=? AND status='active'",[$empId]);
$sep=fetch_one("SELECT * FROM separation_requests WHERE employee_id=? ORDER BY id DESC LIMIT 1",[$empId]);
?>
<div class="page-head"><div><h1>My Profile</h1><div class="sub">Manage your personal information &amp; account</div></div>
  <div class="flex gap">
    <a href="<?= url('modules/profile/edit.php') ?>" class="btn btn-outline"><i class="fa-solid fa-user-pen"></i> Edit Info</a>
    <a href="<?= url('modules/profile/change-password.php') ?>" class="btn btn-light"><i class="fa-solid fa-key"></i> Change Password</a>
  </div>
</div>

<div class="card card-pad" style="background:var(--grad);color:#fff;margin-bottom:18px">
  <div class="flex center gap">
    <div class="avatar lg" style="background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4)"><?= e(initials($e['full_name'])) ?></div>
    <div>
      <h2 style="margin:0;font-size:24px"><?= e($e['full_name']) ?></h2>
      <div style="opacity:.9"><?= e($e['designation']?:'—') ?> · <?= e($e['dept_name']?:'—') ?></div>
      <div class="small" style="opacity:.8;margin-top:4px"><i class="fa-solid fa-id-badge"></i> <?= e($e['employee_code']) ?> · <?= ucfirst(current_role()) ?></div>
    </div>
  </div>
</div>

<div class="grid cols-3">
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px">Personal Information</h3>
    <div class="kv"><span class="k">Email</span><span class="v small"><?= e($e['email']?:'—') ?></span></div>
    <div class="kv"><span class="k">Phone</span><span class="v"><?= e($e['phone']?:'—') ?></span></div>
    <div class="kv"><span class="k">WhatsApp</span><span class="v"><?php if(!empty($e['whatsapp'])): ?><i class="fa-brands fa-whatsapp" style="color:#25D366"></i> <?= e($e['whatsapp']) ?><?php else: ?>—<?php endif; ?></span></div>
    <div class="kv"><span class="k">CNIC</span><span class="v"><?= e($e['cnic']?:'—') ?></span></div>
    <div class="kv"><span class="k">Address</span><span class="v small"><?= e($e['address']?:'—') ?></span></div>
    <div class="kv"><span class="k">Emergency Contact</span><span class="v small"><?= e($e['emergency_name']?:'—') ?> <?= e($e['emergency_phone']?:'') ?></span></div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px">Employment</h3>
    <div class="kv"><span class="k">Joining Date</span><span class="v"><?= format_date($e['joining_date']) ?></span></div>
    <div class="kv"><span class="k">Type</span><span class="v"><?= e($e['employment_type']) ?></span></div>
    <div class="kv"><span class="k">Department</span><span class="v"><?= e($e['dept_name']?:'—') ?></span></div>
    <div class="kv"><span class="k">Reporting Manager</span><span class="v"><?= e($e['manager_name']?:'—') ?></span></div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px">Compensation Snapshot</h3>
    <?php foreach($bal as $b): ?>
      <div class="flex between center small" style="padding:5px 0"><span><?= e($b['name']) ?></span><span class="bold"><?= ($b['allocated']-$b['used']) ?>/<?= $b['allocated'] ?>d</span></div>
    <?php endforeach; if(!$bal) echo '<p class="muted small">No balances.</p>'; ?>
    <div class="divider"></div>
    <div class="flex between center"><span class="muted small">Outstanding Loan</span><span class="bold"><?= money($loanRow['r']??0) ?></span></div>
  </div>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-door-open"></i> Separation / Resignation</h3>
  <?php if($sep && $sep['status']!=='withdrawn'): ?>
    <p class="small">You have a <?= e($sep['separation_type']) ?> request submitted on <?= format_date($sep['created_at']) ?>.
    Status: <span class="badge badge-<?= $sep['status']==='completed'?'green':'amber' ?>"><?= ucfirst(str_replace('_',' ',$sep['status'])) ?></span>
    Last working day: <?= format_date($sep['last_working_day']) ?></p>
  <?php else: ?>
    <p class="muted small">If you wish to resign, you can submit a separation request below. HR will initiate the clearance process.</p>
    <button class="btn btn-outline" onclick="openModal('sepModal')"><i class="fa-solid fa-paper-plane"></i> Submit Resignation</button>
  <?php endif; ?>
</div>

<form method="post" action="<?= url('modules/separation/submit.php') ?>" enctype="multipart/form-data" class="modal-bg" id="sepModal"><div class="modal" style="max-width:600px">
  <div class="modal-head"><h3><i class="fa-solid fa-door-open"></i> Submit Resignation</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('sepModal')"></i></div>
  <div class="modal-body"><?= csrf_field() ?>
    <div class="grid cols-2">
      <div><label>Type</label><select name="separation_type" class="form-select"><option value="resignation">Resignation</option><option value="end_of_contract">End of Contract</option></select></div>
      <div><label>Notice Date</label><input type="date" name="notice_date" class="form-control" value="<?= today() ?>" required></div>
      <div><label>Last Working Day</label><input type="date" name="last_working_day" class="form-control" required></div>
    </div>
    <div style="margin-top:12px"><label>Reason for Leaving</label><textarea name="reason" class="form-control" rows="3" required></textarea></div>
    <div style="margin-top:12px"><label>Handover Notes</label><textarea name="handover_notes" class="form-control" rows="3" placeholder="Pending tasks, project status, contacts..."></textarea></div>
    <div style="margin-top:12px"><label>Upload Handover Documents (optional)</label><input type="file" name="handover[]" class="form-control" multiple accept=".pdf,.doc,.docx,.png,.jpg"></div>
  </div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('sepModal')">Cancel</button><button class="btn btn-danger" data-confirm="Submit this resignation? This cannot be easily undone."><i class="fa-solid fa-paper-plane"></i> Submit Resignation</button></div>
</div></form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
