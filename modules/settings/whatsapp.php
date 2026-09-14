<?php
/**
 * ============================================================================
 * COMMUNICATIONS — WhatsApp + Email broadcast
 * WhatsApp: sends ONE message to the configured broadcast group (anti-ban,
 * everyone receives it). Falls back to per-employee queue if no group is set.
 * Email: queued, sent by the background processor.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Broadcast & Notifications', 'hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'broadcast') {
        $message = clean($_POST['message'] ?? '');
        $prefix = clean($_POST['prefix'] ?? '📢');
        $channel = clean($_POST['channel'] ?? 'both');
        $waMode = clean($_POST['wa_mode'] ?? 'individual'); // individual (private) | group
        // Save the per-message delay (used by the Sender page)
        $delay = (int)($_POST['delay'] ?? 45);
        if ($delay < 10) $delay = 10;
        if ($delay > 180) $delay = 180;
        save_setting('broadcast_delay_seconds', (string)$delay);
        $subject = $prefix . ' Spotcomm Global Update';

        $fullMsg = $prefix . ' *Spotcomm Global Update*' . "\n\n" . $message . "\n\n_— HR Department, Spotcomm Global_";
        $emailMsg = $prefix . ' Spotcomm Global Update' . "\n\n" . $message . "\n\n— HR Department, Spotcomm Global";

        // Determine WhatsApp delivery
        $broadcastGroupId = trim(get_setting('wa_broadcast_group', ''));
        // If individual mode requested OR (group mode but no group set) → fall back to per-employee queue
        $useGroup = ($waMode === 'group' && $broadcastGroupId !== '');

        $waSent = false; $waErr = '';
        if ($channel === 'both' || $channel === 'whatsapp') {
            if ($useGroup) {
                // SAFE: 1 message to the broadcast group — everyone receives it
                $r = broadcast_to_group($fullMsg);
                $waSent = $r['ok'];
                $waErr = $r['error'] ?? '';
                log_activity('Broadcast (Group)', '1 message → broadcast group');
            } else {
                // Individual queue (old behaviour, slower)
                $q = broadcast_whatsapp_queue($fullMsg);
            }
        }

        // Build success/failure message
        $emailNote = '';
        if ($channel === 'both' || $channel === 'email') {
            // Queue emails (sent by background processor — avoids timeout for 500 staff)
            $eq = broadcast_emails_queue($emailMsg, $subject);
            $emailNote = " 📧 {$eq} email(s) queued (sent in the background).";
            log_activity('Email Broadcast Queued', "{$eq} queued");
        }

        if ($channel === 'whatsapp' || $channel === 'both') {
            if ($useGroup) {
                if ($waSent) {
                    set_flash('success', "✅ <strong>Broadcast sent to the group!</strong> 1 WhatsApp message delivered — every member will receive it. No ban risk." . $emailNote);
                } else {
                    set_flash('danger', '❌ Group broadcast failed: ' . $waErr . $emailNote);
                }
            } else {
                if (isset($q) && $q['queued'] > 0) {
                    // Individual broadcast → open the reliable Sender page (keep tab open!)
                    set_flash('success', "✅ <strong>{$q['queued']} messages queued.</strong> The Sender is opening — keep that tab open while it sends." . $emailNote);
                    log_activity('WhatsApp Broadcast Queued', "{$q['queued']} queued → sender");
                    redirect(APP_URL . 'modules/settings/broadcast-sender.php');
                } else {
                    set_flash('danger', '❌ No active employees with WhatsApp numbers to send to.' . $emailNote);
                }
            }
        } elseif ($channel === 'email') {
            set_flash('success', "✅ Email broadcast complete." . $emailNote);
        }

    } elseif ($action === 'test') {
        $num = clean($_POST['test_number'] ?? '');
        $msg = "🧪 Test Message\n\nThis is a test from Spotcomm Global HRIS.\n\nIf you received this, WhatsApp API is working!\n\n" . date('Y-m-d H:i:s');
        $result = send_whatsapp($num, $msg);
        if ($result['ok']) set_flash('success', "✅ Test message sent to $num!");
        else set_flash('danger', "❌ Test failed: " . $result['error']);
    }
    redirect(APP_URL . 'modules/settings/whatsapp.php');
}

$employees = fetch_all("SELECT id, full_name, employee_code, whatsapp, email FROM employees WHERE status='Active' ORDER BY full_name");
$withWa = array_filter($employees, fn($e) => !empty($e['whatsapp']));
$withEmail = array_filter($employees, fn($e) => !empty($e['email']));

// Broadcast group status (for the anti-ban group delivery)
$broadcastGroupId = trim(get_setting('wa_broadcast_group', ''));
$groupReady = $broadcastGroupId !== '';

// Queue stats
$queueStats = fetch_one("SELECT 
    SUM(status='pending') as pending,
    SUM(status='sent') as sent,
    SUM(status='failed') as failed
    FROM whatsapp_queue");

$cronUrl = APP_URL . 'cron/process-queue.php?key=spotcomm-cron-2026';
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-tower-broadcast"></i> Broadcast &amp; Notifications</h1>
    <div class="sub">Send news &amp; updates to all employees via WhatsApp + Email</div></div>
  <a href="<?= url('modules/settings/whatsapp-warmup.php') ?>" class="btn btn-outline"><i class="fa-solid fa-fire"></i> Check Who Will Receive</a>
</div>

<div class="alert alert-warning" style="margin-bottom:18px">
  <i class="fa-solid fa-triangle-exclamation"></i>
  <strong>Important:</strong> WhatsApp only delivers API messages to employees you have an <strong>existing chat</strong> with. Employees with no prior chat won't receive broadcasts until a chat exists.
  <a href="<?= url('modules/settings/whatsapp-warmup.php') ?>" style="color:#7F3E98;text-decoration:underline"><strong>Check Warm-Up Status →</strong></a> to see who's ready and who needs to message you once.
</div>

<!-- Queue Status Bar -->
<?php if (($queueStats['pending'] ?? 0) > 0): ?>
<div class="card card-pad" style="margin-bottom:18px;border-left:4px solid var(--purple);background:#f8f5fc">
  <div class="flex between center wrap gap">
    <div>
      <h3 class="section-title" style="margin:0;color:var(--purple)"><i class="fa-solid fa-clock-rotate-left"></i> Message Queue Active</h3>
      <p class="muted small" style="margin:4px 0 0">
        📤 Pending: <strong><?= $queueStats['pending'] ?></strong> · 
        ✅ Sent: <strong><?= $queueStats['sent'] ?? 0 ?></strong> · 
        ❌ Failed: <strong><?= $queueStats['failed'] ?? 0 ?></strong>
      </p>
    </div>
    <div class="flex gap">
      <a href="<?= e($cronUrl) ?>" target="_blank" class="btn btn-sm btn-purple"><i class="fa-solid fa-play"></i> Process Now</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-users"></i></div><div><div class="num"><?= count($employees) ?></div><div class="lbl">Active Employees</div></div></div>
  <div class="stat"><div class="ico" style="background:#25D366"><i class="fa-brands fa-whatsapp"></i></div><div><div class="num"><?= count($withWa) ?></div><div class="lbl">WhatsApp</div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-envelope"></i></div><div><div class="num"><?= count($withEmail) ?></div><div class="lbl">Email</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num"><?= count($employees) - count($withWa) ?></div><div class="lbl">No WhatsApp</div></div></div>
</div>

<div class="grid cols-2">
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-bullhorn"></i> Broadcast to All</h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="broadcast">
      <div style="margin-bottom:12px">
        <label>Message Type</label>
        <select name="prefix" class="form-select">
          <option value="📢">📢 General Announcement</option>
          <option value="🔔">🔔 Important Notice</option>
          <option value="🎉">🎉 Celebration / Event</option>
          <option value="⚠️">⚠️ Urgent Alert</option>
          <option value="📅">📅 Holiday / Schedule</option>
        </select>
      </div>
      <div style="margin-bottom:12px">
        <label>Send Via</label>
        <select name="channel" class="form-select">
          <option value="both">📱 WhatsApp + 📧 Email (both)</option>
          <option value="whatsapp">📱 WhatsApp only</option>
          <option value="email">📧 Email only</option>
        </select>
      </div>
      <div style="margin-bottom:12px">
        <label>WhatsApp Delivery Mode</label>
        <select name="wa_mode" class="form-select">
          <option value="individual" <?= (!$groupReady||true)?'selected':'' ?>>📨 Individual — private 1-to-1 (employees can't see each other) ✅</option>
          <option value="group" <?= $groupReady?'selected':'' ?>>🛡️ Broadcast Group — 1 message (members see each other)</option>
        </select>
        <div class="muted small" style="margin-top:6px"><i class="fa-solid fa-shield-halved" style="color:#10b981"></i> <strong>Individual mode</strong> sends each employee a private message with a safe gap — best privacy. After sending it opens a <strong>Sender page</strong> (keep that tab open) that sends one-by-one with live progress.</div>
      </div>
      <div style="margin-bottom:12px">
        <label>Delay Between Messages (anti-ban)</label>
        <div class="flex gap" style="gap:8px;align-items:center">
          <input type="number" name="delay" class="form-control" value="<?= (int)get_setting('broadcast_delay_seconds','45') ?>" min="10" max="180" style="max-width:100px">
          <span class="muted small">seconds. Recommended <strong>40–50s</strong>. Higher = safer but slower.</span>
        </div>
      </div>
      <div style="margin-bottom:12px">
        <label>Your Message</label>
        <textarea name="message" class="form-control" rows="5" placeholder="Type your announcement..." required></textarea>
      </div>
      <div class="alert alert-info" style="margin-bottom:12px">
        <i class="fa-solid fa-shield-halved"></i>
        <strong>Anti-Ban Tip:</strong> Use <strong>Broadcast Group</strong> mode — WhatsApp sees only 1 outgoing message, so your number stays safe. Make sure all employees are members of that group on your phone.
      </div>
      <button class="btn btn-primary btn-block"><i class="fa-solid fa-paper-plane"></i> Send Broadcast</button>
    </form>
  </div>

  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-vial"></i> Test WhatsApp API</h3>
    <form method="post" class="flex gap" style="gap:8px;margin-bottom:18px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="test">
      <input type="text" name="test_number" class="form-control" placeholder="923001234567" required>
      <button class="btn btn-outline"><i class="fa-brands fa-whatsapp"></i> Test</button>
    </form>
    <div class="divider"></div>
    <h3 class="section-title" style="margin-bottom:10px"><i class="fa-solid fa-gear"></i> API Configuration</h3>
    <div class="kv"><span class="k">WhatsApp API URL</span><span class="v small"><?= e(get_setting('wa_api_url', WA_API_URL)) ?></span></div>
    <div class="kv"><span class="k">Endpoint</span><span class="v small">/api/sendText</span></div>
    <div class="kv"><span class="k">WhatsApp</span><span class="v"><?= get_setting('wa_enabled', '1') === '1' ? '<span class="badge badge-green">Enabled</span>' : '<span class="badge badge-red">Disabled</span>' ?></span></div>
    <div class="kv"><span class="k">Email From</span><span class="v small"><?= e(get_setting('mail_from', MAIL_FROM)) ?></span></div>
    <div class="divider"></div>
    <div class="alert alert-warning" style="font-size:12px">
      <i class="fa-solid fa-clock"></i> 
      <strong>Cron Job Required for Auto-Send:</strong><br>
      Set up in cPanel → Cron Jobs (every minute):<br>
      <code style="font-size:10px;word-break:break-all">* * * * * curl -s "<?= e($cronUrl) ?>" >/dev/null 2>&1</code>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
