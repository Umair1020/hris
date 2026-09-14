<?php
/**
 * ============================================================================
 * WHATSAPP WARM-UP STATUS — shows which employees have an EXISTING chat with
 * the linked WhatsApp Business number (so broadcasts deliver) vs. which still
 * need to message the number once.
 *
 * WHY: Baileys running as a WhatsApp "companion device" can only RELIABLY
 * deliver to chats that already exist. New contacts (no prior chat) silently
 * fail to receive API-sent messages. This tool fetches the real chat list from
 * WAHA and matches it against employees so HR knows exactly who to warm up.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('WhatsApp Warm-Up Status', 'hr');

$apiUrl = rtrim(get_setting('wa_api_url', ''), '/');
$session = get_setting('wa_session', 'default');
$token = get_setting('wa_token', '');

// 1. Fetch the REAL list of existing chats from WAHA
$chatNumbers = [];   // set of normalized numbers that have an existing chat
$fetchError = '';
$chatsFound = 0;
if ($apiUrl) {
    // Try WAHA paths (session in path), then query-style fallbacks
    $sp = '/api/' . urlencode($session);
    $candidates = [
        $apiUrl . $sp . '/chats?limit=2000&sortBy=messageTimestamp&sortOrder=desc',
        $apiUrl . $sp . '/chats/overview?limit=2000',
        $apiUrl . '/api/chats?session=' . urlencode($session),
    ];
    foreach ($candidates as $url) {
        $resp = http_get_json($url, $token);
        if (!$resp['ok'] || $resp['body'] === '') continue;
        $data = json_decode($resp['body'], true);
        $list = $data;
        if (isset($data['chats']) && is_array($data['chats'])) $list = $data['chats'];
        elseif (isset($data['response']) && is_array($data['response'])) $list = $data['response'];
        if (!is_array($list)) continue;
        foreach ($list as $c) {
            if (!is_array($c)) continue;
            $id = $c['id'] ?? ($c['jid'] ?? ($c['chatId'] ?? ($c['remoteJid'] ?? '')));
            if (!$id) continue;
            // only individual chats (@c.us); ignore groups/newsletters
            if (strpos($id, '@c.us') !== false) {
                $num = preg_replace('/[^0-9]/', '', $id); // strip @c.us -> digits
                if ($num !== '') $chatNumbers[$num] = true;
            }
        }
        if ($chatNumbers) { $chatsFound = count($chatNumbers); break; }
    }
    if (!$chatNumbers) {
        $fetchError = 'Could not read your existing chats from the API (' . ($session) . ' session). ' .
            'Make sure the session is connected. If this keeps failing, you can still warm up employees manually by messaging them once from your phone.';
    }
} else {
    $fetchError = 'WhatsApp API URL not configured. Set it in Settings → WhatsApp.';
}

// 2. Match employees against the existing chats
$employees = fetch_all("SELECT id, full_name, employee_code, whatsapp FROM employees WHERE status='Active' ORDER BY full_name");
$rows = [];
$ready = 0; $needsWarmup = 0; $noNumber = 0; $invalid = 0;
foreach ($employees as $emp) {
    $raw = trim((string)$emp['whatsapp']);
    if ($raw === '') { $noNumber++; continue; }
    $norm = normalize_whatsapp($raw);
    $valid = is_valid_whatsapp($raw);
    $exists = isset($chatNumbers[$norm]);
    if (!$valid) { $invalid++; }
    if ($exists) $ready++; else $needsWarmup++;
    $rows[] = [
        'name' => $emp['full_name'],
        'code' => $emp['employee_code'],
        'raw' => $raw,
        'norm' => $norm,
        'valid' => $valid,
        'exists' => $exists,
    ];
}
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-fire"></i> WhatsApp Warm-Up Status</h1>
    <div class="sub">Who will receive broadcasts vs. who needs to message you once</div></div>
  <a href="<?= url('modules/settings/broadcast-sender.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Sender</a>
</div>

<div class="alert alert-info">
  <i class="fa-solid fa-circle-info"></i>
  <strong>Why this matters:</strong> WhatsApp's linked-device (companion) mode only delivers API messages to employees you have an <strong>existing chat</strong> with. Employees with <strong>no prior chat</strong> won't receive broadcasts until they (or you) start a chat once. This page shows exactly who's ready and who isn't.
</div>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-check"></i></div><div><div class="num" style="color:#10b981"><?= $ready ?></div><div class="lbl">Ready (chat exists)</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="num" style="color:#f59e0b"><?= $needsWarmup ?></div><div class="lbl">Needs Warm-Up</div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="num" style="color:#ef4444"><?= $invalid ?></div><div class="lbl">Invalid Number</div></div></div>
  <div class="stat"><div class="ico bg-gray"><i class="fa-solid fa-phone-slash"></i></div><div><div class="num"><?= $noNumber ?></div><div class="lbl">No Number</div></div></div>
</div>

<?php if ($fetchError): ?>
<div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> <?= $fetchError ?></div>
<?php elseif ($needsWarmup === 0): ?>
<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <strong>Awesome! All <?= $ready ?> employees have existing chats.</strong> Broadcasts will deliver to everyone. You're ready to send!</div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:18px">
  <h3 class="section-title" style="margin-bottom:10px"><i class="fa-solid fa-lightbulb" style="color:#F26223"></i> How to Warm Up the "Needs Warm-Up" employees</h3>
  <ol class="small" style="line-height:1.9;padding-left:20px;color:#555">
    <li><strong>Easiest:</strong> Ask those employees to send <code>Hi</code> once to your Spotcomm WhatsApp Business number. The moment they message you, a chat is created and broadcasts start reaching them.</li>
    <li>Or open each "Needs Warm-Up" employee on your phone and send them one message — that also creates the chat.</li>
    <li>After warming up, come back here and refresh — their status will flip to <span class="badge badge-green">Ready</span>.</li>
  </ol>
  <p class="muted small"><i class="fa-solid fa-rotate"></i> This list is read live from your WhatsApp each time you open/refresh this page.</p>
  <a href="<?= url('modules/settings/whatsapp-warmup.php') ?>" class="btn btn-outline"><i class="fa-solid fa-rotate"></i> Refresh Status</a>
</div>

<div class="card card-pad">
  <div class="flex between center wrap" style="margin-bottom:12px">
    <h3 class="section-title" style="margin:0"><i class="fa-solid fa-users"></i> Employees</h3>
    <input type="text" id="warmFilter" class="form-control" placeholder="Search name/code..." style="max-width:240px" oninput="document.querySelectorAll('#warmRows tr').forEach(function(r){r.style.display = r.dataset.q.toLowerCase().includes(this.value.toLowerCase())?'':'none';}.bind(this))">
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Code</th><th>Stored Number</th><th>Sent To API</th><th>Status</th></tr></thead>
      <tbody id="warmRows">
      <?php foreach ($rows as $r): ?>
        <tr data-q="<?= e($r['name'].' '.$r['code'].' '.$r['raw']) ?>">
          <td class="bold"><?= e($r['name']) ?></td>
          <td class="small muted"><?= e($r['code']) ?></td>
          <td class="small"><?= e($r['raw']) ?></td>
          <td class="small"><code><?= e($r['norm']) ?></code> <?= $r['valid'] ? '' : '<span class="badge badge-red" style="font-size:9px">invalid?</span>' ?></td>
          <td>
            <?php if ($r['exists']): ?>
              <span class="badge badge-green"><i class="fa-solid fa-check"></i> Ready</span>
            <?php else: ?>
              <span class="badge badge-amber"><i class="fa-solid fa-hourglass-half"></i> Needs Warm-Up</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
