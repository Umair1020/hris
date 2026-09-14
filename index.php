<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - LOGIN PAGE
 * ============================================================================
 */
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(APP_URL . 'dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $result = attempt_login($username, $password);
    if ($result['ok']) {
        if (!empty($result['must_change'])) {
            redirect(APP_URL . 'modules/profile/change-password.php');
        }
        redirect(APP_URL . 'dashboard.php');
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= APP_NAME ?> Login · <?= APP_COMPANY ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= asset('img/favicon.svg') ?>">
  <link rel="apple-touch-icon" href="<?= asset('img/icon-192.png') ?>">
  <link rel="manifest" href="<?= APP_URL ?>manifest.json">
  <meta name="theme-color" content="#7F3E98">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Spotcomm HRIS">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&family=Mulish:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body>
  <div class="login-wrap">
    <div class="login-left">
      <img src="<?= asset('img/logo.png') ?>" alt="Spotcomm Global" style="height:40px;width:auto;max-width:200px;margin-bottom:28px">
      <div class="tag"><?= APP_TAGLINE ?></div>
      <h1>Welcome to<br>Spotcomm HRIS</h1>
      <p>Your unified human resource portal — attendance, payroll, leaves, performance, recruitment and more, all in one secure place.</p>
      <ul class="login-features">
        <li><span class="fi"><i class="fa-solid fa-clock"></i></span> Smart Time &amp; Attendance tracking</li>
        <li><span class="fi"><i class="fa-solid fa-sack-dollar"></i></span> Integrated Payroll &amp; payslips</li>
        <li><span class="fi"><i class="fa-solid fa-calendar-days"></i></span> Self-service leave &amp; approvals</li>
        <li><span class="fi"><i class="fa-solid fa-users-gear"></i></span> Applicant Tracking (ATS)</li>
        <li><span class="fi"><i class="fa-solid fa-chart-line"></i></span> Performance &amp; KPI reviews</li>
      </ul>
    </div>
    <div class="login-right">
      <div class="login-card-logo">
        <img src="<?= asset('img/logo.png') ?>" alt="Spotcomm Global" style="height:32px;width:auto;max-width:180px">
      </div>
      <h2 style="font-size:22px;margin:0 0 4px">Sign in to your account</h2>
      <p class="muted small" style="margin:0 0 24px">Please enter your credentials to continue</p>

      <?php if ($error): ?>
        <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div style="margin-bottom:16px">
          <label>Username</label>
          <div class="input-icon">
            <i class="fa-solid fa-user"></i>
            <input type="text" name="username" class="form-control" placeholder="e.g. admin" required autofocus>
          </div>
        </div>
        <div style="margin-bottom:8px">
          <label>Password</label>
          <div class="input-icon">
            <i class="fa-solid fa-lock"></i>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required>
          </div>
        </div>
        <div style="margin:18px 0">
          <button type="submit" class="btn btn-primary btn-block"><i class="fa-solid fa-right-to-bracket"></i> Sign In</button>
        </div>
        <?= csrf_field() ?>
      </form>
      <div style="text-align:center;margin:14px 0;padding:14px;background:#f4f0f8;border-radius:10px">
        <a href="<?= url('modules/attendance/mark.php') ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;color:#7F3E98;font-weight:700;text-decoration:none;font-family:Oswald;font-size:15px">
          <i class="fa-solid fa-qrcode"></i> Mark Attendance (QR Scan)
        </a>
        <p class="muted" style="font-size:11px;margin:6px 0 0">No login needed — just scan your ID card QR</p>
      </div>
      <p class="muted small" style="text-align:center;margin:18px 0 0">Protected by Spotcomm Cipher Guard · Secure HR access only</p>
    </div>
  </div>
</body>
</html>
