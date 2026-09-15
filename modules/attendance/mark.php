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
// Pass security settings to JavaScript (default selfie to 1)
$attSelfie = get_setting('att_selfie_required', '1') === '1';
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
      <div style="display:flex;justify-content:center;gap:10px;margin-top:10px">
        <button type="button" class="btn btn-sm btn-light" onclick="toggleQrCam()"><i class="fa-solid fa-camera-rotate"></i> Flip Scanner Camera</button>
      </div>
      <p class="muted small center" style="margin-top:10px;text-align:center"><i class="fa-solid fa-camera"></i> Point your camera at the QR code on your ID card</p>
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

var qrFacingMode = "environment";

function startScanner() {
  if (html5QrCode) {
    try {
      html5QrCode.stop().then(function() { runQr(); }).catch(function() { runQr(); });
      return;
    } catch(e) {
      runQr();
      return;
    }
  }
  runQr();
}

function runQr() {
  if (html5QrCode) { try { html5QrCode.clear(); } catch(e){} }
  html5QrCode = new Html5Qrcode("reader");

  function startWith(facing) {
    return html5QrCode.start(
      { facingMode: facing },
      { fps: 10, qrbox: { width: 220, height: 220 } },
      onScanSuccess,
      function(err) {}
    );
  }

  startWith(qrFacingMode).catch(function(err) {
    console.warn('QR camera mode (' + qrFacingMode + ') failed:', err);
    // If environment camera failed (laptop/PC webcam), fallback to front (user) camera
    if (qrFacingMode === "environment") {
      qrFacingMode = "user";
      startWith("user").catch(function(err2) {
        showQrError();
      });
    } else {
      showQrError();
    }
  });
}

function showQrError() {
  document.getElementById('reader').innerHTML =
    '<div style="padding:40px;text-align:center;color:#666">' +
    '<i class="fa-solid fa-camera-slash" style="font-size:30px"></i>' +
    '<p style="margin:10px 0;font-size:13px">Camera not available.<br>Use "Enter Code" tab instead.</p>' +
    '</div>';
}

function toggleQrCam() {
  qrFacingMode = (qrFacingMode === "environment") ? "user" : "environment";
  startScanner();
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
    '<p class="muted small center" style="text-align:center;margin-top:10px"><i class="fa-solid fa-location-dot"></i> Location will be verified' + (SELFIE_REQUIRED ? ' · <i class="fa-solid fa-camera"></i> Front Camera Selfie required' : '') + '</p>';
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
        showLoader(false);
        // Step 2: Open front camera for selfie
        if (SELFIE_REQUIRED) { openSelfieCam(); }
        else { doFinalMark(); }
      },
      function() {
        pendingLocation = 'location-unavailable';
        showLoader(false);
        if (SELFIE_REQUIRED) { openSelfieCam(); }
        else { doFinalMark(); }
      },
      { timeout: 8000, enableHighAccuracy: true }
    );
  } else {
    pendingLocation = 'location-unavailable';
    showLoader(false);
    if (SELFIE_REQUIRED) { openSelfieCam(); }
    else { doFinalMark(); }
  }
}

// --- Selfie camera management (Front camera default, switch option & fallback) ---
var selfieStream = null;
var currentFacingMode = 'user'; // 'user' (front) or 'environment' (back)

function openSelfieCam() {
  currentFacingMode = 'user';
  var actionArea = document.getElementById('actionArea');
  actionArea.innerHTML =
    '<div style="text-align:center">' +
    '<h3 class="section-title" style="margin-bottom:6px"><i class="fa-solid fa-camera"></i> Selfie Verification</h3>' +
    '<p class="muted small" style="margin-bottom:12px">Look at your front camera to take a verification photo.</p>' +
    '<div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap">' +
      '<span id="camModeTitle" class="badge badge-purple" style="font-size:12px;padding:6px 12px"><i class="fa-solid fa-user"></i> Front Camera Active</span>' +
      '<button type="button" class="btn btn-sm btn-light" id="switchCamBtn" onclick="switchCamera()"><i class="fa-solid fa-camera-rotate"></i> Flip Camera</button>' +
      '<label class="btn btn-sm btn-outline" id="fileFallbackBtn" style="cursor:pointer;margin:0" title="Take selfie with native phone camera"><i class="fa-solid fa-camera"></i> Native Camera<input type="file" id="selfieFileInput" accept="image/*" capture="user" style="display:none" onchange="handleSelfieFile(this)"></label>' +
    '</div>' +
    '<div style="border-radius:14px;overflow:hidden;max-width:280px;aspect-ratio:1;margin:0 auto 14px;border:3px solid var(--primary);position:relative;background:#000">' +
      '<video id="selfieVideo" autoplay playsinline muted style="width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)"></video>' +
      '<canvas id="selfieCanvas" style="display:none"></canvas>' +
      '<img id="selfiePreview" style="display:none;width:100%;height:100%;object-fit:cover">' +
      '<div id="videoLoader" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(0,0,0,.6);color:#fff;font-size:13px">' +
        '<div class="spin" style="width:36px;height:36px;border-width:3px;margin-bottom:8px"></div>' +
        '<span>Starting Front Camera...</span>' +
      '</div>' +
    '</div>' +
    '<div id="camFallbackBox" style="display:none;margin-bottom:14px"></div>' +
    '<button class="btn btn-primary btn-block" id="snapBtn" onclick="takeSelfie()" style="display:none"><i class="fa-solid fa-camera"></i> Take Photo</button>' +
    '<button class="btn btn-success btn-block" id="confirmBtn" style="display:none;margin-top:8px" onclick="confirmSelfie()"><i class="fa-solid fa-check"></i> Confirm &amp; Submit Attendance</button>' +
    '<button class="btn btn-light btn-block" id="retakeBtn" style="display:none;margin-top:8px" onclick="retakeSelfie()"><i class="fa-solid fa-rotate-left"></i> Retake Photo</button>' +
    '<button class="btn btn-link btn-block small muted" id="skipSelfieBtn" style="display:none;margin-top:8px;font-size:12px;color:var(--muted);text-decoration:underline" onclick="skipSelfie()">Camera not working? Proceed without photo</button>' +
    '</div>';

  startSelfieStream('user');
}

function startSelfieStream(facingMode) {
  if (selfieStream) {
    try { selfieStream.getTracks().forEach(function(t) { t.stop(); }); } catch(e){}
    selfieStream = null;
  }

  currentFacingMode = facingMode || 'user';
  var video = document.getElementById('selfieVideo');
  var badge = document.getElementById('camModeTitle');
  var loader = document.getElementById('videoLoader');
  if (loader) loader.style.display = 'flex';

  if (badge) {
    badge.innerHTML = (currentFacingMode === 'user')
      ? '<i class="fa-solid fa-user"></i> Front Camera Active'
      : '<i class="fa-solid fa-camera"></i> Back Camera Active';
  }
  if (video) {
    video.style.transform = (currentFacingMode === 'user') ? 'scaleX(-1)' : 'none';
  }

  var constraintsList = [
    { video: { facingMode: { ideal: currentFacingMode }, width: { ideal: 640 }, height: { ideal: 640 } }, audio: false },
    { video: { facingMode: currentFacingMode }, audio: false },
    { video: true, audio: false }
  ];

  function tryConstraintIndex(idx) {
    if (idx >= constraintsList.length) {
      handleCameraFailure('Direct camera preview is unavailable on this device/browser. Tap below to open your camera and take a selfie:');
      return;
    }

    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      navigator.mediaDevices.getUserMedia(constraintsList[idx])
        .then(function(stream) {
          onStreamReady(stream);
        })
        .catch(function(err) {
          console.warn('getUserMedia constraint (' + idx + ') failed:', err);
          tryConstraintIndex(idx + 1);
        });
    } else {
      var legacyGUM = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia || navigator.msGetUserMedia;
      if (legacyGUM) {
        legacyGUM.call(navigator, constraintsList[idx], function(stream) {
          onStreamReady(stream);
        }, function(err) {
          tryConstraintIndex(idx + 1);
        });
      } else {
        handleCameraFailure('Camera live streaming is not supported on this browser or connection. Tap below to open front camera:');
      }
    }
  }

  tryConstraintIndex(0);
}

function onStreamReady(stream) {
  selfieStream = stream;
  var video = document.getElementById('selfieVideo');
  var loader = document.getElementById('videoLoader');
  if (video) {
    video.srcObject = stream;
    video.setAttribute('playsinline', 'true');
    video.setAttribute('muted', 'true');
    video.muted = true;
    var playPromise = video.play();
    if (playPromise !== undefined) {
      playPromise.then(function() {
        if (loader) loader.style.display = 'none';
      }).catch(function(e) {
        console.warn('Play error:', e);
        if (loader) loader.style.display = 'none';
      });
    } else {
      if (loader) loader.style.display = 'none';
    }
  }
  var snapBtn = document.getElementById('snapBtn');
  if (snapBtn) snapBtn.style.display = 'block';
  var skipBtn = document.getElementById('skipSelfieBtn');
  if (skipBtn) skipBtn.style.display = 'block';
}

function handleCameraFailure(msg) {
  var loader = document.getElementById('videoLoader');
  if (loader) loader.style.display = 'none';
  var video = document.getElementById('selfieVideo');
  if (video) video.style.display = 'none';
  var switchBtn = document.getElementById('switchCamBtn');
  if (switchBtn) switchBtn.style.display = 'none';
  var snapBtn = document.getElementById('snapBtn');
  if (snapBtn) snapBtn.style.display = 'none';

  var badge = document.getElementById('camModeTitle');
  if (badge) {
    badge.className = 'badge badge-amber';
    badge.innerHTML = '<i class="fa-solid fa-camera"></i> Camera Mode: Manual Capture';
  }

  var fbBox = document.getElementById('camFallbackBox');
  if (fbBox) {
    fbBox.style.display = 'block';
    fbBox.innerHTML =
      '<p class="small muted" style="margin:0 0 10px">' + msg + '</p>' +
      '<label class="btn btn-primary btn-block" style="cursor:pointer;padding:14px;font-size:15px">' +
      '<i class="fa-solid fa-camera"></i> Open Front Camera &amp; Take Photo' +
      '<input type="file" id="selfieFileInput" accept="image/*" capture="user" style="display:none" onchange="handleSelfieFile(this)">' +
      '</label>';
  }

  var skipBtn = document.getElementById('skipSelfieBtn');
  if (skipBtn) skipBtn.style.display = 'block';
}

function switchCamera() {
  var nextMode = (currentFacingMode === 'user') ? 'environment' : 'user';
  startSelfieStream(nextMode);
}

function handleSelfieFile(input) {
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  showLoader(true);
  var reader = new FileReader();
  reader.onload = function(e) {
    var img = new Image();
    img.onload = function() {
      showLoader(false);
      var canvas = document.getElementById('selfieCanvas');
      var maxDim = 480;
      var w = img.width, h = img.height;
      if (w > h) {
        if (w > maxDim) { h = Math.round(h * maxDim / w); w = maxDim; }
      } else {
        if (h > maxDim) { w = Math.round(w * maxDim / h); h = maxDim; }
      }
      canvas.width = w; canvas.height = h;
      var ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);
      pendingSelfie = canvas.toDataURL('image/jpeg', 0.75);

      var preview = document.getElementById('selfiePreview');
      preview.src = pendingSelfie;
      preview.style.display = 'block';

      var video = document.getElementById('selfieVideo');
      if (video) video.style.display = 'none';
      var loader = document.getElementById('videoLoader');
      if (loader) loader.style.display = 'none';

      var snapBtn = document.getElementById('snapBtn');
      if (snapBtn) snapBtn.style.display = 'none';
      var fbBox = document.getElementById('camFallbackBox');
      if (fbBox) fbBox.style.display = 'none';
      var switchBtn = document.getElementById('switchCamBtn');
      if (switchBtn) switchBtn.style.display = 'none';

      document.getElementById('confirmBtn').style.display = 'block';
      document.getElementById('retakeBtn').style.display = 'block';
    };
    img.src = e.target.result;
  };
  reader.readAsDataURL(file);
}

function takeSelfie() {
  var video = document.getElementById('selfieVideo');
  var canvas = document.getElementById('selfieCanvas');
  if (!video || !video.videoWidth) {
    alert('Camera is still starting. Please wait 1 second and try again.');
    return;
  }
  var vw = video.videoWidth;
  var vh = video.videoHeight;
  var size = Math.min(vw, vh);
  var sx = (vw - size) / 2;
  var sy = (vh - size) / 2;

  canvas.width = 480;
  canvas.height = 480;
  var ctx = canvas.getContext('2d');

  if (currentFacingMode === 'user') {
    ctx.save();
    ctx.translate(canvas.width, 0);
    ctx.scale(-1, 1);
    ctx.drawImage(video, sx, sy, size, size, 0, 0, canvas.width, canvas.height);
    ctx.restore();
  } else {
    ctx.drawImage(video, sx, sy, size, size, 0, 0, canvas.width, canvas.height);
  }

  pendingSelfie = canvas.toDataURL('image/jpeg', 0.75);
  var preview = document.getElementById('selfiePreview');
  preview.src = pendingSelfie;
  preview.style.display = 'block';
  video.style.display = 'none';

  document.getElementById('snapBtn').style.display = 'none';
  var switchBtn = document.getElementById('switchCamBtn');
  if (switchBtn) switchBtn.style.display = 'none';
  document.getElementById('confirmBtn').style.display = 'block';
  document.getElementById('retakeBtn').style.display = 'block';
}

function retakeSelfie() {
  pendingSelfie = null;
  var preview = document.getElementById('selfiePreview');
  preview.style.display = 'none';
  preview.src = '';

  var video = document.getElementById('selfieVideo');
  if (selfieStream && selfieStream.active) {
    video.style.display = 'block';
    var snapBtn = document.getElementById('snapBtn');
    if (snapBtn) snapBtn.style.display = 'block';
    var switchBtn = document.getElementById('switchCamBtn');
    if (switchBtn) switchBtn.style.display = 'inline-flex';
  } else {
    video.style.display = 'block';
    startSelfieStream(currentFacingMode);
  }

  var fbBox = document.getElementById('camFallbackBox');
  if (fbBox && (!selfieStream || !selfieStream.active)) {
    fbBox.style.display = 'block';
  }

  document.getElementById('confirmBtn').style.display = 'none';
  document.getElementById('retakeBtn').style.display = 'none';
}

function skipSelfie() {
  pendingSelfie = null;
  if (selfieStream) {
    try { selfieStream.getTracks().forEach(function(t) { t.stop(); }); } catch(e){}
    selfieStream = null;
  }
  doFinalMark();
}

function confirmSelfie() {
  if (selfieStream) {
    try { selfieStream.getTracks().forEach(function(t) { t.stop(); }); } catch(e){}
    selfieStream = null;
  }
  doFinalMark();
}

function doFinalMark() {
  if (selfieStream) {
    try { selfieStream.getTracks().forEach(function(t) { t.stop(); }); } catch(e){}
    selfieStream = null;
  }
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
