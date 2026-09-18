<?php
/**
 * ============================================================================
 * MY LEAVES — employee apply for leave + view requests/balances
 * Business rules (per Spotcomm vision):
 *   - Casual Leave: minimum 7 days advance notice
 *   - Sick/Medical Leave: minimum 1 day advance notice
 *   - Emergency (medical) Leave: immediate, HR-only approval
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Leaves');
$empId = current_employee_id();
$year = (int)date('Y');

// leave balances
$balances = fetch_all(
    "SELECT lb.*, lt.name, lt.code, lt.color, lt.min_lead_days, lt.is_paid
     FROM leave_balances lb JOIN leave_types lt ON lt.id=lb.leave_type_id
     WHERE lb.employee_id=? AND lb.year=? ORDER BY lt.id", [$empId, $year]
);

// my requests
$requests = fetch_all(
    "SELECT lr.*, lt.name type_name, lt.color FROM leave_requests lr
     JOIN leave_types lt ON lt.id=lr.leave_type_id
     WHERE lr.employee_id=? ORDER BY lr.id DESC", [$empId]
);

// leave types for the form
$types = fetch_all("SELECT * FROM leave_types ORDER BY id");

if (($_GET['action'] ?? '') === 'new'): ?>
<div class="page-head"><div><h1>Apply for Leave / Half Day</h1><div class="sub">Submit a full-day leave or a half-day request</div></div>
  <a href="<?= url('modules/leave/my.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a>
</div>
<form method="post" action="<?= url('modules/leave/submit.php') ?>" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="card card-pad">
    <div class="tabs" style="margin-bottom:16px">
      <a href="javascript:void(0)" id="tabFull" class="<?= ($_GET['type'] ?? '') === 'half' ? '' : 'active' ?>" onclick="setMode(0)"><i class="fa-solid fa-calendar-day"></i> Full Day Leave</a>
      <a href="javascript:void(0)" id="tabHalf" class="<?= ($_GET['type'] ?? '') === 'half' ? 'active' : '' ?>" onclick="setMode(1)"><i class="fa-solid fa-star-half-stroke"></i> Half Day</a>
    </div>
    <input type="hidden" name="half_day" id="halfDay" value="<?= ($_GET['type'] ?? '') === 'half' ? '1' : '0' ?>">
    <div class="grid cols-2">
      <div><label>Leave Type</label>
        <select name="leave_type_id" id="lt" class="form-select" required onchange="updateNotice()">
          <?php foreach ($types as $t): $bal = null; foreach ($balances as $b) if ($b['leave_type_id'] == $t['id']) $bal = $b['allocated'] - $b['used']; ?>
            <option value="<?= $t['id'] ?>" data-min="<?= $t['min_lead_days'] ?>" data-code="<?= e($t['code']) ?>"><?= e($t['name']) ?><?= $bal !== null ? " — $bal day(s) left" : '' ?> (min <?= $t['min_lead_days'] ?>d advance)</option>
          <?php endforeach; ?>
        </select>
        <div class="muted small" style="margin-top:4px">Half day is deducted as 0.5 day from the selected leave type.</div>
      </div>
      <div id="sessionBox" style="display:none"><label>Which Half?</label>
        <select name="half_day_session" class="form-select">
          <option value="first_half">First Half (arrive late — come after lunch)</option>
          <option value="second_half">Second Half (leave early)</option>
        </select>
      </div>
      <div><label id="sdLabel">Start Date</label><input type="date" name="start_date" id="sd" class="form-control" required onchange="calcDays()"></div>
      <div id="edBox"><label>End Date</label><input type="date" name="end_date" id="ed" class="form-control" required onchange="calcDays()"></div>
      <div style="grid-column:span 2"><label>Reason</label><textarea name="reason" class="form-control" rows="3" placeholder="Briefly describe the reason for your leave" required></textarea></div>
      <div><label>Medical/Emergency Document (optional)</label><input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.png"></div>
      <div><label>Emergency Leave? (immediate HR approval)</label>
        <select name="is_emergency" class="form-select"><option value="0">No</option><option value="1">Yes — Medical Emergency</option></select>
      </div>
    </div>
    <div id="noticeBox" class="alert alert-info" style="margin-top:14px"><i class="fa-solid fa-circle-info"></i> <span id="noticeText"></span> <span id="daysText" class="bold"></span></div>
    <div class="flex gap" style="margin-top:14px"><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Submit Request</button><a href="<?= url('modules/leave/my.php') ?>" class="btn btn-light">Cancel</a></div>
  </div>
</form>
<script>
function setMode(half){
  document.getElementById('halfDay').value = half ? '1' : '0';
  document.getElementById('tabFull').classList.toggle('active', !half);
  document.getElementById('tabHalf').classList.toggle('active', !!half);
  document.getElementById('sessionBox').style.display = half ? '' : 'none';
  document.getElementById('edBox').style.display = half ? 'none' : '';
  document.getElementById('sdLabel').textContent = half ? 'Date' : 'Start Date';
  document.getElementById('ed').required = !half;
  if (half) { document.getElementById('ed').value = document.getElementById('sd').value; }
  calcDays();
}
function updateNotice(){
  var sel=document.getElementById('lt');
  var min=sel.options[sel.selectedIndex].dataset.min;
  var code=sel.options[sel.selectedIndex].dataset.code;
  document.getElementById('noticeText').textContent='Minimum advance notice required: '+min+' day(s). '+
    (code==='EMG'?'Emergency leaves bypass manager approval and are approved directly by HR.':'');
}
function calcDays(){
  var half = document.getElementById('halfDay').value === '1';
  var s=document.getElementById('sd').value,e=document.getElementById('ed').value;
  if (half) { document.getElementById('ed').value = s; document.getElementById('daysText').textContent = s ? 'Requesting 0.5 day.' : ''; return; }
  if(s&&e){var d=Math.max(1,Math.round((new Date(e)-new Date(s))/86400000)+1);
    document.getElementById('daysText').textContent='Requesting up to '+d+' calendar day(s) — off days & public holidays are not counted.';
  } else document.getElementById('daysText').textContent='';
}
updateNotice();
setMode(document.getElementById('halfDay').value === '1');
</script>
<?php
else:
?>
<div class="page-head">
  <div><h1>My Leaves</h1><div class="sub">Apply for leave and track your requests &amp; balances</div></div>
  <div class="flex gap">
    <a href="<?= url('modules/leave/my.php?action=new&type=half') ?>" class="btn btn-light"><i class="fa-solid fa-star-half-stroke"></i> Apply Half Day</a>
    <a href="<?= url('modules/leave/my.php?action=new') ?>" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Apply for Leave</a>
  </div>
</div>

<div class="grid cols-4">
  <?php foreach ($balances as $b): $rem=$b['allocated']-$b['used']; $pct=$b['allocated']>0?($b['used']/$b['allocated']*100):0; ?>
  <div class="card card-pad">
    <div class="flex between center" style="margin-bottom:8px">
      <span class="badge" style="background:<?= e($b['color']) ?>20;color:<?= e($b['color']) ?>"><?= e($b['code']) ?></span>
      <span class="bold"><?= $rem ?> / <?= $b['allocated'] ?> days left</span>
    </div>
    <div class="small muted"><?= e($b['name']) ?></div>
    <div class="progress" style="margin-top:8px"><div class="bar" style="width:<?= $pct ?>%"></div></div>
    <div class="small muted" style="margin-top:6px"><?= $b['used'] ?> used of <?= $b['allocated'] ?></div>
  </div>
  <?php endforeach; if(!$balances): ?>
    <div class="card card-pad empty"><i class="fa-solid fa-calendar-xmark"></i>No leave balances allocated for <?= $year ?>. Contact HR.</div>
  <?php endif; ?>
</div>

<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px">My Leave Requests</h3>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Reason</th><th>Manager</th><th>HR</th><th>Status</th></tr></thead>
      <tbody>
      <?php if(!$requests): echo '<tr><td colspan="8" class="empty"><i class="fa-solid fa-inbox"></i>No leave requests yet</td></tr>';
      else: foreach($requests as $r): ?>
        <tr>
          <td><?= leave_type_badge($r) ?></td>
          <td><?= format_date($r['start_date'],'d M Y') ?></td>
          <td><?= format_date($r['end_date'],'d M Y') ?></td>
          <td><?= $r['days'] ?></td>
          <td class="small muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['reason']) ?></td>
          <td><span class="badge badge-<?= $r['manager_status']==='approved'?'green':($r['manager_status']==='rejected'?'red':'amber') ?>"><?= ucfirst($r['manager_status']) ?></span></td>
          <td><span class="badge badge-<?= $r['hr_status']==='approved'?'green':($r['hr_status']==='rejected'?'red':'amber') ?>"><?= ucfirst($r['hr_status']) ?></span></td>
          <td><span class="badge badge-<?= $r['status']==='approved'?'green':($r['status']==='rejected'?'red':'amber') ?>"><?= ucfirst($r['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
