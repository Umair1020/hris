<?php
/**
 * ============================================================================
 * PAYROLL - Finance Department CSV upload
 * Format: employee_code,allowance,bonus,other_deduction
 * Applies adjustments to the existing payroll run items.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');
verify_csrf();

$month = (int)$_POST['month']; $year = (int)$_POST['year'];

if (empty($_FILES['csv']['tmp_name'])) { set_flash('danger','No file uploaded.'); redirect(APP_URL.'modules/payroll/index.php?m='.$month.'&y='.$year); }

$run = fetch_one("SELECT * FROM payroll_runs WHERE month=? AND year=?",[$month,$year]);
if (!$run) { set_flash('danger','Process the payroll run first before uploading Finance data.'); redirect(APP_URL.'modules/payroll/index.php?m='.$month.'&y='.$year); }

$fh = fopen($_FILES['csv']['tmp_name'],'r');
$header = fgetcsv($fh);
$count = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) < 4) continue;
    list($code,$allowance,$bonus,$deduction) = array_map('trim',$row);
    $item = fetch_one("SELECT pi.* FROM payroll_items pi JOIN employees e ON e.id=pi.employee_id WHERE pi.payroll_run_id=? AND e.employee_code=?",[$run['id'],$code]);
    if (!$item) continue;
    $allowance = (float)$allowance; $bonus = (float)$bonus; $deduction = (float)$deduction;
    $newGross = $item['basic_salary'] + $allowance + $item['overtime_pay'] + $item['bonus'] + $bonus;
    $newDed = $item['loan_deduction'] + $item['tax'] + $item['other_deductions'] + $deduction;
    update('payroll_items',[
        'allowances'=>$allowance,'bonus'=>$item['bonus']+$bonus,'other_deductions'=>$item['other_deductions']+$deduction,
        'gross_pay'=>$newGross,'total_deductions'=>$newDed,'net_pay'=>$newGross-$newDed,
        'upload_note'=>'Updated by Finance via CSV on '.date('Y-m-d'),
    ],'id=?',[$item['id']]);
    $count++;
}
fclose($fh);
log_activity('Payroll Finance Upload', "$count records for ".month_name($month).' '.$year);
set_flash('success',"Finance data applied to $count employee(s).");
redirect(APP_URL.'modules/payroll/index.php?m='.$month.'&y='.$year);
