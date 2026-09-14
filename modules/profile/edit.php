<?php
/**
 * ============================================================================
 * EDIT MY PROFILE — self can edit ONLY personal information
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login();

// Ensure the logged-in user has a linked employee record. Standalone admin/HR
// accounts (employee_id = NULL) previously could NOT save their profile because
// "WHERE id = NULL" matched 0 rows. This auto-links a profile if missing.
$empId = ensure_self_employee();
$e = $empId ? fetch_one("SELECT * FROM employees WHERE id=?", [$empId]) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  if (!$e) {
    set_flash('danger', 'Could not load your employee profile. Please contact HR to link your account.');
    redirect(APP_URL.'modules/profile/edit.php');
  }
  // employees can only edit personal info fields
  try {
    update('employees', [
      'phone'           => clean($_POST['phone'] ?? ''),
      'whatsapp'        => clean($_POST['whatsapp'] ?? ''),
      'address'         => clean($_POST['address'] ?? ''),
      'emergency_name'  => clean($_POST['emergency_name'] ?? ''),
      'emergency_phone' => clean($_POST['emergency_phone'] ?? ''),
      'marital_status'  => clean($_POST['marital_status'] ?? ''),
      'bank_name'       => clean($_POST['bank_name'] ?? ''),
      'bank_account'    => clean($_POST['bank_account'] ?? ''),
    ], 'id=?', [$empId]);
    log_activity('Profile Updated', 'Self-edit personal info');
    set_flash('success', 'Your profile information has been updated.');
    redirect(APP_URL.'modules/profile/index.php');
  } catch (Exception $ex) {
    set_flash('danger', 'Could not save profile: ' . $ex->getMessage());
    redirect(APP_URL.'modules/profile/edit.php');
  }
}

auth_header('Edit Profile');
$e = $empId ? fetch_one("SELECT * FROM employees WHERE id=?", [$empId]) : null;
if (!$e) {
  set_flash('danger', 'Could not load your employee profile.');
  redirect(APP_URL.'dashboard.php');
}
?>
<div class="page-head"><div><h1>Edit My Information</h1><div class="sub">You can update your personal contact &amp; banking details</div></div>
  <a href="<?= url('modules/profile/index.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a></div>

<div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> You can edit only your personal information. Employment &amp; salary details are managed by HR.</div>

<form method="post" class="card card-pad">
  <?= csrf_field() ?>
  <div class="grid cols-2">
    <div><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= e($e['phone']) ?>"></div>
    <div><label>WhatsApp Number</label><input type="text" name="whatsapp" class="form-control" value="<?= e($e['whatsapp']) ?>" placeholder="923001234567"></div>
    <div><label>Marital Status</label><select name="marital_status" class="form-select"><?php foreach(['','Single','Married','Divorced','Widowed'] as $m): ?><option <?= $e['marital_status']===$m?'selected':'' ?>><?= $m ?></option><?php endforeach; ?></select></div>
    <div style="grid-column:span 2"><label>Address</label><textarea name="address" class="form-control" rows="2"><?= e($e['address']) ?></textarea></div>
    <div><label>Emergency Contact Name</label><input type="text" name="emergency_name" class="form-control" value="<?= e($e['emergency_name']) ?>"></div>
    <div><label>Emergency Phone</label><input type="text" name="emergency_phone" class="form-control" value="<?= e($e['emergency_phone']) ?>"></div>
    <div><label>Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= e($e['bank_name']) ?>"></div>
    <div><label>Bank Account</label><input type="text" name="bank_account" class="form-control" value="<?= e($e['bank_account']) ?>"></div>
  </div>
  <div style="margin-top:16px"><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Changes</button>
    <a href="<?= url('modules/profile/index.php') ?>" class="btn btn-light">Cancel</a></div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
