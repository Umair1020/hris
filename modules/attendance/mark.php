<?php
/**
 * ============================================================================
 * MARK ATTENDANCE — Public page (no login required)
 * Employee scans QR from their card OR enters code manually.
 * Smart: detects if Clock In or Clock Out is needed.
 * Uses RELATIVE URLs for API calls (same folder as qr-api.php)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
// No auth required — this is a public page
// Pass security settings to JavaScript
$attSelfie = get_setting('att_selfie_required', '0') === '1';
$attGeofence = get_setting('att_geofence_enabled', '0') === '1';
$officeLat = get_setting('att_office_lat', '');
$officeLng = get_setting('att_office_lng', '');
$geofenceRadius = (int)get_setting('att_geofence_radius', '200');

// AUTO-DETECT: If logged in employee opens this, skip QR scan and auto-detect them
$autoCode = '';
if (isset($_GET['auto']) && is_logged_in()) {
    $empData = fetch_one("SELECT employee_code FROM employees WHERE id = ?", [current_employee_id()]);
    if ($empData) {
        $autoCode = $empData['employee_code'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Mark Attendance · <?= APP_COMPANY ?></title>
<link rel="icon" type="image/png" href="<?= asset('img/favicon.png') ?>">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=Mulish:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<style>
body{background:var(--grad-dark);min-height:100vh;margin:0}
.mark-wrap{max-width:460px;margin:0 auto;padding:16px;min-height:100vh;display:flex;flex-direction:column}
.mark-header{text-align:center;color:#fff;padding:20px 0}
.mark-header img{height:44px;margin-bottom:12px}
.mark-header h1{font-family:Oswald;font-size:22px;margin:0}
.mark-header p{opacity:.8;font-size:13px;margin:4px 0 0}
.mark-card{background:#fff;border-radius:18px;padding:24px;flex:1;box-shadow:var(--shadow-lg)}
.scanner-box{background:#000;border-radius:14px;overflow:hidden;position:relative;aspect-ratio:1;max-width:320px;margin:0 auto}
.scanner-box video{width:100%;height:100%;object-fit:cover}
.scanner-box::after{content:"";position:absolute;inset:30px;border:3px solid #25D366;border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.4)}
.scan-line{position:absolute;left:30px;right:30px;height:3px;background:#25D366;animation:scan 2s infinite;top:50%}
@keyframes scan{0%,100%{top:35px}50%{top:calc(100% - 35px)}}
.emp-found{background:var(--grad);border-radius:14px;padding:20px;color:#fff;text-align:center;margin-bottom:16px}
.emp-found .avatar-lg{width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.2);border:3px solid rgba(255,255,255,.4);display:flex;align-items:center;justify-content:center;font-size:26px;font-family:Oswald;font-weight:600;margin:0 auto 12px}
.action-btn{width:100%;padding:18px;border:none;border-radius:14px;font-size:18px;font-family:Oswald;font-weight:600;cursor:pointer;transition:.2s}
.btn-clockin{background:#10b981;color:#fff}
.btn-clockout{background:#f26223;color:#fff}
.action-btn:hover{filter:brightness(1.1);transform:translateY(-2px)}
.success-screen{text-align:center;padding:30px 0}
.success-screen .check{width:80px;height:80px;border-radius:50%;background:rgba(16,185,129,.15);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:40px;color:#10b981}
.tab-btns{display:flex;gap:8px;margin-bottom:20px;background:#f4f5fb;border-radius:12px;padding:4px}
.tab-btns button{flex:1;padding:12px;border:none;background:transparent;border-radius:8px;font-family:Oswald;font-size:14px;cursor:pointer;color:var(--muted);transition:.2s}
.tab-btns button.active{background:#fff;color:var(--primary);box-shadow:0 2px 8px rgba(0,0,0,.08);font-weight:600}
.loading-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:center;justify-content:center;z-index:999}
.loading-overlay.show{display:flex}
.loading-overlay .spin{width:50px;height:50px;border:4px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.back-link{text-align:center;margin-top:16px}
.back-link a{color:rgba(255,255,255,.7);font-size:13px;text-decoration:none}
.back-link a:hover{color:#fff}
</style>
</head>
<body>
<div class="mark-wrap">
  <div class="mark-header">
    <img src="<?= asset('img/logo.png') ?>" alt="Spotcomm Global" style="height:44px;filter:brightness(0) invert(1)">
    <h1>Mark Attendance</h1>
    <p><?= date('l, d F Y') ?> · Scan your ID card QR</p>
  </div>

  <div class="mark-card" id="mainCard">
    <div class="tab-btns" id="tabBtns">
      <button class="active" onclick="switchTab('scan')"><i class="fa-solid fa-qrcode"></i> Scan QR</button>
      <button onclick="switchTab('manual')"><i class="fa-solid fa-keyboard"></i> Enter Code</button>
    </div>
    <div id="scanTab">
      <div class="scanner-box" id="reader"></div>
      <p class="muted small center" style="margin-top:14px;text-align:center"><i class="fa-solid fa-camera"></i> Point your camera at the QR code on your ID card</p>
    </div>
    <div id="manualTab" style="display:none">
      <label>Employee Code</label>
      <div class="flex gap" style="gap:8px">
        <input type="text" id="manualCode" class="form-control" placeholder="e.g. SCG-004" style="text-transform:uppercase">
        <button class="btn btn-primary" onclick="lookupManual()"><i class="fa-solid fa-search"></i></button>
      </div>
    </div>
  </div>

  <div class="mark-card" id="empCard" style="display:none">
    <div id="empInfo"></div>
    <div id="actionArea"></div>
    <button class="btn btn-light btn-block" style="margin-top:12px" onclick="resetAll()"><i class="fa-solid fa-rotate-left"></i> Start Over</button>
  </div>

  <div class="back-link">
    <a href="../../index.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
  </div>
</div>

<div class="loading-overlay" id="loader"><div class="spin"></div></div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
var html5QrCode = null;
var scannedCode = null;
var AUTO_CODE = '<?= $autoCode ?>';
var SELFIE_REQUIRED = <?= $attSelfie ? 'true' : 'false' ?>;
var GEOFENCE_ENABLED = <?= $attGeofence ? 'true' : 'false' ?>;
var OFFICE_LAT = <?= (float)$officeLat ?: '0' ?>;
var OFFICE_LNG = <?= (float)$officeLng ?: '0' ?>;
var GEOFENCE_RADIUS = <?= $geofenceRadius ?>;

function startScanner() {
  if (html5QrCode) { try { html5QrCode.clear(); } catch(e){} }
  html5QrCode = new Html5Qrcode("reader");
  html5QrCode.start(
    { facingMode: "environment" },
    { fps: 10, qrbox: { width: 220, height: 220 } },
    onScanSuccess,
    function(err) {}
  ).catch(function(err) {
    document.getElementById('reader').innerHTML = '<div style="padding:40px;text-align:center;color:#666"><i class="fa-solid fa-camera-slash" style="font-size:30px"></i><p style="margin:10px 0;font-size:13px">Camera not available.<br>Use "Enter Code" tab instead.</p></div>';
  });
}

function onScanSuccess(decodedText) {
  if (scannedCode === decodedText) return;
  scannedCode = decodedText;
  if (html5QrCode) { try { html5QrCode.stop(); } catch(e){} }
  lookupEmployee(decodedText.trim());
}

function switchTab(tab) {
  document.querySelectorAll('.tab-btns button').forEach(function(b){b.classList.remove('active')});
  if(event) event.target.closest('button').classList.add('active');
  if (tab === 'scan') {
    document.getElementById('scanTab').style.display = '';
    document.getElementById('manualTab').style.display = 'none';
    scannedCode = null;
    startScanner();
  } else {
    document.getElementById('scanTab').style.display = 'none';
    document.getElementById('manualTab').style.display = '';
    if (html5QrCode) { try { html5QrCode.stop(); } catch(e){} }
    document.getElementById('manualCode').focus();
  }
}

function lookupManual() {
  var code = document.getElementById('manualCode').value.trim().toUpperCase();
  if (!code) return;
  lookupEmployee(code);
}

function showLoader(show) {
  document.getElementById('loader').classList.toggle('show', show);
}

// CRITICAL FIX: Use RELATIVE URL 'qr-api.php' (same folder as mark.php)
var API_URL = 'qr-api.php';

function lookupEmployee(code) {
  showLoader(true);
  fetch(API_URL + '?action=lookup&code=' + encodeURIComponent(code))
    .then(function(r) {
      if(!r.ok) throw new Error('Server error (HTTP ' + r.status + ')');
      return r.json();
    })
    .then(function(data) {
      showLoader(false);
      if (data.ok) {
        if (data.completed) { showCompleted(data); }
        else { showEmployee(data); }
      } else {
        alert(data.error || 'Employee not found');
        scannedCode = null;
        startScanner();
      }
    })
    .catch(function(err) {
      showLoader(false);
      alert('Connection error: ' + err.message + '\n\nPlease check your internet and try again.');
      scannedCode = null;
      startScanner();
    });
}

function showEmployee(data) {
  document.getElementById('mainCard').style.display = 'none';
  document.getElementById('empCard').style.display = '';
  document.getElementById('empInfo').innerHTML =
    '<div class="emp-found">' +
    '<div class="avatar-lg">' + getInitials(data.name) + '</div>' +
    '<h2 style="margin:0 0 4px;font-size:22px">' + data.name + '</h2>' +
    '<p style="opacity:.85;margin:0;font-size:13px">' + data.designation + ' · ' + data.department + '</p>' +
    '<p style="opacity:.7;margin:6px 0 0;font-size:12px">' + data.code + '</p>' +
    '<div style="margin-top:12px;padding:6px 14px;background:rgba(255,255,255,.15);border-radius:20px;display:inline-block;font-size:12px">📅 ' + data.status_text + '</div>' +
    '</div>';
  var btnText = data.next_action === 'in' ? '🕐 CLOCK IN' : '🏠 CLOCK OUT';
  var btnClass = data.next_action === 'in' ? 'btn-clockin' : 'btn-clockout';
  var btnIcon = data.next_action === 'in' ? 'fa-right-to-bracket' : 'fa-right-from-bracket';
  document.getElementById('actionArea').innerHTML =
    '<button class="action-btn ' + btnClass + '" onclick="startMark(\'' + data.code + '\', \'' + data.next_action + '\')">' +
    '<i class="fa-solid ' + btnIcon + '"></i> ' + btnText + '</button>' +
    '<p class="muted small center" style="text-align:center;margin-top:10px"><i class="fa-solid fa-location-dot"></i> Your location will be verified' + (SELFIE_REQUIRED ? ' · <i class="fa-solid fa-camera"></i> Selfie required' : '') + '</p>';
}

// --- Attendance marking flow with selfie + geofence ---
var pendingCode = null, pendingAction = null, pendingLocation = null, pendingSelfie = null;

function startMark(code, action) {
  pendingCode = code;
  pendingAction = action;
  pendingSelfie = null;
  showLoader(true);

  // Step 1: Get location
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      function(pos) {
        pendingLocation = pos.coords.latitude + ',' + pos.coords.longitude;
        // Step 2: If selfie required, open camera
        if (SELFIE_REQUIRED) { showLoader(false); openSelfieCam(); }
        else { doFinalMark(); }
      },
      function() {
        pendingLocation = 'location-unavailable';
        if (SELFIE_REQUIRED) { showLoader(false); openSelfieCam(); }
        else { doFinalMark(); }
      },
      { timeout: 8000, enableHighAccuracy: true }
    );
  } else {
    pendingLocation = 'location-unavailable';
    if (SELFIE_REQUIRED) { showLoader(false); openSelfieCam(); }
    else { doFinalMark(); }
  }
}

// --- Selfie camera ---
var selfieStream = null;
function openSelfieCam() {
  var actionArea = document.getElementById('actionArea');
  actionArea.innerHTML =
    '<div style="text-align:center">' +
    '<h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-camera"></i> Selfie Verification</h3>' +
    '<p class="muted small" style="margin-bottom:14px">Please look at the camera. Your photo will be taken for verification.</p>' +
    '<div style="border-radius:14px;overflow:hidden;max-width:260px;margin:0 auto 14px;border:3px solid var(--primary)">' +
    '<video id="selfieVideo" autoplay playsinline style="width:100%;display:block;transform:scaleX(-1)"></video>' +
    '<canvas id="selfieCanvas" style="display:none"></canvas>' +
    '<img id="selfiePreview" style="display:none;width:100%">' +
    '</div>' +
    '<button class="btn btn-primary btn-block" id="snapBtn" onclick="takeSelfie()"><i class="fa-solid fa-camera"></i> Take Photo</button>' +
    '<button class="btn btn-success btn-block" id="confirmBtn" style="display:none;margin-top:8px" onclick="confirmSelfie()"><i class="fa-solid fa-check"></i> Confirm &amp; Submit</button>' +
    '<button class="btn btn-light btn-block" style="margin-top:8px" onclick="retakeSelfie()" id="retakeBtn" style="display:none">Retake</button>' +
    '</div>';

  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
    .then(function(stream) {
      selfieStream = stream;
      document.getElementById('selfieVideo').srcObject = stream;
    })
    .catch(function() {
      // Camera not available — proceed without selfie
      alert('Camera not available. Proceeding without selfie.');
      doFinalMark();
    });
}

function takeSelfie() {
  var video = document.getElementById('selfieVideo');
  var canvas = document.getElementById('selfieCanvas');
  canvas.width = 480; canvas.height = 480;
  var ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, 480, 480);
  pendingSelfie = canvas.toDataURL('image/jpeg', 0.7);
  document.getElementById('selfiePreview').src = pendingSelfie;
  document.getElementById('selfiePreview').style.display = 'block';
  document.getElementById('selfieVideo').style.display = 'none';
  document.getElementById('snapBtn').style.display = 'none';
  document.getElementById('confirmBtn').style.display = '';
}

function retakeSelfie() {
  document.getElementById('selfiePreview').style.display = 'none';
  document.getElementById('selfieVideo').style.display = 'block';
  document.getElementById('snapBtn').style.display = '';
  document.getElementById('confirmBtn').style.display = 'none';
}

function confirmSelfie() {
  if (selfieStream) { selfieStream.getTracks().forEach(function(t){ t.stop(); }); }
  doFinalMark();
}

function doFinalMark() {
  if (selfieStream) { selfieStream.getTracks().forEach(function(t){ t.stop(); }); }
  showLoader(true);
  var body = 'action=mark&code=' + encodeURIComponent(pendingCode) + '&location=' + encodeURIComponent(pendingLocation);
  if (pendingSelfie) body += '&selfie=' + encodeURIComponent(pendingSelfie);
  if (pendingAction === 'out' && pendingSelfie) body += '&selfie_type=out';

  fetch(API_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  })
    .then(function(r) { if(!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function(data) {
      showLoader(false);
      if (data.ok) { showSuccess(data); }
      else { alert(data.error || 'Failed to mark attendance'); }
    })
    .catch(function(err) { showLoader(false); alert('Connection error: ' + err.message); });
}

function showSuccess(data) {
  var waNote = data.wa_sent ? '<p style="color:#10b981;font-size:13px;margin-top:8px"><i class="fa-brands fa-whatsapp"></i> WhatsApp confirmation sent</p>'
                            : '<p class="muted" style="font-size:12px;margin-top:8px">WhatsApp notification pending</p>';
  document.getElementById('empInfo').innerHTML = '';
  document.getElementById('actionArea').innerHTML =
    '<div class="success-screen">' +
    '<div class="check"><i class="fa-solid fa-check"></i></div>' +
    '<h2 style="font-size:20px;margin:0 0 4px">' + data.message + '</h2>' +
    '<p class="muted" style="font-size:13px;margin:4px 0">' + data.time + '</p>' +
    waNote +
    '</div>';
}

function showCompleted(data) {
  document.getElementById('mainCard').style.display = 'none';
  document.getElementById('empCard').style.display = '';
  document.getElementById('empInfo').innerHTML =
    '<div class="emp-found">' +
    '<div class="avatar-lg">' + getInitials(data.name) + '</div>' +
    '<h2 style="margin:0 0 4px;font-size:20px">' + data.name + '</h2>' +
    '</div>';
  document.getElementById('actionArea').innerHTML =
    '<div class="success-screen">' +
    '<div class="check" style="background:rgba(245,158,11,.15);color:#f59e0b"><i class="fa-solid fa-clock"></i></div>' +
    '<p style="font-size:15px;margin:0">' + data.message + '</p>' +
    '</div>';
}

function resetAll() {
  document.getElementById('mainCard').style.display = '';
  document.getElementById('empCard').style.display = 'none';
  scannedCode = null;
  switchTab('scan');
}

function init() {
  if (AUTO_CODE) {
    // Logged-in employee from dashboard — auto-detect, skip QR scan
    document.getElementById('tabBtns').style.display = 'none';
    document.getElementById('scanTab').style.display = 'none';
    lookupEmployee(AUTO_CODE);
  } else {
    // Public user — start QR scanner
    startScanner();
  }
}

function getInitials(name) {
  if(!name) return '?';
  var parts = String(name).split(' ');
  return parts.slice(0,2).map(function(w){return w[0]||''}).join('').toUpperCase() || '?';
}

init();
</script>
</body>
</html>
