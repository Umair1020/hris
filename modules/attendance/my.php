<?php
/**
 * ============================================================================
 * MY ATTENDANCE — employee self-service view
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Attendance');
$empId = current_employee_id();
$year = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('m'));

$today = fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, today()]);

// monthly stats
$stats = fetch_one(
    "SELECT
        SUM(status='present') present,
        SUM(status='late') late,
        SUM(status='absent') absent,
        SUM(status='half_day') halfday,
        SUM(status='leave') onleave,
        SUM(work_hours) total_hours,
        SUM(overtime_hours) ot,
        SUM(undertime_hours) ut
     FROM attendance WHERE employee_id=? AND " . sql_month('attendance_date') . "=? AND " . sql_year('attendance_date') . "=?",
    [$empId, $month, $year]
);
$present = (int)($stats['present'] ?? 0); $late = (int)($stats['late'] ?? 0);
$absent = (int)($stats['absent'] ?? 0); $onleave = (int)($stats['onleave'] ?? 0);

// monthly grid
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$records = fetch_all("SELECT * FROM attendance WHERE employee_id=? AND " . sql_month('attendance_date') . "=? AND " . sql_year('attendance_date') . "=? ORDER BY attendance_date", [$empId, $month, $year]);
$recMap = [];
foreach ($records as $r) $recMap[$r['attendance_date']] = $r;
?>
<div class="page-head">
  <div>
    <h1>My Attendance</h1>
    <div class="sub">Track your daily clock-ins, hours, overtime and undertime</div>
  </div>
  <a href="<?= url('modules/attendance/mark.php?auto=1') ?>" class="btn btn-primary"><i class="fa-solid fa-fingerprint"></i> Mark Attendance</a>
</div>

<?php if ($today): ?>
<div class="card card-pad" style="margin-bottom:18px;background:var(--grad);color:#fff">
  <div class="flex between center wrap gap">
    <div>
      <div class="small" style="opacity:.8">TODAY · <?= date('l, d F Y') ?></div>
      <div style="font-family:'Oswald';font-size:20px;margin-top:4px">
        <i class="fa-solid fa-<?= $today['clock_out']?'door-open':'right-to-bracket' ?>"></i>
        <?= $today['clock_out'] ? 'Completed' : ($today['clock_in'] ? 'Currently Clocked In' : 'Not Marked') ?>
      </div>
    </div>
    <div class="flex gap">
      <div style="text-align:center"><div style="font-family:'Oswald';font-size:18px"><?= $today['clock_in']?format_datetime($today['clock_in'],'h:i A'):'--:--' ?></div><div class="small" style="opacity:.8">Clock In</div></div>
      <div style="text-align:center"><div style="font-family:'Oswald';font-size:18px"><?= $today['clock_out']?format_datetime($today['clock_out'],'h:i A'):'--:--' ?></div><div class="small" style="opacity:.8">Clock Out</div></div>
      <div style="text-align:center"><div style="font-family:'Oswald';font-size:18px"><?= $today['work_hours']?:'0' ?>h</div><div class="small" style="opacity:.8">Worked</div></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="grid cols-4">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-check"></i></div><div><div class="num"><?= $present ?></div><div class="lbl">Present</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-clock"></i></div><div><div class="num"><?= $late ?></div><div class="lbl">Late Arrivals</div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-xmark"></i></div><div><div class="num"><?= $absent ?></div><div class="lbl">Absent</div></div></div>
  <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-plane"></i></div><div><div class="num"><?= $onleave ?></div><div class="lbl">On Leave</div></div></div>
</div>

<div class="flex center gap" style="margin:20px 0 12px">
  <a href="?m=<?= $month==1?12:$month-1 ?>&y=<?= $month==1?$year-1:$year ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-chevron-left"></i></a>
  <h3 class="section-title"><?= month_name($month) . ' ' . $year ?></h3>
  <a href="?m=<?= $month==12?1:$month+1 ?>&y=<?= $month==12?$year+1:$year ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-chevron-right"></i></a>
</div>

<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Day</th><th>Clock In</th><th>Clock Out</th><th>Hours</th><th>OT</th><th>Under</th><th>Status</th></tr></thead>
      <tbody>
      <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $isFuture = strtotime($date) > strtotime(today());
        $rec = $recMap[$date] ?? null;
        if ($isFuture && !$rec) continue;
        $dow = date('N', strtotime($date));
        $weekend = $dow >= 6;
      ?>
        <tr <?= $weekend && !$rec ? 'style="background:#fafaff"' : '' ?>>
          <td class="bold"><?= format_date($date,'d M') ?></td>
          <td class="small muted"><?= date('D', strtotime($date)) ?></td>
          <td><?= $rec?format_datetime($rec['clock_in'],'h:i A'):'—' ?></td>
          <td><?= $rec?format_datetime($rec['clock_out'],'h:i A'):'—' ?></td>
          <td><?= $rec?$rec['work_hours'].'h':'—' ?></td>
          <td><?= $rec && $rec['overtime_hours']?'<span class="badge badge-green">'.$rec['overtime_hours'].'h</span>':'—' ?></td>
          <td><?= $rec && $rec['undertime_hours']?'<span class="badge badge-amber">'.$rec['undertime_hours'].'h</span>':'—' ?></td>
          <td>
            <?php if (!$rec): ?>
              <span class="badge badge-<?= $weekend?'gray':'red' ?>"><?= $weekend?'Off':'—' ?></span>
            <?php else: $s=$rec['status']; ?>
              <span class="badge badge-<?= $s==='present'?'green':($s==='late'?'amber':($s==='leave'?'blue':'red')) ?>"><?= ucfirst($s) ?></span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- clock modal -->
<div class="modal-bg" id="clockModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-fingerprint"></i> Mark Attendance</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('clockModal')"></i></div>
    <div class="modal-body">
      <div class="clock-widget"><div class="time" data-clock></div><div class="date"><?= date('l, d F Y') ?></div><p class="small" style="opacity:.8">Location is captured to confirm your arrival</p></div>
      <form method="post" action="<?= url('modules/attendance/clock.php') ?>" id="clockForm" style="margin-top:18px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="clockAction">
        <input type="hidden" name="location" id="clockLoc">
        <div class="flex gap">
          <button type="button" class="btn btn-primary btn-block" onclick="doClock('in')"><i class="fa-solid fa-right-to-bracket"></i> Clock IN</button>
          <button type="button" class="btn btn-dark btn-block" onclick="doClock('out')"><i class="fa-solid fa-right-from-bracket"></i> Clock OUT</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>liveClock('[data-clock]');</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
