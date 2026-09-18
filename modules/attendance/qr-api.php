<?php
/**
 * ============================================================================
 * QR ATTENDANCE API — AJAX endpoint for mark.php
 * Actions: lookup (find employee + today status), mark (clock in/out)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
header('Content-Type: application/json');

// --- Helper: Haversine distance between two GPS coordinates (in meters) ---
function calculateDistance($lat1, $lng1, $lat2, $lng2)
{
    $R = 6371000; // Earth radius in meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

// --- Helper: save base64 selfie to file ---
function saveSelfie($base64, $prefix)
{
    return save_selfie_image($base64, $prefix);
}

$action = $_REQUEST['action'] ?? '';
$code = clean($_REQUEST['code'] ?? '');

if (!$code) {
    json_response(['ok' => false, 'error' => 'No employee code provided']);
}

// Find employee by code
$emp = fetch_one("SELECT *, COALESCE(full_name, first_name || ' ' || last_name, employee_code) AS full_name FROM employees WHERE employee_code = ? AND status = 'Active'", [$code]);
if (!$emp) {
    json_response(['ok' => false, 'error' => 'Invalid employee code or inactive employee']);
}

$today = today();
$now = now();
// Night-shift aware: an open (not clocked-out) shift from yesterday is still "today's" shift
$att = att_find_open_shift($emp['id'], $now);
if (!$att) $att = fetch_one("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?", [$emp['id'], $today]);

if ($action === 'lookup') {
    // Determine what action is available
    if (!$att || !$att['clock_in']) {
        if ($att && !att_can_clock_in($att)) {
            json_response(['ok' => true, 'completed' => true, 'name' => $emp['full_name'], 'message' => 'Today is marked as ' . att_label($att['status']) . '. No clock-in needed.']);
        }
        $nextAction = 'in';
        $statusText = 'Not Clocked In Yet · Shift ' . date('h:i A', strtotime(att_shift_start($emp)));
    } elseif ($att['clock_in'] && !$att['clock_out']) {
        $nextAction = 'out';
        $statusText = 'Clocked In at ' . date('h:i A', strtotime($att['clock_in']));
        if (!empty($att['late_minutes'])) $statusText .= ' (Late ' . fmt_hours($att['late_minutes'] / 60) . ')';
    } else {
        json_response(['ok' => true, 'completed' => true, 'name' => $emp['full_name'], 'message' => 'You have already completed attendance today. Worked ' . fmt_hours($att['work_hours']) . ' · ' . att_label($att['status'])]);
    }

    json_response([
        'ok' => true,
        'employee_id' => $emp['id'],
        'name' => $emp['full_name'],
        'code' => $emp['employee_code'],
        'designation' => $emp['designation'] ?: '',
        'department' => department_name($emp['department_id']),
        'next_action' => $nextAction,
        'status_text' => $statusText,
        'photo' => $emp['photo'],
    ]);

} elseif ($action === 'mark') {
    // Mark attendance (clock in or out)
    $location = clean($_REQUEST['location'] ?? '');
    $selfieData = $_POST['selfie'] ?? '';
    $selfieType = clean($_REQUEST['selfie_type'] ?? 'in');
    $empId = $emp['id'];
    $now = now();

    // --- GEOFENCE CHECK ---
    if (get_setting('att_geofence_enabled', '0') === '1') {
        $officeLat = (float)get_setting('att_office_lat', '0');
        $officeLng = (float)get_setting('att_office_lng', '0');
        $radius = (int)get_setting('att_geofence_radius', '200');
        $coordinates = explode(',', $location);
        if (count($coordinates) === 2 && is_numeric($coordinates[0]) && is_numeric($coordinates[1])
            && abs((float)$coordinates[0]) <= 90 && abs((float)$coordinates[1]) <= 180) {
            list($empLat, $empLng) = $coordinates;
            $dist = calculateDistance((float)$empLat, (float)$empLng, $officeLat, $officeLng);
            if ($dist > $radius) {
                json_response(['ok' => false, 'error' => "❌ You are " . round($dist) . "m from office. Attendance can only be marked within {$radius}m of the office location."]);
            }
        } else {
            json_response(['ok' => false, 'error' => '❌ Could not verify your location. Please enable GPS and try again.']);
        }
    }

    // --- PHOTO IS MANDATORY: no photo → no attendance ---
    $selfieRequired = get_setting('att_selfie_required', '1') === '1';
    if ($selfieRequired && (!$selfieData || strpos($selfieData, 'data:image') !== 0)) {
        json_response(['ok' => false, 'error' => '📸 Photo required. Attendance cannot be marked without a live selfie — please take your photo and try again.']);
    }

    // --- SAVE SELFIE ---
    $selfieFile = null;
    if ($selfieData && strpos($selfieData, 'data:image') === 0) {
        $selfieFile = saveSelfie($selfieData, $emp['employee_code'] . '_' . $selfieType);
        if (!$selfieFile) {
            json_response(['ok' => false, 'error' => '📸 Your photo could not be saved. Please retake the selfie and submit again.']);
        }
    }

    if (!$att || !$att['clock_in']) {
        // CLOCK IN — status by employee shift + grace (Late shows minutes late)
        $ci = att_clock_in_status($emp, $now, $att);
        $late = $ci['status'];
        $mapsLink = '';
        if ($location && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }
        $inData = ['clock_in' => $now, 'clock_in_location' => $mapsLink ?: $location, 'clock_in_method' => 'id_card', 'status' => $late, 'late_minutes' => $ci['late_minutes'], 'clock_in_selfie' => $selfieFile];
        if ($att) {
            update('attendance', $inData, 'id = ?', [$att['id']]);
        } else {
            $inData['employee_id'] = $empId; $inData['attendance_date'] = $today;
            insert('attendance', $inData);
        }
        log_activity('QR Clock In', $emp['full_name'] . " at $location");
        $statusLabel = $late === 'late' ? 'Late by ' . fmt_hours($ci['late_minutes'] / 60) : ($late === 'half_day' ? 'Half Day' . ($ci['late_minutes'] ? ' · Late ' . fmt_hours($ci['late_minutes'] / 60) : '') : 'On Time');

        $waSent = false;
        if (!empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance - Clock IN*\n\n👤 {$emp['full_name']}\n📅 " . date('d M Y, l') . "\n⏰ " . date('h:i A') . "\n📊 " . $statusLabel;
            if ($mapsLink) $waMsg .= "\n📍 $mapsLink";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $r = send_whatsapp($emp['whatsapp'], $waMsg);
            $waSent = $r['ok'];
        }

        json_response([
            'ok' => true, 'action' => 'in', 'time' => date('h:i A', strtotime($now)),
            'status' => $late, 'late_minutes' => $ci['late_minutes'], 'wa_sent' => $waSent,
            'message' => "✅ Clocked IN at " . date('h:i A') . " (" . $statusLabel . ")"
        ]);

    } elseif ($att['clock_in'] && !$att['clock_out']) {
        // CLOCK OUT — hours / OT / undertime / final status from the shared engine
        $calc = att_compute_clock_out($emp, $att, $now);
        $hours = $calc['work_hours']; $overtime = $calc['overtime_hours']; $undertime = $calc['undertime_hours'];
        $mapsLink = '';
        if ($location && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }
        update('attendance', [
            'clock_out' => $now, 'clock_out_location' => $mapsLink ?: $location,
            'work_hours' => $hours, 'overtime_hours' => $overtime, 'undertime_hours' => $undertime,
            'late_minutes' => $calc['late_minutes'], 'clock_out_selfie' => $selfieFile, 'status' => $calc['status'],
        ], 'id = ?', [$att['id']]);
        log_activity('QR Clock Out', $emp['full_name'] . " worked $hours hrs");

        $summary = "Worked " . fmt_hours($hours, false) . " of " . fmt_hours($calc['required_hours']);
        if ($overtime > 0) $summary .= " · OT " . fmt_hours($overtime);
        if ($undertime > 0) $summary .= " · Undertime " . fmt_hours($undertime);

        $waSent = false;
        if (!empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance - Clock OUT*\n\n👤 {$emp['full_name']}\n📅 " . date('d M Y, l') . "\n⏰ " . date('h:i A') . "\n⏱️ " . $summary . "\n📊 " . att_label($calc['status']);
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $r = send_whatsapp($emp['whatsapp'], $waMsg);
            $waSent = $r['ok'];
        }

        json_response([
            'ok' => true, 'action' => 'out', 'time' => date('h:i A', strtotime($now)),
            'hours' => $hours, 'overtime' => $overtime, 'undertime' => $undertime, 'status' => $calc['status'], 'wa_sent' => $waSent,
            'message' => "✅ Clocked OUT at " . date('h:i A') . ". " . $summary . " — " . att_label($calc['status'])
        ]);

    } else {
        json_response(['ok' => false, 'error' => 'Attendance already completed for today']);
    }
}

json_response(['ok' => false, 'error' => 'Invalid action']);
