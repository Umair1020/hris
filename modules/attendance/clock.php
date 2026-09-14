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
$today  = today();
$now    = now();

$existing = fetch_one("SELECT * FROM attendance WHERE employee_id=? AND attendance_date=?", [$empId, $today]);

if ($action === 'in') {
    if ($existing && $existing['clock_in']) {
        set_flash('warning', 'You have already clocked in today at ' . format_datetime($existing['clock_in'], 'h:i A'));
    } else {
        $late = (date('H:i') > date('H:i', strtotime(WORK_START . ' +' . GRACE_MINUTES . ' minutes'))) ? 'late' : 'present';
        // Build Google Maps link from coordinates
        $mapsLink = '';
        if ($location && $location !== 'location-unavailable' && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }
        if ($existing) {
            update('attendance', ['clock_in' => $now, 'clock_in_location' => $mapsLink ?: $location, 'clock_in_method' => 'portal', 'status' => $late], 'id = ?', [$existing['id']]);
        } else {
            insert('attendance', [
                'employee_id' => $empId, 'attendance_date' => $today,
                'clock_in' => $now, 'clock_in_location' => $mapsLink ?: $location,
                'clock_in_method' => 'portal', 'status' => $late,
            ]);
        }
        log_activity('Clock In', "Location: $location");

        // Send WhatsApp confirmation to employee
        $emp = fetch_one("SELECT full_name, whatsapp FROM employees WHERE id=?", [$empId]);
        if ($emp && !empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance Marked - Clock IN*\n\n";
            $waMsg .= "👤 {$emp['full_name']}\n";
            $waMsg .= "📅 " . date('d M Y, l') . "\n";
            $waMsg .= "⏰ Time IN: " . date('h:i A') . "\n";
            $waMsg .= "📊 Status: " . ucfirst($late);
            if ($mapsLink) $waMsg .= "\n📍 Location: $mapsLink";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $waResult = send_whatsapp($emp['whatsapp'], $waMsg);
            if ($waResult['ok']) {
                set_flash('success', "✅ Clocked IN at " . date('h:i A') . "! WhatsApp confirmation sent to your number.");
            } else {
                set_flash('success', "✅ Clocked IN at " . date('h:i A') . "! (WhatsApp pending: " . $waResult['error'] . ")");
            }
        } else {
            set_flash('success', '✅ Clocked IN at ' . date('h:i A') . '. Have a great day! (Add your WhatsApp number in profile to receive confirmations)');
        }
    }
    } elseif ($action === 'out') {
        if (!$existing || !$existing['clock_in']) {
            set_flash('danger', 'You must clock IN before clocking OUT.');
        } elseif ($existing['clock_out']) {
            set_flash('warning', 'You already clocked out today.');
        } else {
            $hours = calc_hours($existing['clock_in'], $now);
            $empData = fetch_one("SELECT shift_start, shift_end FROM employees WHERE id=?", [$empId]);
            $stdHours = get_required_hours($empData);
            $overtime = max(0, round($hours - $stdHours, 2));
            $undertime = max(0, round($stdHours - $hours, 2));

        // Build Google Maps link
        $mapsLink = '';
        if ($location && $location !== 'location-unavailable' && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }

        update('attendance', [
            'clock_out' => $now, 'clock_out_location' => $mapsLink ?: $location,
            'work_hours' => $hours, 'overtime_hours' => $overtime, 'undertime_hours' => $undertime,
        ], 'id = ?', [$existing['id']]);
        log_activity('Clock Out', "Worked $hours hrs, OT $overtime");

        // Send WhatsApp confirmation
        $emp = fetch_one("SELECT full_name, whatsapp FROM employees WHERE id=?", [$empId]);
        if ($emp && !empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance Marked - Clock OUT*\n\n";
            $waMsg .= "👤 {$emp['full_name']}\n";
            $waMsg .= "📅 " . date('d M Y, l') . "\n";
            $waMsg .= "⏰ Time OUT: " . date('h:i A') . "\n";
            $waMsg .= "⏱️ Total Hours: {$hours}\n";
            if ($overtime > 0) $waMsg .= "⭐ Overtime: {$overtime} hrs";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $waResult = send_whatsapp($emp['whatsapp'], $waMsg);
            if ($waResult['ok']) {
                set_flash('success', "✅ Clocked OUT at " . date('h:i A') . "! Total: {$hours}h. WhatsApp confirmation sent.");
            } else {
                set_flash('success', "✅ Clocked OUT at " . date('h:i A') . "! Total hours: {$hours}");
            }
        } else {
            set_flash('success', '✅ Clocked OUT at ' . date('h:i A') . ". Total hours: $hours");
        }
    }
}
redirect(APP_URL . 'modules/attendance/my.php');
