<?php
/**
 * ============================================================================
 * ATTENDANCE - manual entry / ID card scan / delete / holidays / sync (HR only)
 * Pure action handler — NO HTML output, just process and redirect.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('hr');
verify_csrf();

$action = $_POST['action'] ?? '';
$date = $_POST['d'] ?? ($_POST['attendance_date'] ?? '');
$back = clean($_POST['back'] ?? '');
$redirect = $back ?: (url('modules/attendance/index.php') . ($date ? '?d=' . $date : ''));

if ($action === 'add') {
    $empId = (int)$_POST['employee_id'];
    $date  = clean($_POST['attendance_date']);
    $status = clean($_POST['status']);
    $notes = clean($_POST['notes'] ?? '');
    $validStatuses = array_keys(att_statuses());
    if (!in_array($status, $validStatuses)) $status = 'present';

    $empData = fetch_one("SELECT * FROM employees WHERE id=?", [$empId]);
    if (!$empData) { set_flash('danger', 'Employee not found.'); redirect($redirect); }

    // Statuses without working time (leave / off / holiday / absent) never carry clock times
    $group = att_statuses()[$status][2];
    $timeless = in_array($group, ['leave', 'off', 'absent']);

    $clockIn = null; $clockOut = null;
    if (!$timeless) {
        [$clockIn, $clockOut] = att_manual_times($date, $_POST['clock_in'] ?? '', $_POST['clock_out'] ?? '');
    }

    $hours = 0; $ot = 0; $ut = 0; $lateMin = 0;
    if ($clockIn) {
        $lateMin = att_late_minutes($empData, $clockIn);
        // Auto-detect Late when HR picked "Present" but time is after grace
        if ($status === 'present' && $lateMin > 0) $status = 'late';
    }
    if ($clockIn && $clockOut) {
        $calc = att_compute_clock_out($empData, ['clock_in' => $clockIn, 'status' => $status, 'late_minutes' => $lateMin], $clockOut);
        $hours = $calc['work_hours']; $ot = $calc['overtime_hours']; $ut = $calc['undertime_hours'];
        if (in_array($status, ['present', 'late', 'half_day'])) $status = $calc['status'];
    }
    if ($timeless) { $ot = 0; $ut = 0; $hours = 0; }

    $data = [
        'clock_in'=>$clockIn, 'clock_out'=>$clockOut, 'status'=>$status,
        'work_hours'=>$hours, 'overtime_hours'=>$ot, 'undertime_hours'=>$ut, 'late_minutes'=>$lateMin,
        'notes'=>$notes, 'clock_in_method'=>'manual'
    ];
    $existing = fetch_one("SELECT id FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, $date]);
    if ($existing) update('attendance', $data, 'id=?', [$existing['id']]);
    else insert('attendance', array_merge(['employee_id'=>$empId, 'attendance_date'=>$date], $data));

    log_activity('Manual Attendance', "Employee #$empId on $date → " . att_label($status));
    set_flash('success', 'Attendance saved: ' . att_label($status) . ($hours ? ' (' . fmt_hours($hours) . ')' : '') . '.');

} elseif ($action === 'bulk_status') {
    // Mark ALL active employees for a date (e.g. Public Holiday / Off Day)
    $date = clean($_POST['attendance_date']);
    $status = clean($_POST['status']);
    if (!in_array($status, ['public_holiday', 'off_day', 'work_from_home', 'absent'])) { set_flash('danger', 'Invalid bulk status.'); redirect($redirect); }
    $notes = clean($_POST['notes'] ?? '');
    $n = 0;
    foreach (fetch_all("SELECT id FROM employees WHERE status='Active'") as $emp) {
        $ex = fetch_one("SELECT id, clock_in FROM attendance WHERE employee_id=? AND attendance_date=?", [$emp['id'], $date]);
        if ($ex && $ex['clock_in']) continue; // keep real worked records
        $data = ['status'=>$status, 'notes'=>$notes, 'work_hours'=>0, 'overtime_hours'=>0, 'undertime_hours'=>0, 'clock_in_method'=>'manual'];
        if ($ex) update('attendance', $data, 'id=?', [$ex['id']]);
        else insert('attendance', array_merge(['employee_id'=>$emp['id'], 'attendance_date'=>$date], $data));
        $n++;
    }
    log_activity('Bulk Attendance', "$date → " . att_label($status) . " for $n employees");
    set_flash('success', att_label($status) . " applied to $n employee(s) for " . format_date($date) . '.');

} elseif ($action === 'holiday_add') {
    $hDate = clean($_POST['holiday_date']);
    $title = clean($_POST['title']) ?: 'Public Holiday';
    $recurring = isset($_POST['is_recurring']) ? 1 : 0;
    if (!$hDate) { set_flash('danger', 'Holiday date is required.'); redirect($redirect); }
    $ex = fetch_one("SELECT id FROM holidays WHERE holiday_date=?", [$hDate]);
    if ($ex) update('holidays', ['title'=>$title, 'is_recurring'=>$recurring], 'id=?', [$ex['id']]);
    else insert('holidays', ['title'=>$title, 'holiday_date'=>$hDate, 'is_recurring'=>$recurring]);
    att_holiday_cache_reset();
    // Convert any auto-absent rows on that day into Public Holiday
    db()->prepare("UPDATE attendance SET status='public_holiday', notes=? WHERE attendance_date=? AND status='absent' AND clock_in IS NULL")->execute([$title, $hDate]);
    log_activity('Holiday Added', "$title on $hDate");
    set_flash('success', "Public holiday '$title' saved for " . format_date($hDate) . '.');

} elseif ($action === 'holiday_delete') {
    $id = (int)$_POST['id'];
    $h = fetch_one("SELECT * FROM holidays WHERE id=?", [$id]);
    if ($h) {
        db()->prepare("DELETE FROM holidays WHERE id=?")->execute([$id]);
        db()->prepare("DELETE FROM attendance WHERE attendance_date=? AND status='public_holiday' AND clock_in IS NULL")->execute([$h['holiday_date']]);
        att_holiday_cache_reset();
        log_activity('Holiday Removed', $h['title'] . ' ' . $h['holiday_date']);
        set_flash('success', 'Holiday removed.');
    }

} elseif ($action === 'sync_absent') {
    $n = att_sync_absents(true);
    set_flash('success', "Absent sync complete — $n missing day(s) marked Absent.");

} elseif ($action === 'card') {
    $code = clean($_POST['card_code']);
    $emp = fetch_one("SELECT * FROM employees WHERE employee_code=? AND status='Active'", [$code]);
    if (!$emp) {
        set_flash('danger', "No active employee found with code '$code'.");
        redirect($redirect);
    }
    $today = today(); $now = now();
    $rec = att_find_open_shift($emp['id'], $now);
    if (!$rec) $rec = fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$emp['id'], $today]);
    if ($rec && $rec['clock_in'] && $rec['clock_out']) {
        set_flash('warning', $emp['full_name'] . ' already completed attendance today (' . fmt_hours($rec['work_hours']) . ').');
    } elseif ($rec && $rec['clock_in'] && !$rec['clock_out']) {
        $calc = att_compute_clock_out($emp, $rec, $now);
        update('attendance', [
            'clock_out'=>$now, 'work_hours'=>$calc['work_hours'], 'overtime_hours'=>$calc['overtime_hours'],
            'undertime_hours'=>$calc['undertime_hours'], 'late_minutes'=>$calc['late_minutes'], 'status'=>$calc['status'],
        ], 'id=?', [$rec['id']]);
        log_activity('ID Card Scan OUT', $emp['full_name']);
        set_flash('success', 'Clocked OUT: ' . $emp['full_name'] . ' — worked ' . fmt_hours($calc['work_hours']) . ' (' . att_label($calc['status']) . ')');
    } else {
        $ci = att_clock_in_status($emp, $now, $rec);
        $data = ['clock_in'=>$now, 'clock_in_method'=>'id_card', 'status'=>$ci['status'], 'late_minutes'=>$ci['late_minutes']];
        if ($rec) update('attendance', $data, 'id=?', [$rec['id']]);
        else insert('attendance', array_merge(['employee_id'=>$emp['id'], 'attendance_date'=>$today], $data));
        log_activity('ID Card Scan IN', $emp['full_name']);
        set_flash('success', 'Clocked IN: ' . $emp['full_name'] . ($ci['late_minutes'] ? ' — Late by ' . fmt_hours($ci['late_minutes']/60) : ' — On Time'));
    }

} elseif ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        db()->prepare("DELETE FROM attendance WHERE id = ?")->execute([$id]);
        set_flash('success', 'Record deleted successfully.');
    } else {
        set_flash('danger', 'Invalid record ID.');
    }
}

redirect($redirect);
