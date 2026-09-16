<?php
/**
 * ============================================================================
 * SYSTEM SETTINGS — WhatsApp + Email/SMTP configuration (stored in DB)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('System Settings', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $section = $_POST['section'] ?? '';

    if ($section === 'whatsapp') {
        save_setting('wa_api_url', rtrim(clean($_POST['wa_api_url']), '/'));
        save_setting('wa_session', clean($_POST['wa_session']));
        save_setting('wa_token', clean($_POST['wa_token']));
        save_setting('wa_enabled', isset($_POST['wa_enabled']) ? '1' : '0');
        save_setting('wa_country', clean($_POST['wa_country']));
        save_setting('wa_broadcast_group', trim(clean($_POST['wa_broadcast_group'] ?? '')));
        log_activity('Settings Updated', 'WhatsApp settings');
        set_flash('success', '✅ WhatsApp settings saved!');

    } elseif ($section === 'fetch_groups') {
        // Best-effort: try to auto-detect WhatsApp groups from the linked phone
        $groups = fetch_whatsapp_groups();
        if ($groups) {
            $_SESSION['wa_group_list'] = $groups;
            set_flash('success', '✅ Found ' . count($groups) . ' group(s) on your WhatsApp. Pick the broadcast group below.');
        } else {
            set_flash('danger', '⚠️ Could not auto-fetch groups from the API. Please type the group ID manually (it looks like <code>120363xxxxxxxx@g.us</code>). Tip: send any message inside the group first so the API can see it.');
        }

    } elseif ($section === 'test_group') {
        $r = broadcast_to_group("🧪 *Broadcast Group Test*\n\nThis is a test from Spotcomm Global HRIS.\n\nIf you see this in the group, broadcasting works perfectly! ✅\n\n_" . date('Y-m-d H:i:s') . "_");
        if ($r['ok']) set_flash('success', '✅ Test message sent to the broadcast group! Check the group on your phone.');
        else set_flash('danger', '❌ Group test failed: ' . $r['error']);

    } elseif ($section === 'resolve_invite') {
        // Resolve a group JID from a WhatsApp invite link
        $link = $_POST['invite_link'] ?? '';
        $r = resolve_group_by_invite($link);
        if ($r['ok']) {
            save_setting('wa_broadcast_group', $r['jid']);
            set_flash('success', '✅ Group detected & saved! "' . e($r['name']) . '" → <code>' . e($r['jid']) . '</code>');
            log_activity('Broadcast Group Set', $r['name'] . ' (' . $r['jid'] . ') via invite link');
        } else {
            set_flash('danger', '⚠️ Could not resolve group: ' . $r['error']);
        }

    } elseif ($section === 'email') {
        save_setting('mail_from', clean($_POST['mail_from']));
        save_setting('mail_from_name', clean($_POST['mail_from_name']));
        save_setting('smtp_host', clean($_POST['smtp_host']));
        save_setting('smtp_port', clean($_POST['smtp_port']));
        save_setting('smtp_user', clean($_POST['smtp_user']));
        save_setting('smtp_pass', clean($_POST['smtp_pass']));
        save_setting('smtp_enabled', isset($_POST['smtp_enabled']) ? '1' : '0');
        log_activity('Settings Updated', 'Email/SMTP settings');
        set_flash('success', '✅ Email settings saved!');

    } elseif ($section === 'attendance') {
        save_setting('att_selfie_required', isset($_POST['att_selfie_required']) ? '1' : '0');
        save_setting('reminder_checkin_enabled', isset($_POST['reminder_checkin_enabled']) ? '1' : '0');
        save_setting('reminder_checkin_time', clean($_POST['reminder_checkin_time']));
        save_setting('reminder_checkout_enabled', isset($_POST['reminder_checkout_enabled']) ? '1' : '0');
        save_setting('reminder_checkout_time', clean($_POST['reminder_checkout_time']));
        save_setting('late_grace_minutes', clean($_POST['late_grace_minutes']));
        save_setting('late_threshold_count', clean($_POST['late_threshold_count']));
        save_setting('late_deduction_type', clean($_POST['late_deduction_type']));
        save_setting('required_work_hours', clean($_POST['required_work_hours']));
        save_setting('ot_to_compleave_hours', clean($_POST['ot_to_compleave_hours']));
        save_setting('ot_against_late', isset($_POST['ot_against_late']) ? '1' : '0');
        save_setting('regularize_monthly_limit', clean($_POST['regularize_monthly_limit']));
        save_setting('undertime_grace_minutes', (string)max(0, (int)($_POST['undertime_grace_minutes'] ?? 10)));
        save_setting('auto_absent_enabled', isset($_POST['auto_absent_enabled']) ? '1' : '0');
        save_setting('saturday_off', isset($_POST['saturday_off']) ? '1' : '0');
        log_activity('Settings Updated', 'Attendance policy');
        set_flash('success', '✅ Attendance & Reminder settings saved!');

    } elseif ($section === 'test_whatsapp') {
        $num = clean($_POST['test_number']);
        $msg = "🧪 Test from Spotcomm HRIS\n\nWhatsApp API is working!\n\n" . date('Y-m-d H:i:s');
        $r = send_whatsapp($num, $msg);
        if ($r['ok']) set_flash('success', "✅ Test sent to $num!");
        else set_flash('danger', "❌ WhatsApp test failed: " . $r['error']);

    } elseif ($section === 'letter_settings') {
        save_setting('letter_header', $_POST['letter_header'] ?? '');
        save_setting('letter_footer', $_POST['letter_footer'] ?? '');
        set_flash('success', '✅ Letterhead settings saved!');
        redirect(APP_URL . 'modules/letters/issue.php');

    } elseif ($section === 'test_email') {
        $email = clean($_POST['test_email']);
        $r = send_email($email, 'Test Email - Spotcomm HRIS', "This is a test email from Spotcomm Global HRIS.\n\nIf you received this, email is working!\n\n" . date('Y-m-d H:i:s'));
        if ($r['ok']) set_flash('success', "✅ Test email sent to $email!");
        else set_flash('danger', "❌ Email test failed: " . $r['error']);
    }

    redirect(APP_URL . 'modules/settings/index.php');
}

$waUrl = get_setting('wa_api_url', 'https://wa.spotcomm.pk');
$waSession = get_setting('wa_session', 'default');
$waToken = get_setting('wa_token', '');
$waEnabled = get_setting('wa_enabled', '1') === '1';
$waCountry = get_setting('wa_country', '92');
$waBroadcastGroup = get_setting('wa_broadcast_group', '');
$waGroupList = $_SESSION['wa_group_list'] ?? [];
unset($_SESSION['wa_group_list']);
$mailFrom = get_setting('mail_from', 'no-reply@spotcomm.pk');
$mailName = get_setting('mail_from_name', 'Spotcomm HRIS');
$smtpHost = get_setting('smtp_host', '');
$smtpPort = get_setting('smtp_port', '465');
$smtpUser = get_setting('smtp_user', '');
$smtpPass = get_setting('smtp_pass', '');
$smtpEnabled = get_setting('smtp_enabled', '0') === '1';
$attSelfie = get_setting('att_selfie_required', '1') === '1';
$lateGrace = get_setting('late_grace_minutes', '15');
$lateThreshold = get_setting('late_threshold_count', '3');
$lateDeduction = get_setting('late_deduction_type', 'half_day');
$reqHours = get_setting('required_work_hours', '9');
$otToComp = get_setting('ot_to_compleave_hours', '24');
$otAgainstLate = get_setting('ot_against_late', '1') === '1';
$regLimit = get_setting('regularize_monthly_limit', '1');
$utGrace = get_setting('undertime_grace_minutes', '10');
$autoAbsent = get_setting('auto_absent_enabled', '1') === '1';
$satOff = get_setting('saturday_off', '0') === '1';
$rCheckinEnabled = get_setting('reminder_checkin_enabled', '1') === '1';
$rCheckinTime = get_setting('reminder_checkin_time', '10:30');
$rCheckoutEnabled = get_setting('reminder_checkout_enabled', '1') === '1';
$rCheckoutTime = get_setting('reminder_checkout_time', '19:00');
$cronKey = 'spotcomm-cron-2026';
$cronUrl = APP_URL . 'cron/reminders.php?key=' . $cronKey;
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-gear"></i> System Settings</h1>
    <div class="sub">Configure WhatsApp API &amp; Email/SMTP — same as noc.spotcomm.pk</div></div>
</div>

<div class="grid cols-2">
  <!-- ===== WHATSAPP SETTINGS ===== -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-brands fa-whatsapp" style="color:#25D366"></i> WhatsApp API Configuration</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="whatsapp">
      <div class="flex between center" style="margin-bottom:14px">
        <label style="margin:0">Enable WhatsApp</label>
        <label class="flex center gap" style="gap:8px;cursor:pointer">
          <input type="checkbox" name="wa_enabled" <?= $waEnabled ? 'checked' : '' ?> style="width:20px;height:20px">
          <span class="small bold"><?= $waEnabled ? 'ENABLED' : 'DISABLED' ?></span>
        </label>
      </div>
      <div style="margin-bottom:12px">
        <label>API URL *</label>
        <input type="text" name="wa_api_url" class="form-control" value="<?= e($waUrl) ?>" placeholder="https://wa.spotcomm.pk" required>
        <div class="muted small">Your Baileys/Cloudflare Tunnel URL (without /api at the end)</div>
      </div>
      <div class="grid cols-2">
        <div style="margin-bottom:12px">
          <label>Session Name</label>
          <input type="text" name="wa_session" class="form-control" value="<?= e($waSession) ?>" placeholder="default">
          <div class="muted small">Baileys session (default: "default")</div>
        </div>
        <div style="margin-bottom:12px">
          <label>Country Code</label>
          <input type="text" name="wa_country" class="form-control" value="<?= e($waCountry) ?>" placeholder="92">
        </div>
      </div>
      <div style="margin-bottom:14px">
        <label>API Token (optional)</label>
        <input type="text" name="wa_token" class="form-control" value="<?= e($waToken) ?>" placeholder="Leave empty if not required">
      </div>

      <div class="divider"></div>
      <div style="background:linear-gradient(135deg,rgba(127,62,152,.08),rgba(242,98,35,.06));border:1px solid #ebe6f0;border-radius:12px;padding:14px;margin-bottom:14px">
        <h3 class="section-title" style="margin:0 0 4px"><i class="fa-solid fa-people-group" style="color:#25D366"></i> Broadcast Group (Anti-Ban) <span class="badge badge-green" style="font-size:10px">RECOMMENDED</span></h3>
        <p class="muted small" style="margin:0 0 10px">Create a WhatsApp group on your linked phone, add all employees, then set its ID here. Broadcasts will send <strong>1 message to the group</strong> (everyone receives it) instead of hundreds of separate messages — so WhatsApp won't ban your number.</p>
        <label>Broadcast Group ID</label>
        <input type="text" name="wa_broadcast_group" class="form-control" value="<?= e($waBroadcastGroup) ?>" placeholder="120363xxxxxxxx@g.us">
        <?php if ($waGroupList): ?>
          <div class="muted small" style="margin:8px 0">or pick from your WhatsApp groups:</div>
          <select class="form-select" onchange="if(this.value)document.querySelector('input[name=wa_broadcast_group]').value=this.value">
            <option value="">— Select a detected group —</option>
            <?php foreach ($waGroupList as $g): ?>
              <option value="<?= e($g['id']) ?>" <?= $waBroadcastGroup===$g['id']?'selected':'' ?>><?= e(mb_substr($g['name'],0,40)) ?> (<?= e(substr($g['id'],0,12)) ?>…)</option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <div class="muted small" style="margin:8px 0 4px"><i class="fa-solid fa-lightbulb" style="color:#F26223"></i> <strong>Easiest:</strong> on your phone open the group → Group Info → <em>Invite via link</em> → copy, then paste below &amp; click "Get from Link":</div>
        <input type="text" id="inviteField" class="form-control" placeholder="https://chat.whatsapp.com/AbCdEfGhIjKl" style="margin-bottom:8px">
      </div>
      <button class="btn btn-primary btn-block"><i class="fa-solid fa-save"></i> Save WhatsApp Settings</button>
    </form>
    <div class="flex gap" style="gap:8px;margin-top:10px">
      <form method="post" style="flex:1"><?= csrf_field() ?>
        <input type="hidden" name="section" value="resolve_invite">
        <input type="hidden" name="invite_link" id="inviteHidden">
        <button class="btn btn-purple btn-block" onclick="document.getElementById('inviteHidden').value=document.getElementById('inviteField').value" data-confirm="Resolve the group ID from this invite link?"><i class="fa-solid fa-link"></i> Get from Link</button>
      </form>
      <form method="post" style="flex:1"><?= csrf_field() ?><input type="hidden" name="section" value="fetch_groups">
        <button class="btn btn-outline btn-block"><i class="fa-solid fa-magnifying-glass"></i> Auto-Detect</button>
      </form>
      <form method="post" style="flex:1"><?= csrf_field() ?><input type="hidden" name="section" value="test_group">
        <button class="btn btn-block" style="background:#25D366;color:#fff" data-confirm="Send a test message to the broadcast group?"><i class="fa-solid fa-paper-plane"></i> Test Group</button>
      </form>
    </div>
    <div class="divider"></div>
    <h3 class="section-title" style="margin-bottom:8px"><i class="fa-solid fa-vial"></i> Test WhatsApp</h3>
    <form method="post" class="flex gap" style="gap:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="test_whatsapp">
      <input type="text" name="test_number" class="form-control" placeholder="923001234567" required>
      <button class="btn" style="background:#25D366;color:#fff"><i class="fa-brands fa-whatsapp"></i> Test</button>
    </form>
  </div>

  <!-- ===== EMAIL / SMTP SETTINGS ===== -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-envelope" style="color:#3b82f6"></i> Email / SMTP Configuration</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="email">
      <div style="margin-bottom:12px">
        <label>From Email</label>
        <input type="email" name="mail_from" class="form-control" value="<?= e($mailFrom) ?>" placeholder="no-reply@spotcomm.pk">
      </div>
      <div style="margin-bottom:12px">
        <label>From Name</label>
        <input type="text" name="mail_from_name" class="form-control" value="<?= e($mailName) ?>">
      </div>
      <div class="divider"></div>
      <div class="muted small bold" style="margin-bottom:8px"><i class="fa-solid fa-circle-info"></i> SMTP (optional — for reliable email delivery via spotcomm.pk)</div>
      <div class="grid cols-2">
        <div style="margin-bottom:12px">
          <label>SMTP Host</label>
          <input type="text" name="smtp_host" class="form-control" value="<?= e($smtpHost) ?>" placeholder="mail.spotcomm.pk">
        </div>
        <div style="margin-bottom:12px">
          <label>SMTP Port</label>
          <input type="text" name="smtp_port" class="form-control" value="<?= e($smtpPort) ?>" placeholder="465">
        </div>
        <div style="margin-bottom:12px">
          <label>SMTP Username</label>
          <input type="text" name="smtp_user" class="form-control" value="<?= e($smtpUser) ?>" placeholder="no-reply@spotcomm.pk">
        </div>
        <div style="margin-bottom:12px">
          <label>SMTP Password</label>
          <input type="password" name="smtp_pass" class="form-control" value="<?= e($smtpPass) ?>" placeholder="••••••">
        </div>
      </div>
      <div class="flex between center" style="margin-bottom:14px">
        <label style="margin:0">Use SMTP (instead of PHP mail)</label>
        <label class="flex center gap" style="gap:8px;cursor:pointer">
          <input type="checkbox" name="smtp_enabled" <?= $smtpEnabled ? 'checked' : '' ?> style="width:20px;height:20px">
          <span class="small bold"><?= $smtpEnabled ? 'ENABLED' : 'OFF' ?></span>
        </label>
      </div>
      <button class="btn btn-primary btn-block"><i class="fa-solid fa-save"></i> Save Email Settings</button>
    </form>
    <div class="divider"></div>
    <h3 class="section-title" style="margin-bottom:8px"><i class="fa-solid fa-vial"></i> Test Email</h3>
    <form method="post" class="flex gap" style="gap:8px">
      <?= csrf_field() ?>
      <input type="hidden" name="section" value="test_email">
      <input type="email" name="test_email" class="form-control" placeholder="your@email.com" required>
      <button class="btn btn-outline"><i class="fa-solid fa-paper-plane"></i> Test</button>
    </form>
  </div>
</div>

  <div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-shield-halved" style="color:#F26223"></i> Attendance Security (Anti-Fraud)</h3>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Selfie verification ensures the right person is marking attendance. When enabled, the front camera opens automatically and attendance <strong>cannot be marked without a photo</strong> — clock in/out is only saved together with the selfie. HR can view these photos in the attendance log.</div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="attendance">
    <div class="card card-pad" style="border:1px solid var(--border)">
      <div class="flex between center">
        <div>
          <h3 class="section-title" style="margin:0"><i class="fa-solid fa-camera"></i> Selfie Verification</h3>
          <p class="muted small" style="margin:6px 0 0">Employee must take a selfie photo when marking attendance — the front camera opens automatically and <strong>no attendance is marked without the photo</strong>. The photo is saved and visible to HR. Works from any location (office, customer sites, remote).</p>
        </div>
        <label class="flex center gap" style="gap:8px;cursor:pointer">
          <input type="checkbox" name="att_selfie_required" <?= $attSelfie ? 'checked' : '' ?> style="width:20px;height:20px">
          <span class="small bold"><?= $attSelfie ? 'ENABLED' : 'DISABLED' ?></span>
        </label>
      </div>
    </div>
    <button class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-save"></i> Save</button>
  </form>

  <div class="divider"></div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="attendance">
    <h3 class="section-title" style="margin:14px 0 10px"><i class="fa-solid fa-clock" style="color:#7F3E98"></i> Attendance Policy (Per HR Director)</h3>
    <div class="grid cols-2">
      <div><label>Late Grace Period (minutes)</label><input type="number" name="late_grace_minutes" class="form-control" value="<?= e($lateGrace) ?>"><div class="muted small">After this many minutes, clock-in is marked Late</div></div>
      <div><label>Late Deduction Threshold</label><input type="number" name="late_threshold_count" class="form-control" value="<?= e($lateThreshold) ?>"><div class="muted small">Salary deducts after this many lates per month (e.g. 3 = 4th late triggers deduction)</div></div>
      <div><label>Deduction Type</label><select name="late_deduction_type" class="form-select"><option value="half_day" <?= $lateDeduction==='half_day'?'selected':'' ?>>Half Day Salary</option><option value="full_day" <?= $lateDeduction==='full_day'?'selected':'' ?>>Full Day Salary</option><option value="amount" <?= $lateDeduction==='amount'?'selected':'' ?>>Fixed Amount</option></select></div>
      <div><label>Required Working Hours</label><input type="number" step="0.5" name="required_work_hours" class="form-control" value="<?= e($reqHours) ?>"><div class="muted small">Fallback only — each employee's required hours = their own shift length (Shift End − Shift Start)</div></div>
      <div><label>OT → Comp Leave Threshold (hours)</label><input type="number" step="0.5" name="ot_to_compleave_hours" class="form-control" value="<?= e($otToComp) ?>"><div class="muted small">e.g. 24 hours OT auto-converts to 1 Comp Leave</div></div>
      <div><label>Regularization Limit (per month)</label><input type="number" name="regularize_monthly_limit" class="form-control" value="<?= e($regLimit) ?>"><div class="muted small">How many times HR can regularize an employee's attendance per month</div></div>
      <div><label>Undertime Tolerance (minutes)</label><input type="number" name="undertime_grace_minutes" class="form-control" value="<?= e($utGrace) ?>"><div class="muted small">If the shortfall is within this many minutes, the day still counts as "Hours Completed" (no Undertime)</div></div>
    </div>
    <div class="flex between center" style="margin-top:12px;padding:12px;background:#f4f0f8;border-radius:10px">
      <div><strong>Auto-mark Absent</strong><div class="muted small">Past working days with no attendance are automatically marked Absent (weekly off days and public holidays are skipped)</div></div>
      <label class="flex center gap" style="gap:8px;cursor:pointer"><input type="checkbox" name="auto_absent_enabled" <?= $autoAbsent?'checked':'' ?> style="width:20px;height:20px"><span class="small bold"><?= $autoAbsent?'ON':'OFF' ?></span></label>
    </div>
    <div class="flex between center" style="margin-top:8px;padding:12px;background:#f4f0f8;border-radius:10px">
      <div><strong>Saturday is a company Off Day</strong><div class="muted small">Adds Saturday as an off day for everyone (in addition to each employee's own weekly off)</div></div>
      <label class="flex center gap" style="gap:8px;cursor:pointer"><input type="checkbox" name="saturday_off" <?= $satOff?'checked':'' ?> style="width:20px;height:20px"><span class="small bold"><?= $satOff?'ON':'OFF' ?></span></label>
    </div>
    <div class="flex between center" style="margin-top:12px;padding:12px;background:#f4f0f8;border-radius:10px">
      <div><strong>OT Compensates Late Arrival</strong><div class="muted small">If employee is late but completes required hours + overtime → mark as "Working Hours Completed"</div></div>
      <label class="flex center gap" style="gap:8px;cursor:pointer"><input type="checkbox" name="ot_against_late" <?= $otAgainstLate?'checked':'' ?> style="width:20px;height:20px"><span class="small bold"><?= $otAgainstLate?'ON':'OFF' ?></span></label>
    </div>
    <button class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-save"></i> Save Attendance Policy</button>
  </form>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-bell" style="color:#f59e0b"></i> Attendance Reminders</h3>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i> Automatically send WhatsApp + Email reminders to employees who forgot to check-in or check-out. Requires a cron job (instructions below).</div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="attendance">
    <div class="grid cols-2">
      <!-- Check-in reminder -->
      <div class="card card-pad" style="border:1px solid var(--border)">
        <div class="flex between center" style="margin-bottom:8px">
          <h3 class="section-title" style="margin:0"><i class="fa-solid fa-right-to-bracket"></i> Check-in Reminder</h3>
          <label class="flex center gap" style="gap:8px;cursor:pointer">
            <input type="checkbox" name="reminder_checkin_enabled" <?= $rCheckinEnabled ? 'checked' : '' ?> style="width:20px;height:20px">
            <span class="small bold"><?= $rCheckinEnabled ? 'ON' : 'OFF' ?></span>
          </label>
        </div>
        <p class="muted small">Send reminder if employee hasn't clocked in by this time</p>
        <label>Reminder Time</label>
        <input type="time" name="reminder_checkin_time" class="form-control" value="<?= e($rCheckinTime) ?>">
      </div>
      <!-- Check-out reminder -->
      <div class="card card-pad" style="border:1px solid var(--border)">
        <div class="flex between center" style="margin-bottom:8px">
          <h3 class="section-title" style="margin:0"><i class="fa-solid fa-right-from-bracket"></i> Check-out Reminder</h3>
          <label class="flex center gap" style="gap:8px;cursor:pointer">
            <input type="checkbox" name="reminder_checkout_enabled" <?= $rCheckoutEnabled ? 'checked' : '' ?> style="width:20px;height:20px">
            <span class="small bold"><?= $rCheckoutEnabled ? 'ON' : 'OFF' ?></span>
          </label>
        </div>
        <p class="muted small">Send reminder if employee clocked in but hasn't clocked out</p>
        <label>Reminder Time</label>
        <input type="time" name="reminder_checkout_time" class="form-control" value="<?= e($rCheckoutTime) ?>">
      </div>
    </div>
    <button class="btn btn-primary" style="margin-top:14px"><i class="fa-solid fa-save"></i> Save Reminder Settings</button>
    <a href="<?= e($cronUrl) ?>" target="_blank" class="btn btn-light" style="margin-top:8px"><i class="fa-solid fa-play"></i> Run Reminders Now (Test)</a>
  </form>

  <div class="divider"></div>
  <h3 class="section-title" style="margin-bottom:8px"><i class="fa-solid fa-clock-rotate-left"></i> Cron Job Setup (cPanel)</h3>
  <p class="muted small">For automatic reminders, add a cron job in cPanel → Cron Jobs that runs every 15 minutes:</p>
  <div style="background:#1e1e2d;color:#a7f3d0;padding:14px;border-radius:10px;font-family:monospace;font-size:12px;overflow-x:auto">
    */15 * * * * curl -s "<?= e($cronUrl) ?>" >/dev/null 2>&amp;1
  </div>
  <p class="muted small" style="margin-top:8px">Or if your hosting supports PHP CLI:</p>
  <div style="background:#1e1e2d;color:#a7f3d0;padding:14px;border-radius:10px;font-family:monospace;font-size:12px;overflow-x:auto">
    */15 * * * * php -q <?= e(APP_ROOT . '/cron/reminders.php') ?> >/dev/null 2>&amp;1
  </div>
  <p class="muted small" style="margin-top:8px">No cPanel cron? Use a free web cron service like <strong>cron-job.org</strong> with this URL:</p>
  <div style="background:#1e1e2d;color:#a7f3d0;padding:14px;border-radius:10px;font-family:monospace;font-size:11px;overflow-x:auto;word-break:break-all">
    <?= e($cronUrl) ?>
  </div>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:8px"><i class="fa-solid fa-circle-check" style="color:#10b981"></i> Current Status</h3>
  <div class="kv"><span class="k">WhatsApp</span><span class="v"><?= $waEnabled ? '<span class="badge badge-green">Enabled</span>' : '<span class="badge badge-red">Disabled</span>' ?> <?= e($waUrl) ?> (session: <?= e($waSession) ?>)</span></div>
  <div class="kv"><span class="k">Email From</span><span class="v"><?= e($mailFrom) ?></span></div>
  <div class="kv"><span class="k">SMTP</span><span class="v"><?= $smtpEnabled ? '<span class="badge badge-green">Enabled</span> ' . e($smtpHost) : '<span class="badge badge-gray">Using PHP mail()</span>' ?></span></div>
  <div class="divider"></div>
  <a href="<?= url('whatsapp-test.php') ?>" class="btn btn-outline"><i class="fa-solid fa-stethoscope"></i> Run WhatsApp Diagnostics</a>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
