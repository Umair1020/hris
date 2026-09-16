<?php
/**
 * ============================================================================
 * ATTENDANCE EXPORT — HR/Admin downloads attendance as Excel (CSV)
 * Supports Date Range + Employee Filter
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

// Support old single 'd' param, and new 'from', 'to', 'emp' params
$fromDate = clean($_GET['from'] ?? '');
$toDate = clean($_GET['to'] ?? '');
if (!empty($_GET['d'])) {
    $fromDate = clean($_GET['d']);
    $toDate = $fromDate;
}
if (!$fromDate) $fromDate = today();
if (!$toDate) $toDate = today();

$empId = (int)($_GET['emp'] ?? 0);
$statusFilter = clean($_GET['status'] ?? '');
if ($toDate < $fromDate) { $t = $fromDate; $fromDate = $toDate; $toDate = $t; }

att_sync_absents();

$empWhere = "e.status='Active'"; $empParams = [];
if ($empId > 0) { $empWhere .= " AND e.id=?"; $empParams[] = $empId; }
$employees = fetch_all("SELECT e.*, d.name dept FROM employees e LEFT JOIN departments d ON d.id=e.department_id WHERE $empWhere ORDER BY e.full_name", $empParams);
$empIds = array_column($employees, 'id');
$recMap = [];
if ($empIds) {
    $in = implode(',', array_fill(0, count($empIds), '?'));
    foreach (fetch_all("SELECT * FROM attendance WHERE attendance_date BETWEEN ? AND ? AND employee_id IN ($in)", array_merge([$fromDate, $toDate], $empIds)) as $r) {
        $recMap[$r['employee_id']][$r['attendance_date']] = $r;
    }
}

// Generate CSV filename
$filename = 'Attendance_' . $fromDate . '_to_' . $toDate . '.csv';

// Headers for CSV download (Excel compatible)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM for Excel (fixes Urdu/special characters)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header row
fputcsv($output, [
    'Date', 'Day', 'Employee Code', 'Employee Name', 'Department', 'Designation', 'Shift',
    'Clock In', 'Clock Out', 'Late (min)', 'Work Hours', 'Required Hours', 'Overtime (hrs)', 'Undertime (hrs)',
    'Status', 'Location (Clock In)', 'Location (Clock Out)', 'Method', 'Selfie', 'Regularized', 'Notes'
]);

for ($d = $fromDate; $d <= $toDate; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    foreach ($employees as $e) {
        $r = $recMap[$e['id']][$d] ?? null;
        $virtual = $r ? null : att_expected_status($e, $d);
        if (!$r && $virtual === null) continue;
        $status = $r ? $r['status'] : $virtual;
        if ($statusFilter) {
            $group = isset(att_statuses()[$status]) ? att_statuses()[$status][2] : 'pending';
            $match = match ($statusFilter) {
                'late' => $status === 'late' || ($r && (int)$r['late_minutes'] > 0),
                'present' => in_array($status, att_present_statuses()),
                'leave' => $group === 'leave',
                'off' => $group === 'off',
                'undertime' => $r && (float)$r['undertime_hours'] > 0,
                'overtime' => $r && (float)$r['overtime_hours'] > 0,
                default => $status === $statusFilter,
            };
            if (!$match) continue;
        }
        $label = $status === 'not_marked' ? 'Not Marked' : att_label($status);
        if ($status === 'public_holiday') $label .= ' (' . att_holiday($d) . ')';
        fputcsv($output, [
            format_date($d, 'd M Y'),
            date('D', strtotime($d)),
            $e['employee_code'],
            $e['full_name'],
            $e['dept'] ?: '—',
            $e['designation'] ?: '—',
            att_shift_start($e) . ' - ' . substr($e['shift_end'] ?: '18:00', 0, 5),
            $r && $r['clock_in'] ? date('h:i A', strtotime($r['clock_in'])) : '—',
            $r && $r['clock_out'] ? date('h:i A', strtotime($r['clock_out'])) . (substr($r['clock_out'],0,10) !== $d ? ' (+1 day)' : '') : '—',
            $r ? (int)$r['late_minutes'] : 0,
            $r ? ($r['work_hours'] ?: '0') : '0',
            get_required_hours($e),
            $r ? ($r['overtime_hours'] ?: '0') : '0',
            $r ? ($r['undertime_hours'] ?: '0') : '0',
            $label,
            $r ? ($r['clock_in_location'] ?: '—') : '—',
            $r ? ($r['clock_out_location'] ?: '—') : '—',
            $r ? ucfirst(str_replace('_', ' ', $r['clock_in_method'] ?? '')) : '—',
            $r && !empty($r['clock_in_selfie']) ? 'Yes' : 'No',
            $r && $r['is_regularized'] ? 'Yes' : 'No',
            $r ? ($r['notes'] ?: '—') : '—',
        ]);
    }
}

fclose($output);
exit;
