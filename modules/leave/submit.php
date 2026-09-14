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
$reason = clean($_POST['reason']);
$isEmergency = (int)($_POST['is_emergency'] ?? 0);

$lt = fetch_one("SELECT * FROM leave_types WHERE id=?", [$typeId]);
if (!$lt) { set_flash('danger','Invalid leave type.'); redirect(APP_URL.'modules/leave/my.php'); }

// compute days (excluding weekends)
$days = 0;
try { $period = new DatePeriod(new DateTime($start), new DateInterval('P1D'), (new DateTime($end))->modify('+1 day'));
} catch (Exception $e) { set_flash('danger','Invalid date range.'); redirect(APP_URL.'modules/leave/my.php'); }
foreach ($period as $dt) { $dow = (int)$dt->format('N'); if ($dow <= 5) $days++; }
if ($halfDay) $days = max(0.5, $days - 0.5);

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
    'start_date'=>$start, 'end_date'=>$end, 'days'=>$days, 'half_day'=>$halfDay,
    'reason'=>$reason, 'is_emergency'=>$isEmergency,
    'manager_status'=>$mgrStatus, 'hr_status'=>$hrStatus, 'status'=>$status,
    'attachment_path'=>$attach,
]);

// notify manager
$emp = fetch_one("SELECT manager_id, full_name FROM employees WHERE id=?", [$empId]);
if ($emp && $emp['manager_id']) {
    notify($emp['manager_id'], 'New Leave Request', "{$emp['full_name']} requested {$days} day(s) of {$lt['name']}", 'modules/leave/approvals.php');
}
notify(current_user_id(), 'Leave Submitted', "Your {$lt['name']} request ({$days} day(s)) is pending approval.", 'modules/leave/my.php');
log_activity('Leave Requested', "{$lt['name']} $days days ($start to $end)");

set_flash('success', "Leave request submitted! {$days} day(s) of {$lt['name']}. Awaiting approval.");
redirect(APP_URL.'modules/leave/my.php');
