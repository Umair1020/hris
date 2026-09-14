<?php
/**
 * ============================================================================
 * PERFORMANCE - assign KPIs to an employee (HR/manager)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');
verify_csrf();

$empId=(int)$_POST['employee_id'];
$period=clean($_POST['period']);
$kpiIds=$_POST['kpi_ids'] ?? [];

if(!$kpiIds){ set_flash('warning','No KPIs selected.'); redirect(APP_URL.'modules/performance/index.php'); }

foreach($kpiIds as $kid){
  // avoid duplicates
  $ex=fetch_one("SELECT id FROM employee_kpis WHERE employee_id=? AND kpi_template_id=? AND period=?",[$empId,$kid,$period]);
  if(!$ex){
    insert('employee_kpis',['employee_id'=>$empId,'kpi_template_id'=>$kid,'period'=>$period,'assigned_by'=>current_user_id(),'target'=>'']);
  }
}
log_activity('KPIs Assigned',"Employee #$empId, ".count($kpiIds)." KPIs for $period");
set_flash('success',count($kpiIds).' KPI(s) assigned successfully.');
redirect(APP_URL.'modules/performance/index.php');
