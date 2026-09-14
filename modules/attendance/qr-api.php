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
    $dir = UPLOAD_DIR . 'selfies/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $base64));
    if (!$data) return null;
    $filename = $prefix . '_' . date('Ymd_His') . '.jpg';
    file_put_contents($dir . $filename, $data);
    return 'selfies/' . $filename;
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
$att = fetch_one("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?", [$emp['id'], $today]);

if ($action === 'lookup') {
    // Determine what action is available
    if (!$att || !$att['clock_in']) {
        $nextAction = 'in';
        $statusText = 'Not Clocked In Yet';
    } elseif ($att['clock_in'] && !$att['clock_out']) {
        $nextAction = 'out';
        $statusText = 'Clocked In at ' . date('h:i A', strtotime($att['clock_in']));
    } else {
        json_response(['ok' => true, 'completed' => true, 'name' => $emp['full_name'], 'message' => 'You have already completed attendance today.']);
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
        if ($location && strpos($location, ',') !== false) {
            list($empLat, $empLng) = explode(',', $location);
            $dist = calculateDistance((float)$empLat, (float)$empLng, $officeLat, $officeLng);
            if ($dist > $radius) {
                json_response(['ok' => false, 'error' => "❌ You are " . round($dist) . "m from office. Attendance can only be marked within {$radius}m of the office location."]);
            }
        } elseif ($location !== 'location-unavailable') {
            json_response(['ok' => false, 'error' => '❌ Could not verify your location. Please enable GPS and try again.']);
        }
    }

    // --- SAVE SELFIE ---
    $selfieFile = null;
    if ($selfieData && strpos($selfieData, 'data:image') === 0) {
        $selfieFile = saveSelfie($selfieData, $emp['employee_code'] . '_' . $selfieType);
    }

    if (!$att || !$att['clock_in']) {
        // CLOCK IN — Smart status: On Time / Late (based on employee shift + grace)
        $graceMin = (int)get_setting('late_grace_minutes', '15');
        $empShiftStart = $emp['shift_start'] ?: '09:00';
        $lateAfter = date('H:i', strtotime("$empShiftStart +$graceMin minutes"));
        $currentTime = date('H:i');
        $late = ($currentTime > $lateAfter) ? 'late' : 'present';
        $mapsLink = '';
        if ($location && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }
        if ($att) {
            update('attendance', ['clock_in' => $now, 'clock_in_location' => $mapsLink ?: $location, 'clock_in_method' => 'id_card', 'status' => $late, 'clock_in_selfie' => $selfieFile], 'id = ?', [$att['id']]);
        } else {
            insert('attendance', ['employee_id' => $empId, 'attendance_date' => $today, 'clock_in' => $now, 'clock_in_location' => $mapsLink ?: $location, 'clock_in_method' => 'id_card', 'status' => $late, 'clock_in_selfie' => $selfieFile]);
        }
        log_activity('QR Clock In', $emp['full_name'] . " at $location");

        $waSent = false;
        if (!empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance - Clock IN*\n\n👤 {$emp['full_name']}\n📅 " . date('d M Y, l') . "\n⏰ " . date('h:i A') . "\n📊 " . ucfirst($late);
            if ($mapsLink) $waMsg .= "\n📍 $mapsLink";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $r = send_whatsapp($emp['whatsapp'], $waMsg);
            $waSent = $r['ok'];
        }

        json_response([
            'ok' => true, 'action' => 'in', 'time' => date('h:i A', strtotime($now)),
            'status' => $late, 'wa_sent' => $waSent,
            'message' => "✅ Clocked IN at " . date('h:i A') . " (" . ucfirst($late) . ")"
        ]);

    } elseif ($att['clock_in'] && !$att['clock_out']) {
        // CLOCK OUT — Smart re-evaluation of status
        $hours = calc_hours($att['clock_in'], $now);
        $reqHours = get_required_hours($emp);
        $overtime = max(0, round($hours - $reqHours, 2));
        $undertime = max(0, round($reqHours - $hours, 2));
        $mapsLink = '';

        // Smart: if employee was late but completed required hours → "Working Hours Completed"
        $newStatus = $att['status'];
        if ($att['status'] === 'late' && $hours >= $reqHours && get_setting('ot_against_late', '1') === '1') {
            $newStatus = 'hours_completed';
        } elseif ($att['status'] === 'present' && $hours >= $reqHours) {
            $newStatus = 'hours_completed';
        }
        if ($location && strpos($location, ',') !== false) {
            $mapsLink = "https://maps.google.com/?q=$location";
        }
        update('attendance', ['clock_out' => $now, 'clock_out_location' => $mapsLink ?: $location, 'work_hours' => $hours, 'overtime_hours' => $overtime, 'undertime_hours' => $undertime, 'clock_out_selfie' => $selfieFile, 'status' => $newStatus], 'id = ?', [$att['id']]);
        log_activity('QR Clock Out', $emp['full_name'] . " worked $hours hrs");

        $waSent = false;
        if (!empty($emp['whatsapp'])) {
            $waMsg = "🔔 *Attendance - Clock OUT*\n\n👤 {$emp['full_name']}\n📅 " . date('d M Y, l') . "\n⏰ " . date('h:i A') . "\n⏱️ Hours: {$hours}";
            if ($overtime > 0) $waMsg .= "\n⭐ OT: {$overtime}h";
            $waMsg .= "\n\n_Spotcomm Global HRIS_";
            $r = send_whatsapp($emp['whatsapp'], $waMsg);
            $waSent = $r['ok'];
        }

        json_response([
            'ok' => true, 'action' => 'out', 'time' => date('h:i A', strtotime($now)),
            'hours' => $hours, 'overtime' => $overtime, 'wa_sent' => $waSent,
            'message' => "✅ Clocked OUT at " . date('h:i A') . ". Total: {$hours}h"
        ]);

    } else {
        json_response(['ok' => false, 'error' => 'Attendance already completed for today']);
    }
}

json_response(['ok' => false, 'error' => 'Invalid action']);
