<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - DASHBOARD (role-aware)
 * ============================================================================
 */
require_once __DIR__ . '/includes/header.php';
auth_header('Dashboard');

$role = current_role();
$empId = current_employee_id();
$today = today();
$month = (int)date('m'); $year = (int)date('Y');

/* ---- stats by role ---- */
if (is_hr()) {
    $totalEmployees = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE status='Active'")['c'];
    $presentToday   = (int)fetch_one("SELECT COUNT(*) c FROM attendance WHERE attendance_date=? AND status IN ('present','late')", [$today])['c'];
    $pendingLeaves  = (int)fetch_one("SELECT COUNT(*) c FROM leave_requests WHERE status='pending'")['c'];
    $openJobs       = (int)fetch_one("SELECT COUNT(*) c FROM job_postings WHERE status='open'")['c'];
    $newApplicants  = (int)fetch_one("SELECT COUNT(*) c FROM applicants WHERE status='new'")['c'];
    $pendingReviews = (int)fetch_one("SELECT COUNT(*) c FROM performance_reviews WHERE status='draft' OR status='submitted'")['c'];
} elseif (current_role() === 'manager') {
    $myTeam = fetch_all("SELECT id FROM employees WHERE manager_id=?", [$empId]);
    $teamIds = array_column($myTeam, 'id') ?: [0];
    $in = implode(',', array_fill(0, count($teamIds), '?'));
    $totalEmployees = count($teamIds);
    $presentToday   = (int)fetch_one("SELECT COUNT(*) c FROM attendance WHERE attendance_date=? AND employee_id IN($in) AND status IN ('present','late')", array_merge([$today], $teamIds))['c'];
    $pendingLeaves  = (int)fetch_one("SELECT COUNT(*) c FROM leave_requests WHERE status='pending' AND employee_id IN($in)", $teamIds)['c'];
    $openJobs = 0; $newApplicants = 0;
    $pendingReviews = (int)fetch_one("SELECT COUNT(*) c FROM performance_reviews pr JOIN employees e ON e.id=pr.employee_id WHERE e.manager_id=? AND pr.status='submitted'", [$empId])['c'];
} else {
    $myAtt = fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, $today]);
    $balRow = fetch_one("SELECT SUM(allocated-used) bal FROM leave_balances WHERE employee_id=? AND year=?", [$empId, $year]);
    $leaveBal = $balRow ? $balRow['bal'] : 0;
    $loanRow = fetch_one("SELECT SUM(remaining) r FROM loans WHERE employee_id=? AND status='active'", [$empId]);
    $loanRem = $loanRow ? $loanRow['r'] : 0;
    $bonusRow = fetch_one("SELECT SUM(amount) a FROM bonuses WHERE employee_id=? AND " . sql_year('applied_date') . "=?", [$empId, $year]);
    $bonusYtd = $bonusRow ? $bonusRow['a'] : 0;
}

/* attendance trend for HR (last 7 days) */
if (is_hr()) {
    $trend = fetch_all(
        "SELECT attendance_date d,
                SUM(CASE WHEN status IN('present','late') THEN 1 ELSE 0 END) present,
                SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) absent
         FROM attendance
         WHERE attendance_date >= " . sql_days_ago(6) . "
         GROUP BY attendance_date ORDER BY attendance_date"
    );
    $trendLabels = json_encode(array_map(fn($r) => date('D', strtotime($r['d'])), $trend));
    $trendPresent = json_encode(array_map(fn($r) => (int)$r['present'], $trend));
    $trendAbsent = json_encode(array_map(fn($r) => (int)$r['absent'], $trend));

    $deptDist = fetch_all(
        "SELECT d.name, COUNT(e.id) cnt
         FROM departments d LEFT JOIN employees e ON e.department_id=d.id AND e.status='Active'
         GROUP BY d.id ORDER BY cnt DESC"
    );
}

/* recent activities */
$activities = fetch_all("SELECT * FROM activity_log ORDER BY id DESC LIMIT 8");
?>

<div class="page-head">
  <div>
    <h1>Assalam-o-Alaikum, <?= e(explode(' ', current_name())[0]) ?>! 👋</h1>
    <div class="sub"><?= date('l, d F Y') ?> · Here's your Spotcomm HRIS overview</div>
  </div>
  <div class="flex gap wrap">
    <a href="<?= url('modules/attendance/mark.php?auto=1') ?>" class="btn btn-primary"><i class="fa-solid fa-fingerprint"></i> Mark Attendance</a>
    <?php if (role_at_least('employee')): ?>
      <a href="<?= url('modules/leave/my.php?action=new') ?>" class="btn btn-outline"><i class="fa-solid fa-plus"></i> Apply Leave</a>
    <?php endif; ?>
  </div>
</div>

<!-- ============ EMPLOYEE DASHBOARD ============ -->
<?php if (current_role() === 'employee'): ?>
<div class="grid cols-4">
  <div class="stat">
    <div class="ico bg-primary"><i class="fa-solid fa-clock"></i></div>
    <div><div class="num"><?= $myAtt ? 'Clocked In' : 'Not Marked' ?></div><div class="lbl">Today's Attendance</div></div>
  </div>
  <div class="stat">
    <div class="ico bg-purple"><i class="fa-solid fa-calendar-check"></i></div>
    <div><div class="num"><?= money($leaveBal) ?></div><div class="lbl">Leave Balance (days)</div></div>
  </div>
  <div class="stat">
    <div class="ico bg-accent"><i class="fa-solid fa-hand-holding-dollar"></i></div>
    <div><div class="num"><?= money($loanRem) ?></div><div class="lbl">Outstanding Loan</div></div>
  </div>
  <div class="stat">
    <div class="ico bg-green"><i class="fa-solid fa-gift"></i></div>
    <div><div class="num"><?= money($bonusYtd) ?></div><div class="lbl">Bonuses (<?= $year ?>)</div></div>
  </div>
</div>

<div class="grid cols-2" style="margin-top:18px">
  <div class="card card-pad">
    <div class="flex between center" style="margin-bottom:14px">
      <h3 class="section-title">Quick Access</h3>
    </div>
    <div class="grid cols-2">
      <a class="card card-pad" href="<?= url('modules/attendance/my.php') ?>" style="text-align:center;color:inherit"><i class="fa-solid fa-clock" style="font-size:24px;color:#2e3192"></i><div style="margin-top:8px;font-weight:600">My Attendance</div></a>
      <a class="card card-pad" href="<?= url('modules/payroll/my.php') ?>" style="text-align:center;color:inherit"><i class="fa-solid fa-file-invoice-dollar" style="font-size:24px;color:#7d3ef2"></i><div style="margin-top:8px;font-weight:600">My Payslips</div></a>
      <a class="card card-pad" href="<?= url('modules/performance/my.php') ?>" style="text-align:center;color:inherit"><i class="fa-solid fa-chart-line" style="font-size:24px;color:#f26223"></i><div style="margin-top:8px;font-weight:600">My Performance</div></a>
      <a class="card card-pad" href="<?= url('modules/policy/index.php') ?>" style="text-align:center;color:inherit"><i class="fa-solid fa-book" style="font-size:24px;color:#10b981"></i><div style="margin-top:8px;font-weight:600">Company Policy</div></a>
    </div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px">My Recent Leave Requests</h3>
    <?php
    $myLeaves = fetch_all("SELECT lr.*, lt.name type_name, lt.color FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.employee_id=? ORDER BY lr.id DESC LIMIT 5", [$empId]);
    if(!$myLeaves): ?>
      <div class="empty"><i class="fa-solid fa-inbox"></i>No leave requests yet</div>
    <?php else: foreach($myLeaves as $l): ?>
      <div class="flex between center" style="padding:10px 0;border-bottom:1px solid var(--border)">
        <div>
          <div class="bold"><?= e($l['type_name']) ?></div>
          <div class="muted small"><?= format_date($l['start_date']) ?> → <?= format_date($l['end_date']) ?> · <?= $l['days'] ?> day(s)</div>
        </div>
        <span class="badge badge-<?= $l['status']==='approved'?'green':($l['status']==='rejected'?'red':'amber') ?>"><?= ucfirst($l['status']) ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ============ MANAGER DASHBOARD ============ -->
<?php elseif (current_role() === 'manager'): ?>
<div class="grid cols-4">
  <div class="stat"><div class="ico bg-primary"><i class="fa-solid fa-users"></i></div><div><div class="num"><?= $totalEmployees ?></div><div class="lbl">My Team Members</div></div></div>
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-user-check"></i></div><div><div class="num"><?= $presentToday ?></div><div class="lbl">Present Today</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="num"><?= $pendingLeaves ?></div><div class="lbl">Pending Leaves</div></div></div>
  <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-award"></i></div><div><div class="num"><?= $pendingReviews ?></div><div class="lbl">Reviews to Approve</div></div></div>
</div>

<div class="grid cols-2" style="margin-top:18px">
  <div class="card card-pad">
    <div class="flex between center" style="margin-bottom:12px">
      <h3 class="section-title">Pending Leave Approvals</h3>
      <a href="<?= url('modules/leave/approvals.php') ?>" class="small">View all →</a>
    </div>
    <div class="table-wrap">
      <table class="tbl">
        <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th>Action</th></tr></thead>
        <tbody>
        <?php
        $pend = fetch_all("SELECT lr.*, e.full_name, lt.name type_name FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.status='pending' AND e.manager_id=? ORDER BY lr.id DESC LIMIT 6", [$empId]);
        if(!$pend): echo '<tr><td colspan="4" class="empty">No pending approvals 🎉</td></tr>';
        else: foreach($pend as $l): ?>
          <tr>
            <td><?= e($l['full_name']) ?></td>
            <td><span class="badge badge-purple"><?= e($l['type_name']) ?></span></td>
            <td class="small"><?= format_date($l['start_date'],'d M') ?> - <?= format_date($l['end_date'],'d M') ?></td>
            <td><a href="<?= url('modules/leave/approvals.php') ?>" class="btn btn-sm btn-light">Review</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px">My Team</h3>
    <?php
    $team = fetch_all("SELECT e.id,e.full_name,e.designation,a.status astatus FROM employees e LEFT JOIN attendance a ON a.employee_id=e.id AND a.attendance_date=? WHERE e.manager_id=? AND e.status='Active' LIMIT 6", [$today,$empId]);
    foreach($team as $t): ?>
      <div class="flex between center" style="padding:9px 0;border-bottom:1px solid var(--border)">
        <div class="flex center gap">
          <div class="avatar sm"><?= e(initials($t['full_name'])) ?></div>
          <div><div class="bold small"><?= e($t['full_name']) ?></div><div class="muted small"><?= e($t['designation']) ?></div></div>
        </div>
        <span class="badge badge-<?= $t['astatus']?($t['astatus']==='present'?'green':($t['astatus']==='late'?'amber':'gray')):'gray' ?>"><?= $t['astatus']?ucfirst($t['astatus']):'Not marked' ?></span>
      </div>
    <?php endforeach; if(!$team) echo '<div class="empty"><i class="fa-solid fa-users-slash"></i>No team members assigned</div>'; ?>
  </div>
</div>

<!-- ============ HR / ADMIN DASHBOARD ============ -->
<?php else: ?>
<div class="grid cols-4">
  <div class="stat"><div class="ico bg-primary"><i class="fa-solid fa-users"></i></div><div><div class="num"><?= $totalEmployees ?></div><div class="lbl">Active Employees</div></div></div>
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-user-check"></i></div><div><div class="num"><?= $presentToday ?></div><div class="lbl">Present Today</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="num"><?= $pendingLeaves ?></div><div class="lbl">Pending Leaves</div></div></div>
  <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-briefcase"></i></div><div><div class="num"><?= $openJobs ?></div><div class="lbl">Open Positions</div></div></div>
</div>

<div class="grid cols-4" style="margin-top:18px">
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-user-plus"></i></div><div><div class="num"><?= $newApplicants ?></div><div class="lbl">New Applicants</div></div></div>
  <div class="stat"><div class="ico bg-accent"><i class="fa-solid fa-award"></i></div><div><div class="num"><?= $pendingReviews ?></div><div class="lbl">Pending Reviews</div></div></div>
  <div class="stat"><div class="ico bg-dark"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="num"><?= month_name($month).' '.$year ?></div><div class="lbl">Current Pay Period</div></div></div>
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-hand-holding-dollar"></i></div><div><div class="num"><?php
        $payrollRow = fetch_one("SELECT status FROM payroll_runs WHERE month=? AND year=?", [$month,$year]);
        echo $payrollRow ? ucfirst($payrollRow['status']) : 'Not started';
     ?></div><div class="lbl">Payroll Status</div></div></div>
</div>

<!-- ===== ADMIN QUICK ACTIONS ===== -->
<?php if (role_at_least('admin')): ?>
<div class="card card-pad" style="margin-top:18px;border-left:4px solid var(--purple)">
  <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-shield-halved"></i> Administrator Quick Actions</h3>
  <div class="grid cols-4">
    <a href="<?= url('modules/settings/users.php') ?>" class="card card-pad" style="text-align:center;color:inherit;transition:.2s">
      <i class="fa-solid fa-users-gear" style="font-size:28px;color:#7F3E98"></i>
      <div style="margin-top:10px;font-weight:700;font-family:Oswald">User Management</div>
      <div class="muted small">Create users & assign roles</div>
    </a>
    <a href="<?= url('modules/settings/index.php') ?>" class="card card-pad" style="text-align:center;color:inherit;transition:.2s">
      <i class="fa-solid fa-gear" style="font-size:28px;color:#9B59B6"></i>
      <div style="margin-top:10px;font-weight:700;font-family:Oswald">System Settings</div>
      <div class="muted small">WhatsApp, SMTP, Security</div>
    </a>
    <a href="<?= url('modules/employees/departments.php') ?>" class="card card-pad" style="text-align:center;color:inherit;transition:.2s">
      <i class="fa-solid fa-building" style="font-size:28px;color:#2A1B3D"></i>
      <div style="margin-top:10px;font-weight:700;font-family:Oswald">Departments</div>
      <div class="muted small">Manage departments</div>
    </a>
    <a href="<?= url('modules/settings/whatsapp.php') ?>" class="card card-pad" style="text-align:center;color:inherit;transition:.2s">
      <i class="fa-solid fa-tower-broadcast" style="font-size:28px;color:#25D366"></i>
      <div style="margin-top:10px;font-weight:700;font-family:Oswald">Broadcast</div>
      <div class="muted small">Send notifications</div>
    </a>
  </div>
</div>
<?php endif; ?>

<div class="grid cols-2" style="margin-top:18px">
  <div class="card card-pad">
    <div class="flex between center" style="margin-bottom:14px">
      <h3 class="section-title">Attendance Trend (7 days)</h3>
    </div>
    <canvas id="trendChart" height="180"></canvas>
    <div class="chart-legend">
      <span><i style="background:#2e3192"></i> Present</span>
      <span><i style="background:#f26223"></i> Absent</span>
    </div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px">Employees by Department</h3>
    <canvas id="deptChart" height="180"></canvas>
  </div>
</div>
<?php endif; ?>

<!-- recent activity (all roles) -->
<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px">Recent System Activity</h3>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Action</th><th>Details</th><th>When</th></tr></thead>
      <tbody>
      <?php if(!$activities): echo '<tr><td colspan="3" class="empty">No recent activity</td></tr>';
      else: foreach($activities as $a): ?>
        <tr><td class="bold"><?= e($a['action']) ?></td><td class="small muted"><?= e($a['details']) ?></td><td class="small"><?= time_ago($a['created_at']) ?></td></tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Clock-in modal -->
<div class="modal-bg" id="clockModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-fingerprint"></i> Mark Attendance</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('clockModal')"></i></div>
    <div class="modal-body">
      <div class="clock-widget">
        <div class="time" data-clock></div>
        <div class="date"><?= date('l, d F Y') ?></div>
        <p class="small" style="opacity:.8">Location + front camera selfie are required (photo is mandatory)</p>
      </div>
      <form method="post" action="<?= url('modules/attendance/clock.php') ?>" id="clockForm" style="margin-top:18px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="clockAction">
        <input type="hidden" name="location" id="clockLoc">
        <div class="flex gap">
          <a href="<?= url('modules/attendance/mark.php?auto=1') ?>" class="btn btn-primary btn-block"><i class="fa-solid fa-right-to-bracket"></i> Clock IN</a>
          <a href="<?= url('modules/attendance/mark.php?auto=1') ?>" class="btn btn-dark btn-block"><i class="fa-solid fa-right-from-bracket"></i> Clock OUT</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
liveClock('[data-clock]');
function setClock(act){
  document.getElementById('clockAction').value=act;
  getLocation(function(loc){ document.getElementById('clockLoc').value=loc||'location-unavailable'; });
}
<?php if (is_hr() && !empty($trendLabels)): ?>
new Chart(document.getElementById('trendChart'),{
  type:'bar',
  data:{labels:<?= $trendLabels ?>,
    datasets:[
      {label:'Present',data:<?= $trendPresent ?>,backgroundColor:'#2e3192',borderRadius:6},
      {label:'Absent',data:<?= $trendAbsent ?>,backgroundColor:'#f26223',borderRadius:6}
    ]},
  options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});
new Chart(document.getElementById('deptChart'),{
  type:'doughnut',
  data:{labels:<?= json_encode(array_column($deptDist,'name')) ?>,
    datasets:[{data:<?= json_encode(array_map(fn($r)=>(int)$r['cnt'],$deptDist)) ?>,
      backgroundColor:['#2e3192','#7d3ef2','#1e1e2d','#f26223','#10b981','#3b82f6','#f59e0b','#6c757d']}]},
  options:{responsive:true,plugins:{legend:{position:'bottom',labels:{font:{size:11}}}}}
});
<?php endif; ?>
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
