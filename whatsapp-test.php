<?php
/**
 * ============================================================================
 * WHATSAPP DIAGNOSTICS — checks server connectivity to wa.spotcomm.pk
 * Delete this file in production after setup is confirmed working.
 * ============================================================================
 */
require_once __DIR__ . '/includes/header.php';
require_login('admin');

$apiUrl = get_setting('wa_api_url', 'https://wa.spotcomm.pk');
$session = get_setting('wa_session', 'default');
$host = parse_url($apiUrl, PHP_URL_HOST);
$tests = [];

// Test 1: PHP capabilities
$tests[] = ['name' => 'cURL Extension', 'ok' => function_exists('curl_init'), 'detail' => function_exists('curl_init') ? 'Available' : 'NOT installed'];
$tests[] = ['name' => 'fsockopen', 'ok' => function_exists('fsockopen'), 'detail' => function_exists('fsockopen') ? 'Available' : 'NOT available'];
$tests[] = ['name' => 'allow_url_fopen', 'ok' => (bool)ini_get('allow_url_fopen'), 'detail' => ini_get('allow_url_fopen') ? 'On' : 'Off'];
$tests[] = ['name' => 'OpenSSL Extension', 'ok' => extension_loaded('openssl'), 'detail' => extension_loaded('openssl') ? 'Available' : 'NOT loaded'];

// Test 2: DNS resolution
$ip = @gethostbyname($host);
$tests[] = ['name' => "DNS: $host", 'ok' => $ip !== $host, 'detail' => $ip !== $host ? "Resolved → $ip" : "Cannot resolve!"];

// Test 3: fsockopen direct connection
$fp = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 10);
$tests[] = ['name' => "Port 443 (SSL) Connection", 'ok' => (bool)$fp, 'detail' => $fp ? 'Connected!' : "Failed: $errstr ($errno)"];
if ($fp) fclose($fp);

// Test 4: API status endpoint via fsockopen
$statusResp = http_post_fsockopen($apiUrl . '/api/status', '{}', '');
$tests[] = ['name' => 'API /api/status', 'ok' => $statusResp && $statusResp['ok'], 'detail' => $statusResp ? json_encode($statusResp) : 'No response'];

// Test 5: Send actual test message (if number provided)
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $num = clean($_POST['test_number'] ?? '');
    if ($num) {
        $msg = "🧪 Test from HRIS Diagnostics\n\nIf you see this, WhatsApp is working!\n\n" . date('Y-m-d H:i:s');
        $payload = json_encode(['chatId' => normalize_whatsapp($num), 'text' => $msg, 'session' => $session]);
        $endpoint = rtrim($apiUrl, '/') . '/api/sendText';
        $testResult = http_post_json($endpoint, $payload, get_setting('wa_token', ''));
    }
}

auth_header('WhatsApp Diagnostics');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-stethoscope"></i> WhatsApp Diagnostics</h1>
    <div class="sub">Server connectivity check for <?= e($host) ?></div></div>
  <a href="<?= url('modules/settings/index.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back to Settings</a>
</div>

<div class="card card-pad">
  <h3 class="section-title" style="margin-bottom:14px">Server Capability Tests</h3>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Test</th><th>Status</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($tests as $t): ?>
      <tr>
        <td class="bold"><?= e($t['name']) ?></td>
        <td><span class="badge badge-<?= $t['ok'] ? 'green' : 'red' ?>"><?= $t['ok'] ? '✅ PASS' : '❌ FAIL' ?></span></td>
        <td class="small"><?= e($t['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-paper-plane"></i> Send Test Message</h3>
  <form method="post" class="flex gap" style="gap:8px;max-width:500px">
    <?= csrf_field() ?>
    <input type="text" name="test_number" class="form-control" placeholder="923001234567" required>
    <button class="btn btn-primary"><i class="fa-brands fa-whatsapp"></i> Send Test</button>
  </form>
  <?php if ($testResult !== null): ?>
    <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?>" style="margin-top:14px">
      <i class="fa-solid fa-<?= $testResult['ok'] ? 'circle-check' : 'circle-exclamation' ?>"></i>
      <?= $testResult['ok'] ? '✅ Message sent successfully!' : '❌ Failed: ' . e($testResult['error'] ?? 'Unknown') ?>
    </div>
  <?php endif; ?>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:10px">Configuration Summary</h3>
  <div class="kv"><span class="k">API URL</span><span class="v"><?= e($apiUrl) ?></span></div>
  <div class="kv"><span class="k">Host</span><span class="v"><?= e($host) ?></span></div>
  <div class="kv"><span class="k">Session</span><span class="v"><?= e($session) ?></span></div>
  <div class="kv"><span class="k">Endpoint</span><span class="v"><?= e($apiUrl) ?>/api/sendText</span></div>
  <div class="kv"><span class="k">Token</span><span class="v"><?= get_setting('wa_token') ? 'Set' : 'Not set (not required)' ?></span></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
