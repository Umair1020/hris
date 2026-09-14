<?php
/**
 * ============================================================================
 * CHANGE PASSWORD — also used for forced first-login change
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Change Password');
$forced=!empty($_SESSION['user']['must_change']);

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $uid=current_user_id();
  $u=fetch_one("SELECT * FROM users WHERE id=?",[$uid]);
  if(!verify_password($_POST['current']??'',$u['password_hash'])){
    set_flash('danger','Your current password is incorrect.');
  } elseif(strlen($_POST['new'])<8){
    set_flash('danger','New password must be at least 8 characters.');
  } elseif($_POST['new']!==$_POST['confirm']){
    set_flash('danger','New passwords do not match.');
  } else {
    update('users',['password_hash'=>hash_password($_POST['new']),'must_change_password'=>0],'id=?',[$uid]);
    $_SESSION['user']['must_change']=false;
    log_activity('Password Changed','');
    set_flash('success','Password changed successfully.');
    redirect(APP_URL.'dashboard.php');
  }
}
?>
<div class="page-head"><div><h1><?= $forced?'Set Your Password':'Change Password' ?></h1><div class="sub"><?= $forced?'Please set a new password to continue':'Update your account password' ?></div></div></div>
<?php if($forced): ?><div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> You must change your temporary password before continuing.</div><?php endif; ?>

<form method="post" class="card card-pad" style="max-width:480px">
  <?= csrf_field() ?>
  <div style="margin-bottom:14px"><label>Current Password</label><input type="password" name="current" class="form-control" required></div>
  <div style="margin-bottom:14px"><label>New Password</label><input type="password" name="new" class="form-control" required minlength="8"><div class="muted small">Minimum 8 characters</div></div>
  <div style="margin-bottom:14px"><label>Confirm New Password</label><input type="password" name="confirm" class="form-control" required></div>
  <button class="btn btn-primary"><i class="fa-solid fa-key"></i> <?= $forced?'Set Password':'Update Password' ?></button>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
