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

$where = "WHERE a.attendance_date BETWEEN ? AND ?";
$params = [$fromDate, $toDate];

if ($empId > 0) {
    $where .= " AND a.employee_id = ?";
    $params[] = $empId;
}

// Fetch records
$records = fetch_all(
    "SELECT a.*, e.full_name, e.employee_code, e.designation, d.name dept
     FROM attendance a
     JOIN employees e ON e.id = a.employee_id
     LEFT JOIN departments d ON d.id = e.department_id
     $where
     ORDER BY a.attendance_date DESC, e.full_name",
    $params
);

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
    'Date',
    'Employee Code',
    'Employee Name',
    'Department',
    'Designation',
    'Clock In',
    'Clock Out',
    'Work Hours',
    'Overtime (hrs)',
    'Undertime (hrs)',
    'Status',
    'Location (Clock In)',
    'Location (Clock Out)',
    'Method',
    'Selfie',
    'Regularized',
    'Notes'
]);

// Data rows
foreach ($records as $r) {
    // FIX: Display time exactly as stored (Karachi time), no UTC conversion
    $clockIn = $r['clock_in'] ? date('h:i A', strtotime($r['clock_in'])) : '—';
    $clockOut = $r['clock_out'] ? date('h:i A', strtotime($r['clock_out'])) : '—';
    
    fputcsv($output, [
        format_date($r['attendance_date'], 'd M Y'),
        $r['employee_code'],
        $r['full_name'],
        $r['dept'] ?: '—',
        $r['designation'] ?: '—',
        $clockIn,
        $clockOut,
        $r['work_hours'] ?: '0',
        $r['overtime_hours'] ?: '0',
        $r['undertime_hours'] ?: '0',
        ucfirst(str_replace('_', ' ', $r['status'])),
        $r['clock_in_location'] ?: '—',
        $r['clock_out_location'] ?: '—',
        ucfirst(str_replace('_', ' ', $r['clock_in_method'] ?? '')),
        !empty($r['clock_in_selfie']) ? 'Yes' : 'No',
        $r['is_regularized'] ? 'Yes' : 'No',
        $r['notes'] ?: '—'
    ]);
}

fclose($output);
exit;
