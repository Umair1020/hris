<?php
/**
 * ============================================================================
 * ATTENDANCE - manual entry / ID card scan / delete handler (HR only)
 * Pure action handler — NO HTML output, just process and redirect.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';
require_login('hr');
verify_csrf();

$action = $_POST['action'] ?? '';
$date = $_POST['d'] ?? ($_POST['attendance_date'] ?? '');
$redirect = url('modules/attendance/index.php') . ($date ? '?d=' . $date : '');

if ($action === 'add') {
    $empId = (int)$_POST['employee_id'];
    $date  = clean($_POST['attendance_date']);
    $status = clean($_POST['status']);
    $clockIn = !empty($_POST['clock_in']) ? $date . ' ' . $_POST['clock_in'] . ':00' : null;
    $clockOut = !empty($_POST['clock_out']) ? $date . ' ' . $_POST['clock_out'] . ':00' : null;
    $hours = 0; $ot = 0; $ut = 0;
    if ($clockIn && $clockOut) {
        $empData = fetch_one("SELECT shift_start, shift_end FROM employees WHERE id=?", [$empId]);
        $stdHours = get_required_hours($empData);
        $hours = calc_hours($clockIn, $clockOut);
        $ot = max(0, round($hours - $stdHours, 2));
        $ut = max(0, round($stdHours - $hours, 2));
    }
    $existing = fetch_one("SELECT id FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, $date]);
    if ($existing) {
        update('attendance', [
            'clock_in'=>$clockIn, 'clock_out'=>$clockOut, 'status'=>$status,
            'work_hours'=>$hours, 'overtime_hours'=>$ot, 'undertime_hours'=>$ut,
            'notes'=>clean($_POST['notes'] ?? ''), 'clock_in_method'=>'manual'
        ], 'id=?', [$existing['id']]);
    } else {
        insert('attendance', [
            'employee_id'=>$empId, 'attendance_date'=>$date,
            'clock_in'=>$clockIn, 'clock_out'=>$clockOut, 'status'=>$status,
            'work_hours'=>$hours, 'overtime_hours'=>$ot, 'undertime_hours'=>$ut,
            'notes'=>clean($_POST['notes'] ?? ''), 'clock_in_method'=>'manual'
        ]);
    }
    log_activity('Manual Attendance', "Employee #$empId on $date");
    set_flash('success', 'Attendance record saved.');

} elseif ($action === 'card') {
    $code = clean($_POST['card_code']);
    $emp = fetch_one("SELECT id, full_name FROM employees WHERE employee_code=? AND status='Active'", [$code]);
    if (!$emp) {
        set_flash('danger', "No active employee found with code '$code'.");
        redirect($redirect);
    }
    $today = today(); $now = now();
    $rec = fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$emp['id'], $today]);
    $stdHours = (float)get_setting('required_work_hours', '9');
    if ($stdHours < 1) $stdHours = 9;
    if ($rec && $rec['clock_in'] && $rec['clock_out']) {
        set_flash('warning', $emp['full_name'] . ' already completed attendance today.');
    } elseif ($rec && $rec['clock_in'] && !$rec['clock_out']) {
        $hours = round((strtotime($now) - strtotime($rec['clock_in']))/3600, 2);
        update('attendance', [
            'clock_out'=>$now, 'clock_in_method'=>'id_card',
            'work_hours'=>$hours, 'overtime_hours'=>max(0, $hours-$stdHours),
            'undertime_hours'=>max(0, $stdHours-$hours)
        ], 'id=?', [$rec['id']]);
        log_activity('ID Card Scan OUT', $emp['full_name']);
        set_flash('success', 'Clocked OUT: ' . $emp['full_name'] . ' (' . $hours . 'h)');
    } else {
        $late = (date('H:i') > date('H:i', strtotime(WORK_START.' +'.GRACE_MINUTES.' minutes'))) ? 'late' : 'present';
        if ($rec) {
            update('attendance', ['clock_in'=>$now, 'clock_in_method'=>'id_card', 'status'=>$late], 'id=?', [$rec['id']]);
        } else {
            insert('attendance', ['employee_id'=>$emp['id'], 'attendance_date'=>$today, 'clock_in'=>$now, 'clock_in_method'=>'id_card', 'status'=>$late]);
        }
        log_activity('ID Card Scan IN', $emp['full_name']);
        set_flash('success', 'Clocked IN: ' . $emp['full_name']);
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
