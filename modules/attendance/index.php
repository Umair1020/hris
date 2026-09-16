<?php
/**
 * ============================================================================
 * ATTENDANCE MANAGEMENT — HR & Managers
 * Full attendance sheet: every employee × every day in the range, including
 * Absent (no record), Off Day, Public Holiday, Late (with minutes), worked hours.
 * Regularization, Manual Entry, Bulk Holiday/Off marking, ID Card scan.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('manager');
$role = current_role();

// Handle regularization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_hr()) {
    verify_csrf();
    if (($_POST['att_action'] ?? '') === 'regularize') {
        $regEmpId = (int)$_POST['employee_id'];
        $regDate = clean($_POST['reg_date']);
        $regNote = clean($_POST['reg_note']);
        $monthLimit = (int)get_setting('regularize_monthly_limit', '1');

        $monthStart = date('Y-m-01', strtotime($regDate));
        $monthEnd = date('Y-m-t', strtotime($regDate));
        $regCount = (int)fetch_one("SELECT COUNT(*) c FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ? AND is_regularized=1", [$regEmpId, $monthStart, $monthEnd])['c'];

        if ($regCount >= $monthLimit) {
            set_flash('danger', "Regularization limit reached ($monthLimit/month). Already regularized $regCount time(s) this month.");
        } else {
            $emp = fetch_one("SELECT * FROM employees WHERE id=?", [$regEmpId]);
            $shiftStart = att_shift_start($emp);
            $shiftEnd = $emp && $emp['shift_end'] ? substr($emp['shift_end'], 0, 5) : '18:00';
            [$cin, $cout] = att_manual_times($regDate, $shiftStart, $shiftEnd);
            $req = get_required_hours($emp);
            $data = ['clock_in' => $cin, 'clock_out' => $cout, 'status' => 'hours_completed', 'work_hours' => $req, 'overtime_hours' => 0, 'undertime_hours' => 0, 'late_minutes' => 0, 'is_regularized' => 1, 'regularized_by' => current_user_id(), 'regularized_note' => $regNote];
            $existing = fetch_one("SELECT id FROM attendance WHERE employee_id=? AND attendance_date=?", [$regEmpId, $regDate]);
            if ($existing) update('attendance', $data, 'id=?', [$existing['id']]);
            else insert('attendance', array_merge(['employee_id' => $regEmpId, 'attendance_date' => $regDate, 'clock_in_method' => 'manual'], $data));
            log_activity('Attendance Regularized', "Employee #$regEmpId on $regDate: $regNote");
            set_flash('success', "Attendance regularized for $regDate.");
        }
        redirect(APP_URL . 'modules/attendance/index.php?' . http_build_query(['from' => $regDate, 'to' => $regDate]));
    }
}

// Keep the sheet complete: mark past working days without any record as Absent
att_sync_absents();

auth_header('Attendance Management', 'manager');

// Filters: Date Range & Employee & Status
$empId = (int)($_GET['emp'] ?? 0);
$fromDate = clean($_GET['from'] ?? '');
$toDate = clean($_GET['to'] ?? '');
$statusFilter = clean($_GET['status'] ?? '');

if (!$fromDate && !$toDate) {
    $fromDate = clean($_GET['d'] ?? today());
    $toDate = $fromDate;
} elseif ($fromDate && !$toDate) {
    $toDate = $fromDate;
} elseif (!$fromDate && $toDate) {
    $fromDate = $toDate;
}
if ($toDate < $fromDate) { $t = $fromDate; $fromDate = $toDate; $toDate = $t; }
// Safety: cap range to 62 days for the full sheet
if ((strtotime($toDate) - strtotime($fromDate)) / 86400 > 62) $toDate = date('Y-m-d', strtotime($fromDate . ' +62 days'));

// Employees in scope (managers → their team)
$empWhere = "e.status='Active'";
$empParams = [];
if ($role === 'manager') { $empWhere .= " AND e.manager_id = ?"; $empParams[] = current_employee_id(); }
if ($empId > 0) { $empWhere .= " AND e.id = ?"; $empParams[] = $empId; }
$employees = fetch_all("SELECT e.*, d.name dept FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE $empWhere ORDER BY e.full_name", $empParams);
$empIds = array_column($employees, 'id');

// Records in range (also inactive employees who have records, when not filtering by employee)
$records = [];
if ($empIds) {
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $records = fetch_all(
        "SELECT a.* FROM attendance a WHERE a.attendance_date BETWEEN ? AND ? AND a.employee_id IN ($in)",
        array_merge([$fromDate, $toDate], $empIds)
    );
}
$recMap = [];
foreach ($records as $r) $recMap[$r['employee_id']][$r['attendance_date']] = $r;

// Build the full sheet: every day × every employee
$days = [];
for ($d = $fromDate; $d <= $toDate; $d = date('Y-m-d', strtotime($d . ' +1 day'))) $days[] = $d;
$today = today();

$rows = [];
$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'off' => 0, 'not_marked' => 0, 'half_day' => 0, 'hours' => 0.0, 'ot' => 0.0, 'ut' => 0.0];
foreach ($days as $d) {
    foreach ($employees as $emp) {
        $rec = $recMap[$emp['id']][$d] ?? null;
        $virtual = null;
        if (!$rec) {
            $virtual = att_expected_status($emp, $d);
            if ($virtual === null) continue; // future day / before joining
        }
        $status = $rec ? $rec['status'] : $virtual;
        $group = $rec ? (att_statuses()[$status][2] ?? 'work') : ($virtual === 'not_marked' ? 'pending' : att_statuses()[$virtual][2]);

        // status filter
        if ($statusFilter) {
            $match = false;
            if ($statusFilter === 'late') $match = ($status === 'late' || ($rec && (int)$rec['late_minutes'] > 0));
            elseif ($statusFilter === 'present') $match = in_array($status, att_present_statuses());
            elseif ($statusFilter === 'leave') $match = ($group === 'leave');
            elseif ($statusFilter === 'off') $match = ($group === 'off');
            elseif ($statusFilter === 'undertime') $match = ($rec && (float)$rec['undertime_hours'] > 0);
            elseif ($statusFilter === 'overtime') $match = ($rec && (float)$rec['overtime_hours'] > 0);
            else $match = ($status === $statusFilter);
            if (!$match) continue;
        }

        if (in_array($status, att_present_statuses())) $counts['present']++;
        if ($status === 'late' || ($rec && (int)$rec['late_minutes'] > 0)) $counts['late']++;
        if ($status === 'absent') $counts['absent']++;
        if ($status === 'half_day') $counts['half_day']++;
        if ($group === 'leave') $counts['leave']++;
        if ($group === 'off') $counts['off']++;
        if ($status === 'not_marked') $counts['not_marked']++;
        if ($rec) { $counts['hours'] += (float)$rec['work_hours']; $counts['ot'] += (float)$rec['overtime_hours']; $counts['ut'] += (float)$rec['undertime_hours']; }

        $rows[] = ['date' => $d, 'emp' => $emp, 'rec' => $rec, 'virtual' => $virtual, 'status' => $status];
    }
}
// newest first
$rows = array_reverse($rows);

$allEmployees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");
$isRange = ($fromDate !== $toDate);
$holidays = fetch_all("SELECT * FROM holidays ORDER BY holiday_date DESC LIMIT 60");
$colspan = is_hr() ? 11 : 10;
$qs = http_build_query(['from' => $fromDate, 'to' => $toDate, 'emp' => $empId]);
?>
<div class="page-head">
  <div>
    <h1>Attendance Management</h1>
    <div class="sub"><?= $isRange ? "Attendance Sheet: " . format_date($fromDate) . " to " . format_date($toDate) : "Daily Sheet: " . format_date($fromDate) . ' (' . date('l', strtotime($fromDate)) . ')' ?><?php if ($h = att_holiday($fromDate)) echo ' · <span class="badge badge-blue">Public Holiday: ' . e($h) . '</span>'; ?></div>
  </div>
  <div class="flex gap wrap">
    <?php if (is_hr()): ?>
      <button class="btn btn-outline" onclick="openModal('cardModal')"><i class="fa-solid fa-id-card"></i> Scan ID</button>
      <button class="btn btn-outline" onclick="openModal('holidayModal')"><i class="fa-solid fa-umbrella-beach"></i> Holidays / Off Day</button>
      <button class="btn btn-primary" onclick="openModal('manualModal')"><i class="fa-solid fa-plus"></i> Manual Entry</button>
    <?php endif; ?>
  </div>
</div>

<!-- Filter Bar -->
<div class="card card-pad" style="margin-bottom:18px">
  <form method="get" class="flex center gap wrap">
    <div>
      <label style="margin:0 4px 4px 0; font-size:11px">Employee</label>
      <select name="emp" class="form-select" style="width:auto;min-width:200px">
        <option value="0">All Employees</option>
        <?php foreach($allEmployees as $emp): ?>
          <option value="<?= $emp['id'] ?>" <?= $empId == $emp['id'] ? 'selected' : '' ?>><?= e($emp['employee_code'] . ' — ' . $emp['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="margin:0 4px 4px 0; font-size:11px">From Date</label>
      <input type="date" name="from" class="form-control" value="<?= e($fromDate) ?>">
    </div>
    <div>
      <label style="margin:0 4px 4px 0; font-size:11px">To Date</label>
      <input type="date" name="to" class="form-control" value="<?= e($toDate) ?>">
    </div>
    <div>
      <label style="margin:0 4px 4px 0; font-size:11px">Status</label>
      <select name="status" class="form-select" style="width:auto">
        <option value="">All Statuses</option>
        <option value="present" <?= $statusFilter==='present'?'selected':'' ?>>Present (any)</option>
        <option value="late" <?= $statusFilter==='late'?'selected':'' ?>>Late</option>
        <option value="hours_completed" <?= $statusFilter==='hours_completed'?'selected':'' ?>>Hours Completed</option>
        <option value="undertime" <?= $statusFilter==='undertime'?'selected':'' ?>>Undertime</option>
        <option value="overtime" <?= $statusFilter==='overtime'?'selected':'' ?>>Overtime</option>
        <option value="half_day" <?= $statusFilter==='half_day'?'selected':'' ?>>Half Day</option>
        <option value="absent" <?= $statusFilter==='absent'?'selected':'' ?>>Absent</option>
        <option value="not_marked" <?= $statusFilter==='not_marked'?'selected':'' ?>>Not Marked (today)</option>
        <option value="leave" <?= $statusFilter==='leave'?'selected':'' ?>>On Leave (any)</option>
        <option value="off" <?= $statusFilter==='off'?'selected':'' ?>>Off Day / Holiday</option>
      </select>
    </div>
    <div style="display:flex; gap:8px; align-self:flex-end">
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
      <a href="?from=<?= today() ?>&to=<?= today() ?>" class="btn btn-light">Today</a>
      <a href="?from=<?= date('Y-m-01') ?>&to=<?= today() ?>&emp=<?= $empId ?>" class="btn btn-light">This Month</a>
      <?php if (is_hr()): ?>
        <a href="<?= url("modules/attendance/export.php?$qs&status=" . urlencode($statusFilter)) ?>" class="btn btn-success"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="grid cols-4" style="margin-bottom:18px">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-user-check"></i></div><div><div class="num"><?= $counts['present'] ?></div><div class="lbl">Present<?= $isRange ? ' (days)' : '' ?> · <?= fmt_hours($counts['hours'], false) ?> worked</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-clock"></i></div><div><div class="num"><?= $counts['late'] ?></div><div class="lbl">Late · <?= $counts['half_day'] ?> Half Day · UT <?= fmt_hours($counts['ut'], false) ?></div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-user-xmark"></i></div><div><div class="num"><?= $counts['absent'] ?></div><div class="lbl">Absent<?= $counts['not_marked'] ? ' · ' . $counts['not_marked'] . ' not marked yet' : '' ?></div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-plane"></i></div><div><div class="num"><?= $counts['leave'] ?></div><div class="lbl">On Leave · <?= $counts['off'] ?> Off/Holiday · OT <?= fmt_hours($counts['ot'], false) ?></div></div></div>
</div>

<div class="card card-pad">
  <div class="flex between center wrap gap" style="margin-bottom:12px">
    <h3 class="section-title">Attendance Sheet <span class="muted small">(<?= count($rows) ?> rows · <?= count($employees) ?> employees)</span></h3>
    <?php if (is_hr()): ?>
    <form method="post" action="manual.php" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="sync_absent"><input type="hidden" name="back" value="<?= e(current_url()) ?>">
      <button class="btn btn-sm btn-light" title="Mark every past working day without a record as Absent"><i class="fa-solid fa-rotate"></i> Sync Absents</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Date</th>
          <th>Day</th>
          <th>Employee</th>
          <th>Shift</th>
          <th>Clock In</th>
          <th>Clock Out</th>
          <th>Worked</th>
          <th>Overtime</th>
          <th>Undertime</th>
          <th>Status</th>
          <?php if(is_hr()) echo '<th>Action</th>' ?>
        </tr>
      </thead>
      <tbody>
      <?php if(!$rows): ?>
        <tr><td colspan="<?= $colspan ?>" class="empty"><i class="fa-solid fa-clipboard"></i>No records found for the selected filters.</td></tr>
      <?php endif;

      foreach ($rows as $row):
        $r = $row['rec']; $emp = $row['emp']; $d = $row['date'];
        $dow = date('N', strtotime($d));
        $isOffRow = !$r && in_array($row['virtual'], ['off_day', 'public_holiday']);
        $rowStyle = $isOffRow ? 'background:#f7f7fb;opacity:.85' : ($row['status'] === 'absent' ? 'background:#fff5f5' : ($row['status'] === 'not_marked' ? 'opacity:.7' : ''));
        $shift = date('h:i A', strtotime(att_shift_start($emp))) . ' – ' . date('h:i A', strtotime($emp['shift_end'] ?: '18:00'));
        $required = get_required_hours($emp);
      ?>
        <tr style="<?= $rowStyle ?>">
          <td class="bold small" nowrap><?= format_date($d,'d M Y') ?></td>
          <td class="small <?= $dow >= 6 ? 'bold' : 'muted' ?>" nowrap><?= date('D', strtotime($d)) ?></td>
          <td><div class="flex center gap"><div class="avatar sm" <?= $isOffRow ? 'style="background:#adb1c5"' : '' ?>><?= e(initials($emp['full_name'])) ?></div><div><div class="bold"><?= e($emp['full_name']) ?></div><div class="muted small"><?= e($emp['employee_code']) ?><?= $emp['dept'] ? ' · ' . e($emp['dept']) : '' ?></div></div></div></td>
          <td class="small muted" nowrap><?= $shift ?><div class="small">Req <?= fmt_hours($required) ?></div></td>
          <?php if ($r && ($r['clock_in'] || $r['clock_out'])): ?>
          <td nowrap>
            <div class="bold"><?= $r['clock_in'] ? format_datetime($r['clock_in'],'h:i A') : '—' ?></div>
            <?php if((int)$r['late_minutes'] > 0): ?><div class="small" style="color:#b9760a"><i class="fa-solid fa-clock"></i> +<?= fmt_hours($r['late_minutes']/60) ?> late</div><?php endif; ?>
            <?php if($r['clock_in_location'] && $r['clock_in_location'] !== 'location-unavailable' && strpos($r['clock_in_location'], 'http') === 0): ?>
              <a href="<?= e($r['clock_in_location']) ?>" target="_blank" class="badge badge-green" title="Clock IN location"><i class="fa-solid fa-location-dot"></i> Map</a>
            <?php elseif($r['clock_in_location']): ?>
              <span class="badge badge-gray" title="<?= e($r['clock_in_location']) ?>"><i class="fa-solid fa-location-dot"></i></span>
            <?php endif; ?>
            <?php if(!empty($r['clock_in_selfie'])): ?>
              <a href="<?= asset('uploads/' . $r['clock_in_selfie']) ?>" target="_blank" class="badge badge-purple" title="Selfie"><i class="fa-solid fa-camera"></i></a>
            <?php endif; ?>
          </td>
          <td nowrap>
            <div class="bold"><?= $r['clock_out'] ? format_datetime($r['clock_out'],'h:i A') : '<span class="muted">—</span>' ?></div>
            <?php if($r['clock_out'] && substr($r['clock_out'],0,10) !== $d): ?><div class="small muted">next day</div><?php endif; ?>
            <?php if($r['clock_out_location'] && $r['clock_out_location'] !== 'location-unavailable' && strpos($r['clock_out_location'], 'http') === 0): ?>
              <a href="<?= e($r['clock_out_location']) ?>" target="_blank" class="badge badge-amber" title="Clock OUT location"><i class="fa-solid fa-location-dot"></i> Map</a>
            <?php elseif($r['clock_out_location']): ?>
              <span class="badge badge-gray" title="<?= e($r['clock_out_location']) ?>"><i class="fa-solid fa-location-dot"></i></span>
            <?php endif; ?>
            <?php if(!empty($r['clock_out_selfie'])): ?>
              <a href="<?= asset('uploads/' . $r['clock_out_selfie']) ?>" target="_blank" class="badge badge-purple" title="Selfie"><i class="fa-solid fa-camera"></i></a>
            <?php endif; ?>
          </td>
          <td nowrap><span class="bold"><?= fmt_hours($r['work_hours']) ?></span><?php if($r['work_hours'] > 0): ?><div class="small muted">of <?= fmt_hours($required) ?></div><?php endif; ?></td>
          <td><?= ($r['overtime_hours'] > 0) ? '<span class="badge badge-green">+' . fmt_hours($r['overtime_hours']) . '</span>' : '—' ?></td>
          <td><?= ($r['undertime_hours'] > 0) ? '<span class="badge badge-amber">-' . fmt_hours($r['undertime_hours']) . '</span>' : ($r['clock_out'] ? '<span class="badge badge-green"><i class="fa-solid fa-check"></i></span>' : '—') ?></td>
          <?php elseif ($r): ?>
          <td colspan="5" class="muted small"><?= $r['notes'] ? e($r['notes']) : '— No clock time —' ?></td>
          <?php else: ?>
          <td colspan="5" class="muted small"><?= $row['virtual'] === 'not_marked' ? '— Not clocked in yet —' : ($row['virtual'] === 'public_holiday' ? '— ' . e(att_holiday($d)) . ' —' : ($row['virtual'] === 'off_day' ? '— Weekly Off —' : '— No attendance marked —')) ?></td>
          <?php endif; ?>
          <td nowrap><?= $r ? att_badge($r) : att_virtual_badge($row['virtual'], $d) ?></td>
          <?php if(is_hr()): ?>
          <td nowrap>
            <?php if($r && $r['is_regularized']): ?>
              <span class="badge badge-blue" title="<?= e($r['regularized_note']) ?>"><i class="fa-solid fa-check-double"></i></span>
            <?php endif; ?>
            <button class="btn btn-sm btn-light" onclick="openEdit(<?= $emp['id'] ?>,'<?= e($d) ?>','<?= e($r['status'] ?? ($row['virtual'] === 'not_marked' ? 'present' : $row['virtual'])) ?>','<?= $r && $r['clock_in'] ? substr($r['clock_in'],11,5) : att_shift_start($emp) ?>','<?= $r && $r['clock_out'] ? substr($r['clock_out'],11,5) : substr($emp['shift_end'] ?: '18:00',0,5) ?>')" title="Edit / Mark"><i class="fa-solid fa-pen"></i></button>
            <?php if (!$isOffRow): ?>
            <button class="btn btn-sm btn-light" onclick="openReg(<?= $emp['id'] ?>,'<?= e($emp['full_name']) ?>','<?= e($d) ?>')" title="Regularize (full shift credit)"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
            <?php endif; ?>
            <?php if($r): ?>
            <form method="post" action="manual.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <input type="hidden" name="back" value="<?= e(current_url()) ?>">
              <button class="btn btn-sm btn-light" data-confirm="Delete this attendance record?"><i class="fa-solid fa-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="muted small" style="margin-top:10px">
    <i class="fa-solid fa-circle-info"></i> Late = clock-in after shift start + <?= att_grace_minutes() ?> min grace. Required hours come from each employee's shift.
    Days without any record are automatically shown as <span class="badge badge-red">Absent</span> (past working days), <span class="badge badge-gray">Off Day</span> (weekly off) or <span class="badge badge-blue">Public Holiday</span>.
  </div>
</div>

<!-- Manual entry modal -->
<?php if (is_hr()): ?>
<div class="modal-bg" id="manualModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Manual Attendance Entry</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('manualModal')"></i></div>
    <form method="post" action="manual.php">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="back" value="<?= e(current_url()) ?>">
        <div style="margin-bottom:14px"><label>Employee</label>
          <select name="employee_id" id="mEmp" class="form-select" required>
            <?php foreach ($allEmployees as $emp): ?>
              <option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'].' — '.$emp['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex gap" style="margin-bottom:14px">
          <div style="flex:1"><label>Date</label><input type="date" name="attendance_date" id="mDate" class="form-control" value="<?= e($fromDate) ?>" required></div>
          <div style="flex:1"><label>Status</label>
            <select name="status" id="mStatus" class="form-select" onchange="toggleTimes()">
              <optgroup label="Working">
                <option value="present">Present (auto-detects Late / Hours Completed)</option>
                <option value="late">Late</option>
                <option value="hours_completed">Hours Completed</option>
                <option value="half_day">Half Day</option>
                <option value="work_from_home">Work From Home</option>
                <option value="official_duty">Official Duty / Field</option>
              </optgroup>
              <optgroup label="Leave">
                <option value="casual_leave">Casual Leave</option>
                <option value="medical_leave">Medical Leave</option>
                <option value="annual_leave">Annual Leave</option>
                <option value="emergency_leave">Emergency Leave</option>
                <option value="unpaid_leave">Unpaid Leave</option>
                <option value="compensated_leave">Compensated Leave</option>
              </optgroup>
              <optgroup label="Non-working">
                <option value="off_day">Off Day</option>
                <option value="public_holiday">Public Holiday</option>
                <option value="absent">Absent</option>
              </optgroup>
            </select>
          </div>
        </div>
        <div class="flex gap" style="margin-bottom:14px" id="mTimes">
          <div style="flex:1"><label>Clock In</label><input type="time" name="clock_in" id="mIn" class="form-control" value="09:00"></div>
          <div style="flex:1"><label>Clock Out</label><input type="time" name="clock_out" id="mOut" class="form-control" value="18:00"><div class="muted small">Night shift? Out time earlier than In time = next day.</div></div>
        </div>
        <div><label>Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional notes"></div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('manualModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Entry</button></div>
    </form>
  </div>
</div>

<!-- Holidays / Off Day modal -->
<div class="modal-bg" id="holidayModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-head"><h3><i class="fa-solid fa-umbrella-beach"></i> Public Holidays &amp; Off Days</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('holidayModal')"></i></div>
    <div class="modal-body">
      <form method="post" action="manual.php" style="margin-bottom:16px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="holiday_add">
        <input type="hidden" name="back" value="<?= e(current_url()) ?>">
        <label>Add Public Holiday</label>
        <div class="flex gap wrap">
          <input type="date" name="holiday_date" class="form-control" style="flex:1;min-width:150px" value="<?= e($fromDate) ?>" required>
          <input type="text" name="title" class="form-control" style="flex:2;min-width:180px" placeholder="e.g. Eid ul Fitr, Independence Day" required>
        </div>
        <label class="flex center gap" style="gap:8px;margin:8px 0;text-transform:none;font-weight:400"><input type="checkbox" name="is_recurring"> Repeats every year (same date)</label>
        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Save Holiday</button>
        <div class="muted small" style="margin-top:6px">On a public holiday nobody is marked Absent — the sheet shows <span class="badge badge-blue">Public Holiday</span> for everyone. Employees who still work are recorded normally.</div>
      </form>

      <div class="divider"></div>
      <form method="post" action="manual.php" style="margin:14px 0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_status">
        <input type="hidden" name="back" value="<?= e(current_url()) ?>">
        <label>Mark ALL employees for one date</label>
        <div class="flex gap wrap">
          <input type="date" name="attendance_date" class="form-control" style="flex:1;min-width:150px" value="<?= e($fromDate) ?>" required>
          <select name="status" class="form-select" style="flex:1;min-width:150px">
            <option value="off_day">Off Day (company off)</option>
            <option value="public_holiday">Public Holiday</option>
            <option value="work_from_home">Work From Home</option>
          </select>
          <input type="text" name="notes" class="form-control" style="flex:2;min-width:150px" placeholder="Reason (optional)">
        </div>
        <button class="btn btn-outline btn-sm" style="margin-top:8px" data-confirm="Apply this status to every active employee for the selected date? Employees who already clocked in are skipped."><i class="fa-solid fa-users"></i> Apply to Everyone</button>
      </form>

      <div class="divider"></div>
      <label style="margin-top:14px">Upcoming / Recent Holidays</label>
      <div class="table-wrap" style="max-height:220px;overflow:auto">
        <table class="tbl">
          <thead><tr><th>Date</th><th>Day</th><th>Title</th><th>Yearly</th><th></th></tr></thead>
          <tbody>
          <?php if(!$holidays) echo '<tr><td colspan="5" class="muted small">No holidays defined yet.</td></tr>'; foreach($holidays as $h): ?>
            <tr>
              <td class="small bold"><?= format_date($h['holiday_date']) ?></td>
              <td class="small muted"><?= date('D', strtotime($h['holiday_date'])) ?></td>
              <td class="small"><?= e($h['title']) ?></td>
              <td class="small"><?= $h['is_recurring'] ? '<i class="fa-solid fa-repeat"></i>' : '—' ?></td>
              <td><form method="post" action="manual.php" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="holiday_delete"><input type="hidden" name="id" value="<?= $h['id'] ?>"><input type="hidden" name="back" value="<?= e(current_url()) ?>"><button class="btn btn-sm btn-light" data-confirm="Remove this holiday?"><i class="fa-solid fa-trash"></i></button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="muted small" style="margin-top:8px"><i class="fa-solid fa-circle-info"></i> Weekly off days (e.g. Sunday) are set per employee in the Employee form.</div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('holidayModal')">Close</button></div>
  </div>
</div>

<!-- ID card scan modal -->
<div class="modal-bg" id="cardModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-id-card"></i> Scan Employee ID Card</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('cardModal')"></i></div>
    <form method="post" action="manual.php">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="card">
        <input type="hidden" name="d" value="<?= e($fromDate) ?>">
        <p class="muted small">Enter or scan the employee code to mark attendance.</p>
        <label>Employee Code</label>
        <input type="text" name="card_code" class="form-control" placeholder="e.g. SCG-004" required autofocus>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('cardModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-wave-square"></i> Scan &amp; Mark</button></div>
    </form>
  </div>
</div>

<!-- Regularization Modal -->
<div class="modal-bg" id="regModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-wand-magic-sparkles"></i> Regularize Attendance</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('regModal')"></i></div>
    <form method="post">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="att_action" value="regularize">
        <input type="hidden" name="employee_id" id="regEmpId">
        <p>Employee: <strong id="regEmpName"></strong></p>
        <div style="margin-bottom:12px"><label>Date</label><input type="date" name="reg_date" id="regDate" class="form-control" required></div>
        <div><label>Reason (Genuine Emergency)</label><textarea name="reg_note" class="form-control" rows="2" placeholder="e.g. Emergency at home, forgot to mark" required></textarea></div>
        <p class="muted small" style="margin-top:10px"><i class="fa-solid fa-circle-info"></i> Gives full shift credit (Hours Completed). Limit: <?= get_setting('regularize_monthly_limit','1') ?> regularization(s) per employee per month.</p>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('regModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Regularize</button></div>
    </form>
  </div>
</div>
<script>
function openReg(id, name, date) {
  document.getElementById('regEmpId').value = id;
  document.getElementById('regEmpName').textContent = name;
  document.getElementById('regDate').value = date;
  openModal('regModal');
}
function openEdit(empId, date, status, cin, cout) {
  document.getElementById('mEmp').value = empId;
  document.getElementById('mDate').value = date;
  var sel = document.getElementById('mStatus');
  sel.value = status; if (sel.value !== status) sel.value = 'present';
  document.getElementById('mIn').value = cin || '';
  document.getElementById('mOut').value = cout || '';
  toggleTimes();
  openModal('manualModal');
}
function toggleTimes() {
  var s = document.getElementById('mStatus').value;
  var timeless = ['casual_leave','medical_leave','annual_leave','emergency_leave','unpaid_leave','compensated_leave','off_day','public_holiday','absent'];
  document.getElementById('mTimes').style.display = timeless.indexOf(s) >= 0 ? 'none' : '';
}
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
