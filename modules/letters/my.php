<?php
/**
 * ============================================================================
 * MY LETTERS — Employee views received letters + requests new letters
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Letters');
$empId = current_employee_id();
$action = $_GET['action'] ?? '';

// Handle letter request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['req_action'] ?? '') === 'request') {
    verify_csrf();
    $tplId = (int)$_POST['template_id'];
    $tpl = fetch_one("SELECT * FROM letter_templates WHERE id=? AND is_active=1", [$tplId]);
    if (!$tpl) {
        set_flash('danger', 'Template not found.');
        redirect(APP_URL . 'modules/letters/my.php');
    }
    $refNo = 'SCG/' . date('Y') . '/' . str_pad((int)fetch_one("SELECT COUNT(*) c FROM generated_letters")['c'] + 1, 5, '0', STR_PAD_LEFT);
    insert('generated_letters', [
        'employee_id' => $empId,
        'template_id' => $tplId,
        'subject' => $tpl['subject'] ?: $tpl['name'],
        'body' => $tpl['body'],
        'reference_no' => $refNo,
        'status' => 'requested',
        'requested_by' => current_user_id(),
    ]);
    foreach (fetch_all("SELECT id FROM users WHERE role IN('hr','admin')") as $hr) {
        notify($hr['id'], 'Letter Request', employee_name($empId) . ' requested ' . $tpl['name'], 'modules/letters/approvals.php');
    }
    log_activity('Letter Requested', $tpl['name']);
    set_flash('success', 'Letter request submitted! HR will review and generate it.');
    redirect(APP_URL . 'modules/letters/my.php');
}

$templates = fetch_all("SELECT * FROM letter_templates WHERE is_active=1 ORDER BY name");
$letters = fetch_all("SELECT gl.*, lt.name tpl_name FROM generated_letters gl LEFT JOIN letter_templates lt ON lt.id=gl.template_id WHERE gl.employee_id=? AND gl.status='generated' ORDER BY gl.id DESC", [$empId]);
$pendingRequests = fetch_all("SELECT gl.*, lt.name tpl_name FROM generated_letters gl LEFT JOIN letter_templates lt ON lt.id=gl.template_id WHERE gl.employee_id=? AND gl.status='requested' ORDER BY gl.id DESC", [$empId]);
?>

<?php if ($action === 'new'): ?>
<!-- REQUEST LETTER FORM -->
<div class="page-head">
  <div><h1>Request a Letter</h1><div class="sub">Select a letter type to request from HR</div></div>
  <a href="<?= url('modules/letters/my.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>
<form method="post" class="card card-pad">
  <?= csrf_field() ?>
  <input type="hidden" name="req_action" value="request">
  <label>Select Letter Type *</label>
  <select name="template_id" class="form-select" required style="max-width:400px;margin-bottom:16px">
    <option value="">— Choose Letter —</option>
    <?php foreach ($templates as $t): ?>
      <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
</form>

<?php else: ?>
<!-- MAIN VIEW -->
<div class="page-head">
  <div><h1>My Letters &amp; Certificates</h1>
    <div class="sub">Letters and certificates issued to you by HR</div></div>
  <a href="<?= url('modules/letters/my.php?action=new') ?>" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Request Letter</a>
</div>

<?php if ($pendingRequests): ?>
<div class="card card-pad" style="margin-bottom:18px">
  <h3 class="section-title" style="margin-bottom:10px"><i class="fa-solid fa-clock"></i> Pending Requests</h3>
  <?php foreach ($pendingRequests as $pr): ?>
    <div class="flex between center" style="padding:8px 0;border-bottom:1px solid var(--border)">
      <div class="bold small"><?= e($pr['tpl_name']) ?></div>
      <span class="badge badge-amber">Pending HR Approval</span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="grid cols-3">
  <?php if (!$letters): ?>
    <div class="card card-pad empty" style="grid-column:span 3"><i class="fa-solid fa-file-circle-exclamation"></i><p>No letters or certificates yet. Request a letter using the button above.</p></div>
  <?php else: foreach ($letters as $l): ?>
    <div class="card card-pad" style="text-align:center">
      <i class="fa-solid fa-file-lines" style="font-size:36px;color:#7F3E98"></i>
      <h3 style="margin:10px 0 4px;font-size:16px"><?= e($l['tpl_name'] ?: $l['subject']) ?></h3>
      <div class="muted small"><?= e($l['reference_no']) ?></div>
      <div class="muted small"><?= format_date($l['created_at']) ?></div>
      <div style="margin-top:10px">
        <a href="<?= url('modules/letters/view.php?id=' . $l['id']) ?>" class="btn btn-sm btn-primary btn-block"><i class="fa-solid fa-eye"></i> View &amp; Download</a>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
