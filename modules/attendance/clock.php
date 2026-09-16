<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - ATTENDANCE: Clock In / Out handler
 * Captures location to confirm arrival (portal method).
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('employee');
verify_csrf();

$empId  = current_employee_id();
$action = $_POST['action'] ?? '';       // in | out
$location = clean($_POST['location'] ?? '');
$selfieData = $_POST['selfie'] ?? '';
$today  = today();
$now    = now();

$selfieFile = null;
if ($selfieData && strpos($selfieData, 'data:image') === 0) {
    $empCodeRow = fetch_one("SELECT employee_code FROM employees WHERE id=?", [$empId]);
    $prefix = ($empCodeRow ? $empCodeRow['employee_code'] : 'emp') . '_' . $action;
    $selfieFile = save_selfie_image($selfieData, $prefix);
}

// PHOTO IS MANDATORY: without a live selfie we send the employee to the camera page
$selfieRequired = get_setting('att_selfie_required', '1') === '1';
if ($selfieRequired && !$selfieFile && ($action === 'in' || $action === 'out')) {
    redirect(APP_URL . 'modules/attendance/mark.php?auto=1');
}

$empRow = fetch_one("SELECT * FROM employees WHERE id=?", [$empId]);
$existing = att_find_open_shift($empId, $now);
if (!$existing) $existing = fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, $today]);

if ($action === 'in') {
    if ($existing && $existing['clock_in']) {
        set_flash('warning', 'You have already clocked in today at ' . format_datetime($existing['clock_in'], 'h:i A'));
    } elseif (!att_can_clock_in($existing)) {
        set_flash('warning', 'Today is already marked as ' . att_label($existing['status']) . '.');
    } else {
        $ci = att_clock_in_status($empRow, $now, $existing);
        $late = $ci['status'];
        // Build Google Maps link from coordinates
        $mapsLink = '';
        if ($location && $location !== 'location-unavailable' && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }
        $inData = ['clock_in' => $now, 'clock_in_location' => $mapsLink ?: $location, 'clock_in_method' => 'portal', 'status' => $late, 'late_minutes' => $ci['late_minutes']];
        if ($selfieFile) $inData['clock_in_selfie'] = $selfieFile;

        if ($existing) {
            update('attendance', $inData, 'id = ?', [$existing['id']]);
        } else {
            $inData['employee_id'] = $empId;
            $inData['attendance_date'] = $today;
            insert('attendance', $inData);
        }
        log_activity('Clock In', "Location: $location");
        $statusLabel = $late === 'late' ? 'Late by ' . fmt_hours($ci['late_minutes'] / 60) : ($late === 'half_day' ? 'Half Day' . ($ci['late_minutes'] ? ' · Late ' . fmt_hours($ci['late_minutes'] / 60) : '') : 'On Time');

        // Send WhatsApp confirmation to employee
        $emp = $empRow;
        if ($emp && !empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance Marked - Clock IN*\n\n";
            $waMsg .= "👤 {$emp['full_name']}\n";
            $waMsg .= "📅 " . date('d M Y, l') . "\n";
            $waMsg .= "⏰ Time IN: " . date('h:i A') . "\n";
            $waMsg .= "📊 Status: " . $statusLabel;
            if ($mapsLink) $waMsg .= "\n📍 Location: $mapsLink";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $waResult = send_whatsapp($emp['whatsapp'], $waMsg);
            if ($waResult['ok']) {
                set_flash('success', "✅ Clocked IN at " . date('h:i A') . " ($statusLabel). WhatsApp confirmation sent to your number.");
            } else {
                set_flash('success', "✅ Clocked IN at " . date('h:i A') . " ($statusLabel). (WhatsApp pending: " . $waResult['error'] . ")");
            }
        } else {
            set_flash('success', '✅ Clocked IN at ' . date('h:i A') . " ($statusLabel). Have a great day! (Add your WhatsApp number in profile to receive confirmations)");
        }
    }
} elseif ($action === 'out') {
    if (!$existing || !$existing['clock_in']) {
        set_flash('danger', 'You must clock IN before clocking OUT.');
    } elseif ($existing['clock_out']) {
        set_flash('warning', 'You already clocked out today.');
    } else {
        $calc = att_compute_clock_out($empRow, $existing, $now);
        $hours = $calc['work_hours']; $overtime = $calc['overtime_hours']; $undertime = $calc['undertime_hours'];

        // Build Google Maps link
        $mapsLink = '';
        if ($location && $location !== 'location-unavailable' && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }

        $outData = [
            'clock_out' => $now, 'clock_out_location' => $mapsLink ?: $location,
            'work_hours' => $hours, 'overtime_hours' => $overtime, 'undertime_hours' => $undertime,
            'late_minutes' => $calc['late_minutes'], 'status' => $calc['status'],
        ];
        if ($selfieFile) $outData['clock_out_selfie'] = $selfieFile;

        update('attendance', $outData, 'id = ?', [$existing['id']]);
        log_activity('Clock Out', "Worked $hours hrs, OT $overtime, UT $undertime");

        $summary = "Worked " . fmt_hours($hours, false) . " of " . fmt_hours($calc['required_hours']);
        if ($overtime > 0) $summary .= " · OT " . fmt_hours($overtime);
        if ($undertime > 0) $summary .= " · Undertime " . fmt_hours($undertime);
        $summary .= " — " . att_label($calc['status']);

        // Send WhatsApp confirmation
        $emp = $empRow;
        if ($emp && !empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance Marked - Clock OUT*\n\n";
            $waMsg .= "👤 {$emp['full_name']}\n";
            $waMsg .= "📅 " . date('d M Y, l') . "\n";
            $waMsg .= "⏰ Time OUT: " . date('h:i A') . "\n";
            $waMsg .= "⏱️ $summary";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $waResult = send_whatsapp($emp['whatsapp'], $waMsg);
            if ($waResult['ok']) {
                set_flash('success', "✅ Clocked OUT at " . date('h:i A') . "! $summary. WhatsApp confirmation sent.");
            } else {
                set_flash('success', "✅ Clocked OUT at " . date('h:i A') . "! $summary");
            }
        } else {
            set_flash('success', '✅ Clocked OUT at ' . date('h:i A') . ". $summary");
        }
    }
}
redirect(APP_URL . 'modules/attendance/my.php');
