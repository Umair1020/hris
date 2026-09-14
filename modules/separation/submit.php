<?php
/**
 * ============================================================================
 * SEPARATION - employee submits resignation / exit request with handover files.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('employee');
verify_csrf();

$empId=current_employee_id();
// prevent duplicate open requests
$open=fetch_one("SELECT id FROM separation_requests WHERE employee_id=? AND status NOT IN('completed','rejected','withdrawn')",[$empId]);
if($open){ set_flash('warning','You already have an open separation request.'); redirect(APP_URL.'modules/profile/index.php'); }

$files='';
if(!empty($_FILES['handover']['name'][0])){
  $paths=[];
  foreach($_FILES['handover']['name'] as $i=>$name){
    if($_FILES['handover']['error'][$i]!==UPLOAD_ERR_OK) continue;
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if(!in_array($ext,['pdf','doc','docx','jpg','png','xlsx'])) continue;
    if($_FILES['handover']['size'][$i]>MAX_UPLOAD_MB*1048576) continue;
    $newName=uniqid('hnd_').'.'.$ext;
    move_uploaded_file($_FILES['handover']['tmp_name'][$i],UPLOAD_DIR.$newName);
    $paths[]=$newName;
  }
  $files=implode(',',$paths);
}

$sepId=insert('separation_requests',[
  'employee_id'=>$empId,'separation_type'=>clean($_POST['separation_type']),
  'reason'=>clean($_POST['reason']),'notice_date'=>clean($_POST['notice_date']),
  'last_working_day'=>clean($_POST['last_working_day']),
  'handover_notes'=>clean($_POST['handover_notes']),'handover_files'=>$files,
  'status'=>'submitted',
]);
// seed default clearance items
$defaults=[['Company Assets Return','HR','HR Department'],['Laptop / Equipment Return','IT','IT Department'],['Final Settlement','Finance','Finance Department'],['ID Card & Access Revoke','Admin','Admin Office'],['Knowledge / Project Handover','Reporting Manager','Team Lead']];
foreach($defaults as $d) insert('clearance_items',['separation_id'=>$sepId,'item_name'=>$d[0],'department'=>$d[1],'responsible_user'=>$d[2]]);

foreach(fetch_all("SELECT id FROM users WHERE role IN('hr','admin')") as $hr) notify($hr['id'],'New Separation Request',employee_name($empId).' submitted a resignation','modules/separation/index.php');
log_activity('Separation Submitted','Type: '.clean($_POST['separation_type']));
set_flash('success','Your resignation has been submitted. HR will initiate the clearance process.');
redirect(APP_URL.'modules/profile/index.php');
