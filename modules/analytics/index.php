<?php
/**
 * ============================================================================
 * EPAD — Employee Performance Analytics Dashboard (Admin Only)
 * CEO/HR Director's command center: Attendance, Performance, Turnover,
 * Payroll, Leave Trends, Workforce Demographics — all in one view.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('EPAD Analytics', 'admin');

$year = (int)date('Y');

// ===== 1. WORKFORCE OVERVIEW =====
$totalActive = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE status='Active'")['c'];
$totalResigned = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE status IN('Resigned','Terminated')")['c'];
$maleCount = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE gender='Male' AND status='Active'")['c'];
$femaleCount = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE gender='Female' AND status='Active'")['c'];
$onProbation = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE employment_type='Probation' AND status='Active'")['c'];

// Average tenure
$avgTenureRow = fetch_one("SELECT AVG(julianday('now') - julianday(joining_date)) as avg_days FROM employees WHERE status='Active' AND joining_date IS NOT NULL");
$avgTenureMonths = $avgTenureRow ? round($avgTenureRow['avg_days'] / 30.44, 1) : 0;

// ===== 2. ATTENDANCE ANALYTICS (Last 30 days) =====
$attStats = fetch_one("SELECT 
    SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) as present,
    SUM(CASE WHEN status='late' THEN 1 ELSE 0 END) as late,
    SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) as absent,
    SUM(CASE WHEN status IN('casual_leave','medical_leave','compensated_leave','leave') THEN 1 ELSE 0 END) as on_leave,
    SUM(CASE WHEN overtime_hours > 0 THEN 1 ELSE 0 END) as ot_days,
    SUM(overtime_hours) as total_ot,
    SUM(undertime_hours) as total_ut
    FROM attendance WHERE attendance_date >= date('now','-30 days')");

// Department-wise late comers (last 30 days)
$deptLate = fetch_all("SELECT 
    COALESCE(d.name, 'Unassigned') as dept,
    COUNT(a.id) as late_count
    FROM attendance a
    JOIN employees e ON e.id = a.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE a.status = 'late' AND a.attendance_date >= date('now','-30 days')
    GROUP BY d.id ORDER BY late_count DESC LIMIT 10");

// ===== 3. PERFORMANCE (Top & Bottom) =====
$topPerformers = fetch_all("SELECT pr.employee_id, e.full_name, e.employee_code, d.name dept, 
    pr.overall_score, pr.rating, pr.review_period
    FROM performance_reviews pr 
    JOIN employees e ON e.id = pr.employee_id 
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE pr.overall_score > 0
    ORDER BY pr.overall_score DESC LIMIT 5");

$bottomPerformers = fetch_all("SELECT pr.employee_id, e.full_name, e.employee_code, d.name dept, 
    pr.overall_score, pr.rating
    FROM performance_reviews pr 
    JOIN employees e ON e.id = pr.employee_id 
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE pr.overall_score > 0
    ORDER BY pr.overall_score ASC LIMIT 5");

// ===== 4. ATTRITION / TURNOVER (Last 6 months) =====
$attrition = fetch_all("SELECT 
    strftime('%Y-%m', created_at) as month,
    COUNT(*) as count
    FROM separation_requests 
    WHERE status='completed' AND created_at >= date('now','-6 months')
    GROUP BY month ORDER BY month");

$totalResignations = array_sum(array_column($attrition, 'count'));

// Department-wise attrition
$deptAttrition = fetch_all("SELECT 
    COALESCE(d.name, 'Unassigned') as dept,
    COUNT(sr.id) as count
    FROM separation_requests sr
    JOIN employees e ON e.id = sr.employee_id
    LEFT JOIN departments d ON d.id = e.department_id
    WHERE sr.status='completed' AND sr.created_at >= date('now','-6 months')
    GROUP BY d.id ORDER BY count DESC");

// ===== 5. LEAVE TRENDS (Day of week) =====
$leaveTrends = fetch_one("SELECT
    SUM(CASE WHEN strftime('%w', start_date) = '0' THEN 1 ELSE 0 END) as sun,
    SUM(CASE WHEN strftime('%w', start_date) = '1' THEN 1 ELSE 0 END) as mon,
    SUM(CASE WHEN strftime('%w', start_date) = '2' THEN 1 ELSE 0 END) as tue,
    SUM(CASE WHEN strftime('%w', start_date) = '3' THEN 1 ELSE 0 END) as wed,
    SUM(CASE WHEN strftime('%w', start_date) = '4' THEN 1 ELSE 0 END) as thu,
    SUM(CASE WHEN strftime('%w', start_date) = '5' THEN 1 ELSE 0 END) as fri,
    SUM(CASE WHEN strftime('%w', start_date) = '6' THEN 1 ELSE 0 END) as sat
    FROM leave_requests WHERE start_date >= date('now','-90 days') AND status='approved'");

// ===== 6. PAYROLL & OVERTIME COST =====
$payrollStats = fetch_all("SELECT 
    pr.month, pr.year, pr.status,
    SUM(pi.net_pay) as total_net,
    SUM(pi.overtime_pay) as total_ot,
    SUM(pi.gross_pay) as total_gross,
    SUM(pi.total_deductions) as total_ded
    FROM payroll_runs pr 
    JOIN payroll_items pi ON pi.payroll_run_id = pr.id
    GROUP BY pr.month, pr.year ORDER BY pr.year DESC, pr.month DESC LIMIT 6");

// Department headcount
$deptHeadcount = fetch_all("SELECT 
    COALESCE(d.name, 'Unassigned') as name,
    COUNT(e.id) as count
    FROM departments d 
    LEFT JOIN employees e ON e.department_id=d.id AND e.status='Active'
    GROUP BY d.id ORDER BY count DESC");

$ratingColors = [
    'outstanding' => 'green', 'exceeds' => 'green',
    'meets' => 'blue', 'needs_improvement' => 'amber',
    'unsatisfactory' => 'red'
];
?>

<div class="page-head">
  <div>
    <h1><i class="fa-solid fa-chart-pie"></i> EPAD — Analytics Dashboard</h1>
    <div class="sub">Employee Performance &amp; Analytics Data — Real-time Company Overview</div>
  </div>
  <span class="badge badge-purple" style="font-size:14px;padding:8px 16px">Admin Only</span>
</div>

<!-- ===== STATS CARDS ===== -->
<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat"><div class="ico bg-primary"><i class="fa-solid fa-users"></i></div><div><div class="num"><?= $totalActive ?></div><div class="lbl">Active Employees</div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-user-clock"></i></div><div><div class="num"><?= $onProbation ?></div><div class="lbl">On Probation</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-calendar-times"></i></div><div><div class="num"><?= $avgTenureMonths ?> <span style="font-size:14px">mo</span></div><div class="lbl">Avg Tenure</div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-user-minus"></i></div><div><div class="num"><?= $totalResignations ?></div><div class="lbl">Resigned (6mo)</div></div></div>
</div>

<!-- ===== CHARTS ROW 1 ===== -->
<div class="grid cols-2" style="margin-bottom:18px">
  <!-- Attendance Overview (30 days) -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-clipboard-check"></i> Attendance (Last 30 Days)</h3>
    <canvas id="attChart" height="160"></canvas>
    <div class="chart-legend">
      <span><i style="background:#10b981"></i> Present (<?= (int)$attStats['present'] ?>)</span>
      <span><i style="background:#f59e0b"></i> Late (<?= (int)$attStats['late'] ?>)</span>
      <span><i style="background:#ef4444"></i> Absent (<?= (int)$attStats['absent'] ?>)</span>
      <span><i style="background:#3b82f6"></i> Leave (<?= (int)$attStats['on_leave'] ?>)</span>
    </div>
    <div class="divider"></div>
    <div class="flex between center small">
      <div><span class="muted">Total Overtime:</span> <strong style="color:#10b981"><?= round($attStats['total_ot'] ?? 0, 1) ?>h</strong></div>
      <div><span class="muted">Total Undertime:</span> <strong style="color:#f59e0b"><?= round($attStats['total_ut'] ?? 0, 1) ?>h</strong></div>
    </div>
  </div>

  <!-- Department Headcount -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-building"></i> Department Headcount</h3>
    <?php if (!$deptHeadcount || $totalActive == 0): ?>
      <div class="empty"><i class="fa-solid fa-building"></i><p>No data yet</p></div>
    <?php else: ?>
      <canvas id="deptChart" height="160"></canvas>
    <?php endif; ?>
  </div>
</div>

<!-- ===== CHARTS ROW 2 ===== -->
<div class="grid cols-2" style="margin-bottom:18px">
  <!-- Department Late Analysis -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-clock"></i> Late Arrivals by Department (30 days)</h3>
    <?php if (!$deptLate || $deptLate[0]['late_count'] == 0): ?>
      <div class="empty"><i class="fa-solid fa-check-double"></i><p>No late arrivals! Great discipline 🎉</p></div>
    <?php else: ?>
      <canvas id="lateChart" height="160"></canvas>
    <?php endif; ?>
  </div>

  <!-- Leave Trends by Day -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-calendar-day"></i> Leave Trends by Weekday (90 days)</h3>
    <canvas id="leaveChart" height="160"></canvas>
  </div>
</div>

<!-- ===== TOP & BOTTOM PERFORMERS ===== -->
<div class="grid cols-2" style="margin-bottom:18px">
  <!-- Top Performers -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-trophy" style="color:#f59e0b"></i> Top 5 Performers</h3>
    <?php if(!$topPerformers): ?>
      <div class="empty"><i class="fa-solid fa-trophy"></i><p>No performance reviews yet</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>#</th><th>Employee</th><th>Dept</th><th>Score</th><th>Rating</th></tr></thead>
      <tbody>
      <?php foreach($topPerformers as $i => $p): ?>
        <tr>
          <td><span class="badge badge-<?= $i===0?'amber':'gray' ?>">#<?= $i+1 ?></span></td>
          <td class="bold small"><?= e($p['full_name']) ?></td>
          <td class="small muted"><?= e($p['dept'] ?: '—') ?></td>
          <td class="bold" style="color:#10b981"><?= number_format($p['overall_score'], 0) ?>/100</td>
          <td><span class="badge badge-<?= $ratingColors[$p['rating']] ?? 'gray' ?>"><?= ucfirst(str_replace('_',' ',$p['rating'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- Needs Attention -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-triangle-exclamation" style="color:#ef4444"></i> Needs Improvement</h3>
    <?php if(!$bottomPerformers): ?>
      <div class="empty"><i class="fa-solid fa-triangle-exclamation"></i><p>No data yet</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Employee</th><th>Dept</th><th>Score</th><th>Rating</th></tr></thead>
      <tbody>
      <?php foreach($bottomPerformers as $p): ?>
        <tr>
          <td class="bold small"><?= e($p['full_name']) ?></td>
          <td class="small muted"><?= e($p['dept'] ?: '—') ?></td>
          <td class="bold" style="color:<?= $p['overall_score']<50?'#ef4444':'#f59e0b' ?>"><?= number_format($p['overall_score'], 0) ?>/100</td>
          <td><span class="badge badge-<?= $ratingColors[$p['rating']] ?? 'gray' ?>"><?= ucfirst(str_replace('_',' ',$p['rating'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== PAYROLL & ATTRITION ===== -->
<div class="grid cols-2">
  <!-- Payroll Summary -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-sack-dollar"></i> Payroll Summary (Recent)</h3>
    <?php if(!$payrollStats): ?>
      <div class="empty"><i class="fa-solid fa-sack-dollar"></i><p>No payroll processed yet</p></div>
    <?php else: ?>
    <div class="table-wrap"><table class="tbl">
      <thead><tr><th>Period</th><th>Gross</th><th>OT Pay</th><th>Deductions</th><th>Net Paid</th></tr></thead>
      <tbody>
      <?php foreach($payrollStats as $p): ?>
        <tr>
          <td class="bold small"><?= month_name($p['month']) . ' ' . $p['year'] ?></td>
          <td class="small"><?= money($p['total_gross']) ?></td>
          <td class="small" style="color:#10b981"><?= $p['total_ot']>0?money($p['total_ot']):'—' ?></td>
          <td class="small" style="color:#ef4444"><?= money($p['total_ded']) ?></td>
          <td class="bold"><?= money($p['total_net']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- Attrition Analysis -->
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-user-minus" style="color:#ef4444"></i> Turnover Analysis (6 months)</h3>
    <div class="grid cols-2" style="margin-bottom:14px">
      <div class="card card-pad" style="text-align:center;border:1px solid var(--border)">
        <div style="font-family:Oswald;font-size:28px;color:#ef4444"><?= $totalResignations ?></div>
        <div class="muted small">Total Exits</div>
      </div>
      <div class="card card-pad" style="text-align:center;border:1px solid var(--border)">
        <div style="font-family:Oswald;font-size:28px;color:#10b981"><?= $totalActive ?></div>
        <div class="muted small">Current Workforce</div>
      </div>
    </div>
    <?php if($deptAttrition): ?>
    <div class="muted small bold" style="margin-bottom:6px">Exits by Department:</div>
    <?php foreach($deptAttrition as $da): ?>
      <div class="flex between center" style="padding:4px 0">
        <span class="small"><?= e($da['dept']) ?></span>
        <span class="badge badge-red"><?= $da['count'] ?></span>
      </div>
    <?php endforeach; ?>
    <?php else: ?>
      <p class="muted small">No resignations in the last 6 months. Great retention! 🎉</p>
    <?php endif; ?>
  </div>
</div>

<!-- ===== GENDER DIVERSITY ===== -->
<?php if($totalActive > 0): ?>
<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px"><i class="fa-solid fa-venus-mars"></i> Workforce Demographics</h3>
  <div class="grid cols-3">
    <div style="text-align:center;padding:14px">
      <div style="font-family:Oswald;font-size:32px;color:#3b82f6"><?= $maleCount ?></div>
      <div class="muted small">Male (<?= $totalActive>0?round($maleCount/$totalActive*100):0 ?>%)</div>
    </div>
    <div style="text-align:center;padding:14px">
      <div style="font-family:Oswald;font-size:32px;color:#ef4444"><?= $femaleCount ?></div>
      <div class="muted small">Female (<?= $totalActive>0?round($femaleCount/$totalActive*100):0 ?>%)</div>
    </div>
    <div style="text-align:center;padding:14px">
      <div style="font-family:Oswald;font-size:32px;color:#6b7280"><?= $totalActive - $maleCount - $femaleCount ?></div>
      <div class="muted small">Other / Not Set</div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Attendance Donut
new Chart(document.getElementById('attChart'), {
  type: 'doughnut',
  data: {
    labels: ['Present','Late','Absent','On Leave'],
    datasets: [{
      data: [<?= (int)$attStats['present'] ?>, <?= (int)$attStats['late'] ?>, <?= (int)$attStats['absent'] ?>, <?= (int)$attStats['on_leave'] ?>],
      backgroundColor: ['#10b981','#f59e0b','#ef4444','#3b82f6']
    }]
  },
  options: {responsive:true, plugins:{legend:{display:false}}}
});

// Department Doughnut
<?php if($deptHeadcount && $totalActive > 0): ?>
new Chart(document.getElementById('deptChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($deptHeadcount, 'name')) ?>,
    datasets: [{
      data: <?= json_encode(array_map(fn($r)=>(int)$r['count'], $deptHeadcount)) ?>,
      backgroundColor: ['#7F3E98','#9B59B6','#2A1B3D','#F26223','#10b981','#3b82f6','#f59e0b','#6c757d']
    }]
  },
  options: {responsive:true, plugins:{legend:{position:'bottom',labels:{font:{size:11}}}}}
});
<?php endif; ?>

// Late by Department (Bar)
<?php if($deptLate && $deptLate[0]['late_count'] > 0): ?>
new Chart(document.getElementById('lateChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($deptLate, 'dept')) ?>,
    datasets: [{
      label: 'Late Arrivals',
      data: <?= json_encode(array_map(fn($r)=>(int)$r['late_count'], $deptLate)) ?>,
      backgroundColor: '#f59e0b',
      borderRadius: 6
    }]
  },
  options: {responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});
<?php endif; ?>

// Leave Trends (Bar)
new Chart(document.getElementById('leaveChart'), {
  type: 'bar',
  data: {
    labels: ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'],
    datasets: [{
      label: 'Approved Leaves',
      data: [<?= (int)$leaveTrends['sun'] ?>,<?= (int)$leaveTrends['mon'] ?>,<?= (int)$leaveTrends['tue'] ?>,<?= (int)$leaveTrends['wed'] ?>,<?= (int)$leaveTrends['thu'] ?>,<?= (int)$leaveTrends['fri'] ?>,<?= (int)$leaveTrends['sat'] ?>],
      backgroundColor: '#7F3E98',
      borderRadius: 6
    }]
  },
  options: {responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
