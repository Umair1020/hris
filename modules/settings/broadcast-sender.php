<?php
/**
 * ============================================================================
 * BROADCAST SENDER — reliable, visible, browser-driven WhatsApp sender.
 *
 * WHY THIS EXISTS:
 *  The old queue relied on a footer AJAX snippet that only fired on page loads
 *  and sent 3 messages per load — invisible and unreliable. This page runs a
 *  proper loop in an open tab: it sends ONE message, waits N seconds, sends the
 *  next, and shows live progress. Each employee gets a private 1-to-1 message
 *  (so they never see each other's numbers) with a safe 40-50s gap to protect
 *  the number from being banned.
 *
 *  - Resumable: close the tab, reopen, click Start — it continues from pending.
 *  - Configurable delay (default 45s).
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Broadcast Sender', 'hr');

$stats = fetch_one("SELECT
    SUM(status='pending' AND channel='whatsapp') AS pending,
    SUM(status='sent'    AND channel='whatsapp') AS sent,
    SUM(status='failed'  AND channel='whatsapp') AS failed
    FROM whatsapp_queue");
$pending = (int)($stats['pending'] ?? 0);
$sent    = (int)($stats['sent'] ?? 0);
$failed  = (int)($stats['failed'] ?? 0);
$total   = $pending + $sent + $failed;
$delayDefault = (int)get_setting('broadcast_delay_seconds', '45');
$csrf = csrf_token();
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-paper-plane"></i> WhatsApp Broadcast Sender</h1>
    <div class="sub">Sends one private message per employee with a safe delay — keeps your number safe</div></div>
  <div class="flex gap">
    <a href="<?= url('modules/settings/whatsapp-warmup.php') ?>" class="btn btn-outline"><i class="fa-solid fa-fire"></i> Warm-Up Status</a>
    <a href="<?= url('modules/settings/whatsapp.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a>
  </div>
</div>

<?php if ($total === 0): ?>
<div class="card card-pad" style="text-align:center;padding:50px">
  <i class="fa-solid fa-inbox" style="font-size:48px;color:#d1c5db"></i>
  <h3>No messages in the queue</h3>
  <p class="muted">Go to Broadcast &amp; Notifications, type an announcement and send it — it will queue up here.</p>
  <a href="<?= url('modules/settings/whatsapp.php') ?>" class="btn btn-primary"><i class="fa-solid fa-bullhorn"></i> Create Broadcast</a>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; return; endif; ?>

<div class="card card-pad" style="margin-bottom:18px">
  <div class="grid cols-4" style="margin-bottom:16px">
    <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-clock"></i></div><div><div class="num" id="statPending"><?= $pending ?></div><div class="lbl">Pending</div></div></div>
    <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-check"></i></div><div><div class="num" id="statSent" style="color:#10b981"><?= $sent ?></div><div class="lbl">Sent</div></div></div>
    <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-xmark"></i></div><div><div class="num" id="statFailed" style="color:#ef4444"><?= $failed ?></div><div class="lbl">Failed</div></div></div>
    <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-users"></i></div><div><div class="num" id="statTotal"><?= $total ?></div><div class="lbl">Total</div></div></div>
  </div>

  <!-- Progress bar -->
  <div style="margin-bottom:8px"><div class="flex between center small"><span class="bold" id="progressLabel"><?= $sent ?> / <?= $total ?> sent</span><span id="progressPct"><?= $total ? round($sent/$total*100) : 0 ?>%</span></div></div>
  <div class="progress" style="height:22px;background:#eef0f6;border-radius:12px;overflow:hidden"><div id="progressBar" class="bar" style="width:<?= $total ? round($sent/$total*100) : 0 ?>%;background:linear-gradient(90deg,#7F3E98,#9B59B6);transition:width .4s"></div></div>

  <!-- Status + controls -->
  <div class="flex between center wrap gap" style="margin-top:18px">
    <div>
      <div id="statusBadge" class="badge badge-gray" style="font-size:14px;padding:8px 14px"><i class="fa-solid fa-circle-pause"></i> Idle</div>
      <div id="statusDetail" class="muted small" style="margin-top:6px"><?= $pending ?> message(s) waiting. Click <strong>Start</strong> to begin sending.</div>
    </div>
    <div class="flex gap" style="gap:8px;flex-wrap:wrap">
      <label class="flex center gap" style="gap:6px;font-size:13px">Delay:
        <input type="number" id="delayInput" value="<?= $delayDefault ?>" min="10" max="180" style="width:70px" class="form-control">
        <span class="muted small">sec</span>
      </label>
      <button id="startBtn" class="btn btn-primary" onclick="startSending()"><i class="fa-solid fa-play"></i> Start Sending</button>
      <button id="pauseBtn" class="btn btn-light" onclick="pauseSending()" disabled><i class="fa-solid fa-pause"></i> Pause</button>
    </div>
  </div>
</div>

<div class="grid cols-2">
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-clock-rotate-left"></i> Live Activity</h3>
    <div id="activityLog" style="max-height:340px;overflow-y:auto;font-size:13px">
      <div class="muted small">Activity will appear here as messages are sent…</div>
    </div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-circle-info"></i> How it works &amp; Safety</h3>
    <ul class="small" style="line-height:1.9;padding-left:18px;color:#555">
      <li>Each employee gets a <strong>private 1-to-1 message</strong> — nobody sees anyone else's number. ✅</li>
      <li>A <strong><?= $delayDefault ?>-second gap</strong> between messages keeps your number safe from bans. ✅</li>
      <li><strong>Keep this tab open</strong> while sending. If you close it, reopen later and click <em>Start</em> — it resumes automatically.</li>
      <li>Estimated time: <strong id="etaLabel">—</strong> for remaining messages.</li>
    </ul>
    <div class="divider"></div>
    <div class="flex gap" style="gap:8px;flex-wrap:wrap">
      <button class="btn btn-sm btn-outline" onclick="retryFailed()" data-confirm="Move all failed messages back to pending?"><i class="fa-solid fa-rotate-right"></i> Retry Failed</button>
      <button class="btn btn-sm btn-light" onclick="clearQueue()" data-confirm="Delete ALL pending messages? This cannot be undone."><i class="fa-solid fa-trash"></i> Clear Pending</button>
    </div>
    <p class="muted small" style="margin-top:10px"><i class="fa-solid fa-triangle-exclamation"></i> Don't send more than ~1 broadcast per day to large lists. WhatsApp bans come from volume + reports, not from normal HR announcements.</p>
  </div>
</div>

<script>
var CSRF = "<?= $csrf ?>";
var running = false;
var timerHandle = null;
var apiBase = "<?= url('modules/settings/broadcast-send-next.php') ?>";

function apiCall(extra){ return fetch(apiBase + "?token=" + CSRF + "&_=" + Date.now() + (extra||""), {cache:"no-store"}).then(function(r){return r.json();}); }

function fmtTime(sec){
  if(sec<=0) return "0 min";
  var h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60);
  if(h>0) return h+"h "+m+"m";
  return m+" min";
}

function updateStats(s){
  document.getElementById('statPending').textContent = s.pending;
  document.getElementById('statSent').textContent = s.sent;
  document.getElementById('statFailed').textContent = s.failed;
  var total = s.pending + s.sent + s.failed;
  document.getElementById('statTotal').textContent = total;
  var pct = total ? Math.round(s.sent/total*100) : 0;
  document.getElementById('progressBar').style.width = pct + '%';
  document.getElementById('progressPct').textContent = pct + '%';
  document.getElementById('progressLabel').textContent = s.sent + ' / ' + total + ' sent';
  // ETA
  var delay = parseInt(document.getElementById('delayInput').value)||45;
  document.getElementById('etaLabel').textContent = fmtTime(s.pending * delay);
}

function setStatus(badgeHtml, detailHtml){
  document.getElementById('statusBadge').innerHTML = badgeHtml;
  document.getElementById('statusDetail').innerHTML = detailHtml;
}

function addLog(html){
  var log = document.getElementById('activityLog');
  if(log.querySelector('.muted')) log.innerHTML = '';
  var div = document.createElement('div');
  div.style.cssText = 'padding:8px 0;border-bottom:1px solid #f0eef5';
  div.innerHTML = html;
  log.insertBefore(div, log.firstChild);
  // keep last 60
  while(log.children.length > 60) log.removeChild(log.lastChild);
}

function sendOne(){
  if(!running) return;
  setStatus('<i class="fa-solid fa-paper-plane fa-bounce"></i> Sending…', 'Fetching next recipient…');
  apiCall().then(function(d){
    if(!d.ok){
      addLog('<span style="color:#ef4444">⚠️ Error: '+d.error+'</span>');
      setStatus('<span style="color:#ef4444"><i class="fa-solid fa-circle-exclamation"></i> Error</span>', d.error);
      running=false; toggleButtons();
      return;
    }
    if(d.done){
      setStatus('<i class="fa-solid fa-circle-check" style="color:#10b981"></i> Complete!', 'All messages sent. Queue is empty. 🎉');
      running=false; toggleButtons();
      updateStats(d);
      return;
    }
    if(d.result === 'sent'){
      addLog('<span style="color:#10b981">✅</span> Sent to <strong>'+escapeHtml(d.recipient)+'</strong> <span class="muted">('+d.number+')</span>');
    } else {
      addLog('<span style="color:#ef4444">❌</span> Failed: <strong>'+escapeHtml(d.recipient)+'</strong> <span class="muted">— '+escapeHtml(d.error||'')+'</span>');
    }
    updateStats(d);
    // countdown then next
    var delay = parseInt(document.getElementById('delayInput').value)||45;
    runCountdown(delay, sendOne);
  }).catch(function(e){
    addLog('<span style="color:#ef4444">⚠️ Network error, retrying in 10s…</span>');
    setTimeout(function(){ if(running) sendOne(); }, 10000);
  });
}

function runCountdown(sec, cb){
  var el = document.getElementById('statusBadge');
  var remain = sec;
  setStatus('<i class="fa-solid fa-hourglass-half"></i> Next in '+remain+'s', 'Waiting safely before the next message…');
  el.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> Next in '+remain+'s';
  clearInterval(timerHandle);
  timerHandle = setInterval(function(){
    remain--;
    if(remain<=0){
      clearInterval(timerHandle);
      cb();
      return;
    }
    el.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> Next in '+remain+'s';
  }, 1000);
}

function startSending(){
  running = true;
  toggleButtons();
  sendOne();
}
function pauseSending(){
  running = false;
  clearInterval(timerHandle);
  toggleButtons();
  setStatus('<i class="fa-solid fa-circle-pause"></i> Paused', 'Click Start to resume.');
}
function toggleButtons(){
  document.getElementById('startBtn').disabled = running;
  document.getElementById('pauseBtn').disabled = !running;
}
function retryFailed(){
  apiCall('&do=retry').then(function(d){ updateStats(d); addLog('<i class="fa-solid fa-rotate-right" style="color:#7F3E98"></i> Failed messages moved back to pending.'); });
}
function clearQueue(){
  apiCall('&do=clear').then(function(d){ updateStats(d); addLog('<i class="fa-solid fa-trash" style="color:#ef4444"></i> Pending queue cleared.'); if(d.pending===0 && d.sent===0){ location.reload(); } });
}
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

// init ETA
(function(){ var d=parseInt(document.getElementById('delayInput').value)||45; document.getElementById('etaLabel').textContent=fmtTime(<?= $pending ?> * d); })();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
