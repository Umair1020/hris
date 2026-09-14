<?php
/**
 * ============================================================================
 * ATTENDANCE MANAGEMENT — HR & Managers
 * Filter by Date Range + Employee. Regularization, Manual Entry, ID Card.
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
            $emp = fetch_one("SELECT shift_start FROM employees WHERE id=?", [$regEmpId]);
            $shiftStart = $emp['shift_start'] ?: '09:00';
            $shiftEnd = $emp['shift_end'] ?: '18:00';
            $existing = fetch_one("SELECT id FROM attendance WHERE employee_id=? AND attendance_date=?", [$regEmpId, $regDate]);
            if ($existing) {
                update('attendance', ['clock_in' => $regDate . ' ' . $shiftStart . ':00', 'clock_out' => $regDate . ' ' . $shiftEnd . ':00', 'status' => 'present', 'work_hours' => 9, 'is_regularized' => 1, 'regularized_by' => current_user_id(), 'regularized_note' => $regNote], 'id=?', [$existing['id']]);
            } else {
                insert('attendance', ['employee_id' => $regEmpId, 'attendance_date' => $regDate, 'clock_in' => $regDate . ' ' . $shiftStart . ':00', 'clock_out' => $regDate . ' ' . $shiftEnd . ':00', 'status' => 'present', 'work_hours' => 9, 'clock_in_method' => 'manual', 'is_regularized' => 1, 'regularized_by' => current_user_id(), 'regularized_note' => $regNote]);
            }
            log_activity('Attendance Regularized', "Employee #$regEmpId on $regDate: $regNote");
            set_flash('success', "Attendance regularized for $regDate.");
        }
        redirect(APP_URL . 'modules/attendance/index.php');
    }
}

auth_header('Attendance Management', 'manager');

// Filters: Date Range & Employee
$empId = (int)($_GET['emp'] ?? 0);
$fromDate = clean($_GET['from'] ?? '');
$toDate = clean($_GET['to'] ?? '');

if (!$fromDate && !$toDate) {
    $fromDate = clean($_GET['d'] ?? today());
    $toDate = $fromDate;
} elseif ($fromDate && !$toDate) {
    $toDate = $fromDate;
} elseif (!$fromDate && $toDate) {
    $fromDate = $toDate;
}

// restrict managers to their team
$empFilter = '';
$params = [];
if ($role === 'manager') {
    $empFilter = "AND e.manager_id = ?";
    $params[] = current_employee_id();
}
if ($empId > 0) {
    $empFilter .= " AND a.employee_id = ?";
    $params[] = $empId;
}

// records
$records = fetch_all(
    "SELECT a.*, e.full_name, e.employee_code, d.name dept
     FROM attendance a
     JOIN employees e ON e.id = a.employee_id
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE a.attendance_date BETWEEN ? AND ? $empFilter
     ORDER BY a.attendance_date DESC, e.full_name",
    array_merge([$fromDate, $toDate], $params)
);

// Stats (only calculated if viewing a single day to make sense)
$showStats = ($fromDate === $toDate);
$noRecord = [];
if ($showStats) {
    $noRecord = fetch_all(
        "SELECT e.id, e.full_name, e.employee_code
         FROM employees e
         WHERE e.status='Active' AND e.id NOT IN (SELECT employee_id FROM attendance WHERE attendance_date BETWEEN ? AND ?)
         $empFilter ORDER BY e.full_name",
        array_merge([$fromDate, $toDate], $params)
    );
}

$allEmployees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");
$isRange = ($fromDate !== $toDate);
?>
<div class="page-head">
  <div>
    <h1>Attendance Management</h1>
    <div class="sub"><?= $isRange ? "Date Range: $fromDate to $toDate" : "Daily Log: $fromDate" ?></div>
  </div>
  <div class="flex gap">
    <?php if (is_hr()): ?>
      <button class="btn btn-outline" onclick="openModal('cardModal')"><i class="fa-solid fa-id-card"></i> Scan ID</button>
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
    <div style="display:flex; gap:8px; align-self:flex-end">
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
      <?php if (is_hr()): ?>
        <a href="<?= url("modules/attendance/export.php?from=$fromDate&to=$toDate&emp=$empId") ?>" class="btn btn-success"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($showStats): ?>
<div class="grid cols-4" style="margin-bottom:18px">
  <?php
  $counts = fetch_one("SELECT SUM(CASE WHEN a.status IN('present','late','hours_completed') THEN 1 ELSE 0 END) p, SUM(CASE WHEN a.status='late' THEN 1 ELSE 0 END) l FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.attendance_date BETWEEN ? AND ? $empFilter", array_merge([$fromDate, $toDate], $params));
  ?>
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-user-check"></i></div><div><div class="num"><?= (int)$counts['p'] ?></div><div class="lbl">Present</div></div></div>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-clock"></i></div><div><div class="num"><?= (int)$counts['l'] ?></div><div class="lbl">Late Today</div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-user-xmark"></i></div><div><div class="num"><?= count($noRecord) ?></div><div class="lbl">Not Marked</div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-users"></i></div><div><div class="num"><?= count($records)+count($noRecord) ?></div><div class="lbl">Total Workforce</div></div></div>
</div>
<?php endif; ?>

<div class="card card-pad">
  <div class="flex between center" style="margin-bottom:12px">
    <h3 class="section-title">Attendance Log</h3>
  </div>
  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Date</th>
          <th>Employee</th>
          <th>Code</th>
          <th>Clock In</th>
          <th>Clock Out</th>
          <th>Hrs</th>
          <th>Overtime</th>
          <th>Undertime</th>
          <th>Status</th>
          <?php if(is_hr()) echo '<th>Action</th>' ?>
        </tr>
      </thead>
      <tbody>
      <?php
      if(!$records && !$noRecord): ?>
        <tr><td colspan="<?= is_hr()?10:9 ?>" class="empty"><i class="fa-solid fa-clipboard"></i>No records found for the selected filters.</td></tr>
      <?php endif;

      foreach ($records as $r): ?>
        <tr>
          <td class="small muted"><?= format_date($r['attendance_date'],'d M') ?></td>
          <td><div class="flex center gap"><div class="avatar sm"><?= e(initials($r['full_name'])) ?></div><?= e($r['full_name']) ?></div></td>
          <td class="small muted"><?= e($r['employee_code']) ?></td>
          <td nowrap>
            <div class="bold"><?= format_datetime($r['clock_in'],'h:i A') ?></div>
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
            <div class="bold"><?= format_datetime($r['clock_out'],'h:i A') ?></div>
            <?php if($r['clock_out_location'] && $r['clock_out_location'] !== 'location-unavailable' && strpos($r['clock_out_location'], 'http') === 0): ?>
              <a href="<?= e($r['clock_out_location']) ?>" target="_blank" class="badge badge-amber" title="Clock OUT location"><i class="fa-solid fa-location-dot"></i> Map</a>
            <?php elseif($r['clock_out_location']): ?>
              <span class="badge badge-gray" title="<?= e($r['clock_out_location']) ?>"><i class="fa-solid fa-location-dot"></i></span>
            <?php endif; ?>
            <?php if(!empty($r['clock_out_selfie'])): ?>
              <a href="<?= asset('uploads/' . $r['clock_out_selfie']) ?>" target="_blank" class="badge badge-purple" title="Selfie"><i class="fa-solid fa-camera"></i></a>
            <?php endif; ?>
          </td>
          <td><?= $r['work_hours']?:'—' ?></td>
          <td>
            <?= ($r['overtime_hours'] && $r['overtime_hours']>0)?'<span class="badge badge-green">'.$r['overtime_hours'].'h</span>':'—' ?>
          </td>
          <td>
            <?= ($r['undertime_hours'] && $r['undertime_hours']>0)?'<span class="badge badge-amber">'.$r['undertime_hours'].'h</span>':'—' ?>
          </td>
          <td><span class="badge badge-<?= $r['status']==='present'||$r['status']==='hours_completed'?'green':($r['status']==='late'?'amber':($r['status']==='leave'||$r['status']==='casual_leave'||$r['status']==='medical_leave'||$r['status']==='compensated_leave'?'blue':'red')) ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
          <?php if(is_hr()): ?>
          <td>
            <?php if($r['is_regularized']): ?>
              <span class="badge badge-blue" title="<?= e($r['regularized_note']) ?>"><i class="fa-solid fa-check-double"></i></span>
            <?php endif; ?>
            <button class="btn btn-sm btn-light" onclick="openReg(<?= $r['employee_id'] ?>,'<?= e($r['full_name']) ?>','<?= e($r['attendance_date']) ?>')" title="Regularize"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
            <form method="post" action="manual.php" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="btn btn-sm btn-light" data-confirm="Delete this attendance record?"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach;
      
      if ($showStats) {
          foreach ($noRecord as $nr): ?>
            <tr style="opacity:.6">
              <td class="small muted"><?= format_date($fromDate,'d M') ?></td>
              <td><div class="flex center gap"><div class="avatar sm" style="background:#adb1c5"><?= e(initials($nr['full_name'])) ?></div><?= e($nr['full_name']) ?></div></td>
              <td class="small muted"><?= e($nr['employee_code']) ?></td>
              <td colspan="6" class="muted small">— No attendance marked —</td>
              <td><span class="badge badge-red">Absent</span></td>
              <?php if(is_hr()) echo '<td></td>'; ?>
            </tr>
          <?php endforeach;
      }
      ?>
      </tbody>
    </table>
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
        <input type="hidden" name="d" value="<?= e($fromDate) ?>">
        <div style="margin-bottom:14px"><label>Employee</label>
          <select name="employee_id" class="form-select" required>
            <?php foreach ($allEmployees as $emp): ?>
              <option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'].' — '.$emp['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex gap" style="margin-bottom:14px">
          <div style="flex:1"><label>Date</label><input type="date" name="attendance_date" class="form-control" value="<?= e($fromDate) ?>" required></div>
          <div style="flex:1"><label>Status</label>
            <select name="status" class="form-select">
              <option value="present">Present</option>
              <option value="late">Late</option>
              <option value="absent">Absent</option>
              <option value="half_day">Half Day</option>
              <option value="hours_completed">Hours Completed</option>
              <option value="casual_leave">Casual Leave</option>
              <option value="medical_leave">Medical Leave</option>
              <option value="compensated_leave">Compensated Leave</option>
              <option value="work_from_home">Work From Home</option>
              <option value="official_duty">Official Duty / Field</option>
            </select>
          </div>
        </div>
        <div class="flex gap" style="margin-bottom:14px">
          <div style="flex:1"><label>Clock In</label><input type="time" name="clock_in" class="form-control" value="09:00"></div>
          <div style="flex:1"><label>Clock Out</label><input type="time" name="clock_out" class="form-control" value="18:00"></div>
        </div>
        <div><label>Notes</label><input type="text" name="notes" class="form-control" placeholder="Optional notes"></div>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('manualModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Save Entry</button></div>
    </form>
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
        <p class="muted small" style="margin-top:10px"><i class="fa-solid fa-circle-info"></i> Limit: <?= get_setting('regularize_monthly_limit','1') ?> regularization(s) per employee per month.</p>
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
</script>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
