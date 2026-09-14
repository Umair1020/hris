<?php
/**
 * ============================================================================
 * LEAVE - approve / reject handler
 *  - manager step: sets manager_status
 *  - hr step: sets hr_status; when both approved (or emergency HR-approved),
 *    marks leave as approved and deducts balance + marks attendance as leave.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('manager');
verify_csrf();

$id = (int)$_POST['id'];
$step = $_POST['step'] ?? 'manager';
$decision = $_POST['decision'] ?? '';
$uid = current_employee_id(); // FIX: Use employee_id instead of user_id for manager comparison

$lr = fetch_one("SELECT lr.*, lt.is_paid, lt.code, e.manager_id, e.full_name emp_name
                 FROM leave_requests lr
                 JOIN leave_types lt ON lt.id=lr.leave_type_id
                 JOIN employees e ON e.id=lr.employee_id
                 WHERE lr.id=?", [$id]);
if (!$lr) { set_flash('danger','Leave request not found.'); redirect(APP_URL.'modules/leave/approvals.php'); }

// authorization
if ($step === 'manager') {
    if ($lr['manager_id'] != $uid && !is_hr()) { set_flash('danger','Not authorized.'); redirect(APP_URL.'modules/leave/approvals.php'); }
    update('leave_requests', [
        'manager_status'=>$decision==='approve'?'approved':'rejected',
        'manager_id'=>$uid, 'manager_action_at'=>now(),
    ], 'id=?', [$id]);
    log_activity('Leave Manager '.($decision==='approve'?'Approval':'Rejection'), $lr['emp_name']);
    notify($lr['employee_id'], 'Leave Update', "Your leave request was ".($decision==='approve'?'approved':'rejected')." by your manager.", 'modules/leave/my.php');
    
    // FIX: If manager rejects, final status is rejected.
    if ($decision==='reject') {
        update('leave_requests',['status'=>'rejected'],'id=?',[$id]);
    } 
    // FIX: If manager approves, check if HR already approved. If yes, mark final status approved.
    else {
        $lr = fetch_one("SELECT * FROM leave_requests WHERE id=?", [$id]); // Re-fetch to get latest HR status
        if ($lr['hr_status'] === 'approved' || $lr['is_emergency']) {
            update('leave_requests',['status'=>'approved'],'id=?',[$id]);
            // deduct balance & mark attendance
            $ltCode = $lr['code'] ?? '';
            $ltIsPaid = $lr['is_paid'] ?? 0;
            if ($ltIsPaid && $ltCode !== 'LWP') {
                db()->prepare("UPDATE leave_balances SET used=used+? WHERE employee_id=? AND leave_type_id=? AND year=?")
                    ->execute([$lr['days'], $lr['employee_id'], $lr['leave_type_id'], date('Y', strtotime($lr['start_date']))]);
            }
            try {
                $period = new DatePeriod(new DateTime($lr['start_date']), new DateInterval('P1D'), (new DateTime($lr['end_date']))->modify('+1 day'));
                foreach ($period as $dt) {
                    $d = $dt->format('Y-m-d');
                    $ex = fetch_one("SELECT id FROM attendance WHERE employee_id=? AND attendance_date=?", [$lr['employee_id'], $d]);
                    if ($ex) update('attendance', ['status' => 'leave'], 'id=?', [$ex['id']]);
                    else insert('attendance', ['employee_id' => $lr['employee_id'], 'attendance_date' => $d, 'status' => 'leave']);
                }
            } catch (Exception $e) {}
            notify($lr['employee_id'], 'Leave Approved ✅', "Your {$lr['days']}-day leave has been fully approved.", 'modules/leave/my.php');
        }
    }

} elseif ($step === 'hr') {
    require_login('hr');
    update('leave_requests', [
        'hr_status'=>$decision==='approve'?'approved':'rejected',
        'hr_action_by'=>$uid, 'hr_action_at'=>now(),
    ], 'id=?', [$id]);
    log_activity('Leave HR '.($decision==='approve'?'Approval':'Rejection'), $lr['emp_name']);

    // Re-fetch to get latest manager_status
    $lr = fetch_one("SELECT * FROM leave_requests WHERE id=?", [$id]);

    if ($decision === 'reject') {
        update('leave_requests',['status'=>'rejected'],'id=?',[$id]);
        notify($lr['employee_id'],'Leave Rejected',"Your leave request was rejected by HR.",'modules/leave/my.php');
    } else {
        // If HR approves, check if manager already approved (or it's an emergency bypass)
        if ($lr['is_emergency'] || $lr['manager_status']==='approved') {
            update('leave_requests',['status'=>'approved'],'id=?',[$id]);
            
            $ltCode = $lr['code'] ?? '';
            $ltIsPaid = $lr['is_paid'] ?? 0;
            if ($ltIsPaid && $ltCode !== 'LWP') {
                db()->prepare("UPDATE leave_balances SET used=used+? WHERE employee_id=? AND leave_type_id=? AND year=?")
                    ->execute([$lr['days'], $lr['employee_id'], $lr['leave_type_id'], date('Y', strtotime($lr['start_date']))]);
            }
            
            try {
                $period = new DatePeriod(new DateTime($lr['start_date']), new DateInterval('P1D'), (new DateTime($lr['end_date']))->modify('+1 day'));
                foreach ($period as $dt) {
                    $d = $dt->format('Y-m-d');
                    $ex = fetch_one("SELECT id FROM attendance WHERE employee_id=? AND attendance_date=?", [$lr['employee_id'], $d]);
                    if ($ex) update('attendance', ['status' => 'leave'], 'id=?', [$ex['id']]);
                    else insert('attendance', ['employee_id' => $lr['employee_id'], 'attendance_date' => $d, 'status' => 'leave']);
                }
            } catch (Exception $e) {}
            
            notify($lr['employee_id'],'Leave Approved ✅',"Your {$lr['days']}-day leave has been fully approved.",'modules/leave/my.php');
        }
    }
}
set_flash('success',"Leave request {$decision}d successfully.");
redirect(APP_URL.'modules/leave/approvals.php');
