<?php
/**
 * ============================================================================
 * APPLY FOR JOB — candidate application form (public, no login).
 * Resume auto-scored against keywords. EMAIL confirmation only (no WhatsApp).
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
$jobId = (int)($_GET['job'] ?? 0);
$job = fetch_one("SELECT * FROM job_postings WHERE id=?", [$jobId]);
if (!$job) { set_flash('danger', 'Job not found.'); redirect(APP_URL . 'modules/ats/jobs.php'); }

$success = false;
$appliedName = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = clean($_POST['full_name']);
    $email = clean($_POST['email']);
    $phone = clean($_POST['phone']);
    $cover = clean($_POST['cover_letter']);
    $resumeFile = null;
    $resumeText = '';
    if (!empty($_FILES['resume']['name'])) {
        try {
            $resumeFile = handle_upload('resume', RESUME_DIR, ['pdf', 'doc', 'docx']);
        } catch (Exception $ex) {
            set_flash('danger', $ex->getMessage());
            redirect(APP_URL . 'modules/ats/apply.php?job=' . $jobId);
        }
        $path = RESUME_DIR . $resumeFile;
        if (strtolower(pathinfo($resumeFile, PATHINFO_EXTENSION)) === 'pdf') {
            $resumeText = read_pdf_text($path);
        }
    }
    // Score against keywords
    $keywords = array_filter(array_map('trim', explode(',', $job['keywords'])));
    $haystack = strtolower($resumeText . ' ' . $cover . ' ' . $name);
    $matched = [];
    foreach ($keywords as $kw) { if (strpos($haystack, $kw) !== false) $matched[] = $kw; }
    $score = $keywords ? round(count($matched) / count($keywords) * 100) : 0;

    insert('applicants', [
        'job_posting_id' => $jobId, 'full_name' => $name, 'email' => $email, 'phone' => $phone,
        'resume_path' => $resumeFile, 'resume_text' => $resumeText, 'cover_letter' => $cover,
        'status' => 'new', 'match_score' => $score, 'keywords_matched' => implode(', ', $matched),
        'source' => 'Portal',
    ]);
    log_activity('Application Submitted', "$name for " . $job['title'] . " (score $score)");

    // EMAIL confirmation to applicant ONLY (no WhatsApp for candidates)
    if ($email) {
        $emailBody = "Dear $name,\n\nThank you for applying for the position of \"{$job['title']}\" at Spotcomm Global.\n\nWe have received your application and our HR team will review it. If your profile matches our requirements, we will contact you for the next steps.\n\nPosition: {$job['title']}\nApplied on: " . date('d M Y, h:i A') . "\n\nBest regards,\nHR Department\nSpotcomm Global";
        send_email($email, 'Application Received - Spotcomm Global', $emailBody);
    }

    // Notify HR users via in-app notification
    foreach (fetch_all("SELECT id FROM users WHERE role IN('hr','admin')") as $hr) {
        notify($hr['id'], 'New Job Application', "$name applied for {$job['title']} (match: $score%)", 'modules/ats/applicants.php');
    }

    $success = true;
    $appliedName = $name;
}
require_once __DIR__ . '/../../includes/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apply: <?= e($job['title']) ?> · <?= APP_COMPANY ?></title>
<link rel="icon" type="image/png" href="<?= asset('img/favicon.png') ?>">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Mulish:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<style>
body{background:#f0eef5;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.apply-wrap{width:100%;max-width:520px;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(127,62,152,.2)}
.apply-head{background:#fff;padding:28px 32px 0;text-align:center}
.apply-head img{height:38px;width:auto;margin:0 auto 16px;display:block}
.apply-head h2{font-family:Oswald,sans-serif;font-size:20px;margin:0;color:#2A1B3D}
.apply-head .meta{color:#7F3E98;font-size:13px;margin-top:4px}
.apply-body{padding:24px 32px 32px}
.apply-form .form-control{margin-bottom:12px}
.apply-form label{font-size:12px}
.apply-btn{width:100%;padding:14px;background:linear-gradient(135deg,#7F3E98,#9B59B6);color:#fff;border:none;border-radius:10px;font-family:Oswald;font-size:16px;font-weight:500;cursor:pointer;transition:.2s}
.apply-btn:hover{filter:brightness(1.1)}
.success-box{text-align:center;padding:20px}
.success-box .icon{width:72px;height:72px;border-radius:50%;background:rgba(16,185,129,.12);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:34px;color:#10b981}
.success-box h2{font-family:Oswald;color:#10b981;margin:0 0 8px}
.jobs-link{text-align:center;padding:16px 0;color:#7F3E98}
.jobs-link a{color:#7F3E98;font-weight:600;text-decoration:none}
</style>
</head>
<body>
<div class="apply-wrap">
  <div class="apply-head">
    <img src="<?= asset('img/logo.png') ?>" alt="Spotcomm Global">
    <?php if ($success): ?>
      <h2>Application Submitted!</h2>
    <?php else: ?>
      <h2><?= e($job['title']) ?></h2>
      <div class="meta"><i class="fa-solid fa-location-dot"></i> <?= e($job['location'] ?: 'On-site') ?> · <?= e($job['employment_type']) ?></div>
    <?php endif; ?>
  </div>
  <div class="apply-body">
    <?php if ($success): ?>
      <div class="success-box">
        <div class="icon"><i class="fa-solid fa-check"></i></div>
        <h2>Thank you, <?= e($appliedName) ?>!</h2>
        <p class="muted" style="font-size:14px;line-height:1.6">Your application has been received. A confirmation email has been sent to you. Our HR team will review your application and reach out if shortlisted.</p>
      </div>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="apply-form">
        <?= csrf_field() ?>
        <label>Full Name</label>
        <input type="text" name="full_name" class="form-control" placeholder="Your full name" required>
        <div class="grid cols-2">
          <div><label>Email</label><input type="email" name="email" class="form-control" placeholder="you@email.com" required></div>
          <div><label>Phone</label><input type="text" name="phone" class="form-control" placeholder="03XX XXXXXXX"></div>
        </div>
        <label>Cover Letter</label>
        <textarea name="cover_letter" class="form-control" rows="3" placeholder="Tell us why you're a great fit..." style="margin-bottom:12px"></textarea>
        <label>Upload Resume (PDF/DOC)</label>
        <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx" required style="margin-bottom:18px">
        <button type="submit" class="apply-btn"><i class="fa-solid fa-paper-plane"></i> Submit Application</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="jobs-link">
    <a href="<?= url('modules/ats/jobs.php') ?>">← View All Openings</a>
  </div>
</div>
</body>
</html>
