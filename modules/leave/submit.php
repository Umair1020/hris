<?php
/**
 * ============================================================================
 * LEAVE - submit new request (enforces advance-notice rules)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('employee');
verify_csrf();

$empId = current_employee_id();
$typeId = (int)$_POST['leave_type_id'];
$start = clean($_POST['start_date']);
$end = clean($_POST['end_date']);
$halfDay = (int)($_POST['half_day'] ?? 0);
$halfSession = in_array($_POST['half_day_session'] ?? '', ['first_half', 'second_half']) ? $_POST['half_day_session'] : 'first_half';
$reason = clean($_POST['reason']);
$isEmergency = (int)($_POST['is_emergency'] ?? 0);

$lt = fetch_one("SELECT * FROM leave_types WHERE id=?", [$typeId]);
if (!$lt) { set_flash('danger','Invalid leave type.'); redirect(APP_URL.'modules/leave/my.php'); }

if (!$start || !$end || strtotime($start) === false || strtotime($end) === false) { set_flash('danger','Invalid date range.'); redirect(APP_URL.'modules/leave/my.php'); }
// Half day is always a single date
if ($halfDay) $end = $start;
if ($end < $start) { set_flash('danger','End date cannot be before start date.'); redirect(APP_URL.'modules/leave/my.php'); }

// overlap check with existing pending/approved requests
$overlap = fetch_one("SELECT id FROM leave_requests WHERE employee_id=? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ?", [$empId, $end, $start]);
if ($overlap) { set_flash('danger','You already have a leave request covering these dates.'); redirect(APP_URL.'modules/leave/my.php'); }

// compute days — only working days (skips weekly off + public holidays)
$empRow = fetch_one("SELECT * FROM employees WHERE id=?", [$empId]);
$days = att_working_days($empRow, $start, $end);
if ($days <= 0) { set_flash('danger','Selected date(s) fall on your off day / public holiday — no leave needed.'); redirect(APP_URL.'modules/leave/my.php'); }
if ($halfDay) $days = 0.5;

// emergency bypasses lead-day check
if (!$isEmergency) {
    $lead = floor((strtotime($start) - strtotime(today())) / 86400);
    if ($lead < $lt['min_lead_days']) {
        set_flash('danger', "Advance notice not met. {$lt['name']} requires at least {$lt['min_lead_days']} day(s) in advance. You gave {$lead} day(s). For medical emergencies, select Emergency Leave.");
        redirect(APP_URL.'modules/leave/my.php');
    }
}

// balance check (paid leaves only)
if ($lt['is_paid'] && $lt['code'] !== 'LWP') {
    $bal = fetch_one("SELECT * FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?", [$empId, $typeId, date('Y')]);
    $remaining = $bal ? ($bal['allocated'] - $bal['used']) : 0;
    if ($days > $remaining) {
        set_flash('danger', "Insufficient leave balance. You requested {$days} day(s) but only {$remaining} day(s) remaining for {$lt['name']}.");
        redirect(APP_URL.'modules/leave/my.php');
    }
}

// file upload
$attach = null;
if (!empty($_FILES['attachment']['name'])) {
    try { $attach = handle_upload('attachment', UPLOAD_DIR, ['pdf','jpg','jpeg','png']); }
    catch (Exception $ex) { set_flash('warning', $ex->getMessage()); }
}

// emergency => skip manager, HR-only
$mgrStatus = $isEmergency ? 'pending' : 'pending';
$hrStatus  = $isEmergency ? 'pending' : 'pending';
$status    = 'pending';

insert('leave_requests', [
    'employee_id'=>$empId, 'leave_type_id'=>$typeId,
    'start_date'=>$start, 'end_date'=>$end, 'days'=>$days, 'half_day'=>$halfDay, 'half_day_session'=>$halfDay ? $halfSession : null,
    'reason'=>$reason, 'is_emergency'=>$isEmergency,
    'manager_status'=>$mgrStatus, 'hr_status'=>$hrStatus, 'status'=>$status,
    'attachment_path'=>$attach,
]);

// notify manager
$emp = $empRow;
$what = $halfDay ? 'a Half Day (' . ($halfSession === 'second_half' ? 'second half' : 'first half') . ", $start)" : "{$days} day(s) of {$lt['name']}";
if ($emp && $emp['manager_id']) {
    notify_employee($emp['manager_id'], 'New Leave Request', "{$emp['full_name']} requested $what", 'modules/leave/approvals.php');
}
foreach (fetch_all("SELECT id FROM users WHERE role IN('hr','admin') AND status='active'") as $hrU) {
    notify($hrU['id'], 'New Leave Request', "{$emp['full_name']} requested $what", 'modules/leave/approvals.php');
}
notify(current_user_id(), 'Leave Submitted', "Your {$lt['name']} request ({$days} day(s)) is pending approval.", 'modules/leave/my.php');
log_activity('Leave Requested', "{$lt['name']} $days days ($start to $end)");

set_flash('success', $halfDay ? "Half Day request submitted for " . format_date($start) . " ({$lt['name']}, 0.5 day). Awaiting approval." : "Leave request submitted! {$days} day(s) of {$lt['name']}. Awaiting approval.");
redirect(APP_URL.'modules/leave/my.php');
