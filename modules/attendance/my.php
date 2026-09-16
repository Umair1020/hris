<?php
/**
 * ============================================================================
 * MY ATTENDANCE — employee self-service view
 * Shows every day of the month: worked hours, Late (minutes), Absent,
 * Off Day, Public Holiday, Leave type, Half Day.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Attendance');
$empId = current_employee_id();
$year = (int)($_GET['y'] ?? date('Y'));
$month = (int)($_GET['m'] ?? date('m'));
if ($month < 1 || $month > 12) $month = (int)date('m');

att_sync_absents();

$emp = fetch_one("SELECT * FROM employees WHERE id=?", [$empId]);
$required = get_required_hours($emp);
$todayDate = today();
$today = att_find_open_shift($empId) ?: fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, $todayDate]);

// monthly grid
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$records = fetch_all("SELECT * FROM attendance WHERE employee_id=? AND " . sql_month('attendance_date') . "=? AND " . sql_year('attendance_date') . "=? ORDER BY attendance_date", [$empId, $month, $year]);
$recMap = [];
foreach ($records as $r) $recMap[$r['attendance_date']] = $r;

// Build rows + stats (virtual statuses included so Absent/Off/Holiday always show)
$rows = [];
$st = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'half' => 0, 'off' => 0, 'holiday' => 0, 'hours' => 0.0, 'ot' => 0.0, 'ut' => 0.0, 'late_min' => 0, 'working_days' => 0];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
    $rec = $recMap[$date] ?? null;
    $virtual = $rec ? null : att_expected_status($emp, $date);
    if (!$rec && $virtual === null && $date > $todayDate) {
        // future day: show as a placeholder (weekend/holiday label still useful)
        $kind = att_day_kind($emp, $date);
        $rows[] = ['date' => $date, 'rec' => null, 'virtual' => null, 'future' => true, 'kind' => $kind];
        continue;
    }
    $status = $rec ? $rec['status'] : $virtual;
    $group = $status && isset(att_statuses()[$status]) ? att_statuses()[$status][2] : 'pending';
    if ($group !== 'off') $st['working_days']++;
    if (in_array($status, att_present_statuses())) $st['present']++;
    if ($status === 'late' || ($rec && (int)$rec['late_minutes'] > 0)) { $st['late']++; $st['late_min'] += (int)($rec['late_minutes'] ?? 0); }
    if ($status === 'absent') $st['absent']++;
    if ($status === 'half_day') $st['half']++;
    if ($group === 'leave') $st['leave']++;
    if ($status === 'off_day') $st['off']++;
    if ($status === 'public_holiday') $st['holiday']++;
    if ($rec) { $st['hours'] += (float)$rec['work_hours']; $st['ot'] += (float)$rec['overtime_hours']; $st['ut'] += (float)$rec['undertime_hours']; }
    $rows[] = ['date' => $date, 'rec' => $rec, 'virtual' => $virtual, 'future' => false, 'kind' => att_day_kind($emp, $date)];
}
$prevM = $month == 1 ? 12 : $month - 1; $prevY = $month == 1 ? $year - 1 : $year;
$nextM = $month == 12 ? 1 : $month + 1; $nextY = $month == 12 ? $year + 1 : $year;
?>
<div class="page-head">
  <div>
    <h1>My Attendance</h1>
    <div class="sub">Shift <?= date('h:i A', strtotime(att_shift_start($emp))) ?> – <?= date('h:i A', strtotime($emp['shift_end'] ?: '18:00')) ?> · Required <?= fmt_hours($required) ?>/day · Grace <?= att_grace_minutes() ?> min · Weekly off: <?= e($emp['weekly_off'] ?: 'Sunday') ?></div>
  </div>
  <div class="flex gap">
    <a href="<?= url('modules/leave/my.php?action=new') ?>" class="btn btn-light"><i class="fa-solid fa-calendar-plus"></i> Apply Leave / Half Day</a>
    <a href="<?= url('modules/attendance/mark.php?auto=1') ?>" class="btn btn-primary"><i class="fa-solid fa-fingerprint"></i> Mark Attendance</a>
  </div>
</div>

<?php
$todayKind = att_day_kind($emp, $todayDate);
if ($today || $todayKind !== 'work'): ?>
<div class="card card-pad" style="margin-bottom:18px;background:var(--grad);color:#fff">
  <div class="flex between center wrap gap">
    <div>
      <div class="small" style="opacity:.8">TODAY · <?= date('l, d F Y') ?></div>
      <div style="font-family:'Oswald';font-size:20px;margin-top:4px">
        <?php if ($today && $today['clock_out']): ?>
          <i class="fa-solid fa-door-open"></i> Completed · <?= e(att_label($today['status'])) ?>
        <?php elseif ($today && $today['clock_in']): ?>
          <i class="fa-solid fa-right-to-bracket"></i> Currently Clocked In<?= (int)$today['late_minutes'] > 0 ? ' · Late by ' . fmt_hours($today['late_minutes']/60) : ' · On Time' ?>
        <?php elseif ($today): ?>
          <i class="fa-solid fa-calendar-day"></i> <?= e(att_label($today['status'])) ?>
        <?php elseif ($todayKind === 'holiday'): ?>
          <i class="fa-solid fa-umbrella-beach"></i> Public Holiday · <?= e(att_holiday($todayDate)) ?>
        <?php else: ?>
          <i class="fa-solid fa-mug-hot"></i> Weekly Off Day
        <?php endif; ?>
      </div>
      <?php if ($today && $today['clock_in'] && !$today['clock_out']):
        $sofar = calc_hours($today['clock_in'], now()); $left = max(0, $required - $sofar); ?>
        <div class="small" style="opacity:.85;margin-top:4px">Worked so far <?= fmt_hours($sofar, false) ?> · <?= $left > 0 ? fmt_hours($left) . ' remaining to complete ' . fmt_hours($required) : 'Required hours completed ✔' ?></div>
      <?php endif; ?>
    </div>
    <?php if ($today): ?>
    <div class="flex gap">
      <div style="text-align:center"><div style="font-family:'Oswald';font-size:18px"><?= $today['clock_in']?format_datetime($today['clock_in'],'h:i A'):'--:--' ?></div><div class="small" style="opacity:.8">Clock In</div></div>
      <div style="text-align:center"><div style="font-family:'Oswald';font-size:18px"><?= $today['clock_out']?format_datetime($today['clock_out'],'h:i A'):'--:--' ?></div><div class="small" style="opacity:.8">Clock Out</div></div>
      <div style="text-align:center"><div style="font-family:'Oswald';font-size:18px"><?= fmt_hours($today['work_hours'], false) ?></div><div class="small" style="opacity:.8">Worked</div></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="grid cols-4">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-check"></i></div><div><div class="num"><?= $st['present'] ?><span class="muted" style="font-size:13px">/<?= $st['working_days'] ?></span></div><div class="lbl">Present days · <?= fmt_hours($st['hours'], false) ?> worked</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-clock"></i></div><div><div class="num"><?= $st['late'] ?></div><div class="lbl">Late arrivals · <?= fmt_hours($st['late_min']/60, false) ?> total</div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-xmark"></i></div><div><div class="num"><?= $st['absent'] ?></div><div class="lbl">Absent · <?= $st['half'] ?> Half Day · UT <?= fmt_hours($st['ut'], false) ?></div></div></div>
  <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-plane"></i></div><div><div class="num"><?= $st['leave'] ?></div><div class="lbl">On Leave · <?= $st['off'] + $st['holiday'] ?> Off/Holiday · OT <?= fmt_hours($st['ot'], false) ?></div></div></div>
</div>

<div class="flex center gap" style="margin:20px 0 12px">
  <a href="?m=<?= $prevM ?>&y=<?= $prevY ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-chevron-left"></i></a>
  <h3 class="section-title"><?= month_name($month) . ' ' . $year ?></h3>
  <a href="?m=<?= $nextM ?>&y=<?= $nextY ?>" class="btn btn-light btn-sm"><i class="fa-solid fa-chevron-right"></i></a>
</div>

<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Date</th><th>Day</th><th>Clock In</th><th>Clock Out</th><th>Worked</th><th>OT</th><th>Under</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row):
        $date = $row['date']; $rec = $row['rec'];
        $dow = date('N', strtotime($date));
        $isOff = $row['kind'] !== 'work';
        $isToday = $date === $todayDate;
        $style = $isToday ? 'background:#f4f0f8' : ($isOff && !$rec ? 'background:#fafaff;opacity:.8' : (($rec && $rec['status'] === 'absent') || $row['virtual'] === 'absent' ? 'background:#fff5f5' : ''));
      ?>
        <tr style="<?= $style ?>">
          <td class="bold" nowrap><?= format_date($date,'d M') ?><?= $isToday ? ' <span class="badge badge-purple">Today</span>' : '' ?></td>
          <td class="small <?= $dow >= 6 ? 'bold' : 'muted' ?>"><?= date('D', strtotime($date)) ?></td>
          <?php if ($row['future']): ?>
            <td colspan="5" class="muted small"><?= $row['kind'] === 'holiday' ? '<i class="fa-solid fa-umbrella-beach"></i> ' . e(att_holiday($date)) : ($row['kind'] === 'off' ? 'Weekly off' : '') ?></td>
            <td><?= $row['kind'] === 'holiday' ? '<span class="badge badge-blue">Public Holiday</span>' : ($row['kind'] === 'off' ? '<span class="badge badge-gray">Off Day</span>' : '<span class="muted small">—</span>') ?></td>
          <?php elseif ($rec && ($rec['clock_in'] || $rec['clock_out'])): ?>
            <td nowrap><?= $rec['clock_in'] ? format_datetime($rec['clock_in'],'h:i A') : '—' ?><?= (int)$rec['late_minutes'] > 0 ? '<div class="small" style="color:#b9760a">+' . fmt_hours($rec['late_minutes']/60) . ' late</div>' : '' ?></td>
            <td nowrap><?= $rec['clock_out'] ? format_datetime($rec['clock_out'],'h:i A') : '<span class="muted">—</span>' ?><?= $rec['clock_out'] && substr($rec['clock_out'],0,10) !== $date ? '<div class="small muted">next day</div>' : '' ?></td>
            <td nowrap><span class="bold"><?= fmt_hours($rec['work_hours']) ?></span><?= $rec['work_hours'] > 0 ? '<div class="small muted">of ' . fmt_hours($required) . '</div>' : '' ?></td>
            <td><?= $rec['overtime_hours'] > 0 ? '<span class="badge badge-green">+' . fmt_hours($rec['overtime_hours']) . '</span>' : '—' ?></td>
            <td><?= $rec['undertime_hours'] > 0 ? '<span class="badge badge-amber">-' . fmt_hours($rec['undertime_hours']) . '</span>' : ($rec['clock_out'] ? '<span class="badge badge-green"><i class="fa-solid fa-check"></i></span>' : '—') ?></td>
            <td nowrap><?= att_badge($rec) ?></td>
          <?php elseif ($rec): ?>
            <td colspan="5" class="muted small"><?= $rec['notes'] ? e($rec['notes']) : '—' ?></td>
            <td nowrap><?= att_badge($rec) ?></td>
          <?php else: ?>
            <td colspan="5" class="muted small"><?= $row['virtual'] === 'not_marked' ? 'Not clocked in yet' : ($row['virtual'] === 'public_holiday' ? '<i class="fa-solid fa-umbrella-beach"></i> ' . e(att_holiday($date)) : ($row['virtual'] === 'off_day' ? 'Weekly off' : 'No attendance marked')) ?></td>
            <td nowrap><?= att_virtual_badge($row['virtual'], $date) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="muted small" style="margin-top:10px"><i class="fa-solid fa-circle-info"></i> Late = clock-in after shift start + grace. Hours Completed = required hours done. Undertime = required hours not completed. Missing days are Absent automatically — ask HR to regularize if you forgot to mark.</div>
</div>

<!-- clock modal -->
<div class="modal-bg" id="clockModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-fingerprint"></i> Mark Attendance</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('clockModal')"></i></div>
    <div class="modal-body">
      <div class="clock-widget"><div class="time" data-clock></div><div class="date"><?= date('l, d F Y') ?></div><p class="small" style="opacity:.8">Location + front camera selfie are required (photo is mandatory)</p></div>
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
<script>liveClock('[data-clock]');</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
