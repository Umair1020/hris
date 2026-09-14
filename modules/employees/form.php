<?php
/**
 * ============================================================================
 * EMPLOYEE FORM — HR add / edit employee
 * NOTE: POST handler runs BEFORE auth_header() to ensure clean redirects.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');
$id=(int)($_GET['id']??0);
$e=$id?fetch_one("SELECT * FROM employees WHERE id=?",[$id]):null;

if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $data=[
    'employee_code'=>clean($_POST['employee_code'] ?? ''),'first_name'=>clean($_POST['first_name'] ?? ''),
    'last_name'=>clean($_POST['last_name'] ?? ''),
    'full_name'=>trim(clean($_POST['first_name'] ?? '') . ' ' . clean($_POST['last_name'] ?? '')),
    'email'=>clean($_POST['email'] ?? ''),'phone'=>clean($_POST['phone'] ?? ''),
    'cnic'=>clean($_POST['cnic'] ?? ''),'dob'=>clean($_POST['dob'] ?? '') ?: null,'gender'=>clean($_POST['gender'] ?? ''),
    'marital_status'=>clean($_POST['marital_status'] ?? ''),'address'=>clean($_POST['address'] ?? ''),
    'department_id'=>(int)($_POST['department_id'] ?? 0) ?: null,'designation'=>clean($_POST['designation'] ?? ''),
    'manager_id'=>(int)($_POST['manager_id'] ?? 0) ?: null,'employment_type'=>clean($_POST['employment_type'] ?? 'Probation'),
    'joining_date'=>clean($_POST['joining_date'] ?? '') ?: null,'basic_salary'=>(float)($_POST['basic_salary'] ?? 0),
    'emergency_name'=>clean($_POST['emergency_name'] ?? ''),'emergency_phone'=>clean($_POST['emergency_phone'] ?? ''),
    'bank_name'=>clean($_POST['bank_name'] ?? ''),'bank_account'=>clean($_POST['bank_account'] ?? ''),
    'shift_start'=>clean($_POST['shift_start'] ?? '09:00'),'shift_end'=>clean($_POST['shift_end'] ?? '18:00'),
    'whatsapp'=>clean($_POST['whatsapp'] ?? ''),
    'weekly_off'=>implode(',', ($_POST['weekly_off'] ?? ['Sunday'])),
  ];
  if($e){ update('employees',$data,'id=?',[$id]);
    // --- UPDATE leave balances (EDIT mode) ---
    // Previously the edit branch did NOT touch leave_balances at all, so any
    // value entered here was silently discarded and the form always re-showed
    // the default. Now we upsert each leave type's allocated balance.
    $year = date('Y');
    foreach (fetch_all("SELECT * FROM leave_types") as $lt) {
      $raw = $_POST['leave_' . $lt['id']] ?? null;
      if ($raw !== null && $raw !== '') {
        $bal = (float)$raw;
        $existing = fetch_one("SELECT id, used FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?", [$id, $lt['id'], $year]);
        if ($existing) {
          // Save exactly what HR entered (do not silently reduce below 'used')
          update('leave_balances', ['allocated' => $bal], 'id=?', [$existing['id']]);
        } else {
          insert('leave_balances', ['employee_id'=>$id, 'leave_type_id'=>$lt['id'], 'year'=>$year, 'allocated'=>$bal, 'used'=>0]);
        }
      }
    }
    log_activity('Employee Edited',$data['first_name'].' '.$data['last_name']);
    set_flash('success','Employee updated successfully.');
    redirect(APP_URL.'modules/employees/view.php?id='.$id);
  }
  $newId=insert('employees',$data);
  // auto-create login account
  $username=strtolower(explode('@',$data['email'])[0] ?: $data['employee_code']);
  $tmpPass='Spotcomm@'.rand(1000,9999);
  insert('users',['employee_id'=>$newId,'username'=>$username,'email'=>$data['email'],
    'password_hash'=>hash_password($tmpPass),'role'=>$_POST['role']??'employee','must_change_password'=>1]);
  // seed leave balances — use custom values from form if provided, else defaults
  foreach(fetch_all("SELECT * FROM leave_types") as $lt){
    $customBal = $_POST['leave_'.$lt['id']] ?? null;
    $bal = ($customBal !== null && $customBal !== '') ? (float)$customBal : $lt['default_balance'];
    if ($bal > 0 || $customBal !== null) {
      insert('leave_balances',['employee_id'=>$newId,'leave_type_id'=>$lt['id'],'year'=>date('Y'),'allocated'=>$bal,'used'=>0]);
    }
  }
  log_activity('Employee Added',$data['first_name'].' '.$data['last_name'].' (login: '.$username.', temp pass: '.$tmpPass.')');

  // Send WhatsApp notification to new employee
  if (!empty($data['whatsapp'])) {
    $waMsg = "🎉 *Welcome to Spotcomm Global!*\n\n";
    $waMsg .= "Hi {$data['first_name']}, your HRIS account has been created.\n\n";
    $waMsg .= "🔐 *Login Details:*\n";
    $waMsg .= "URL: " . APP_URL . "index.php\n";
    $waMsg .= "Username: $username\n";
    $waMsg .= "Password: $tmpPass\n\n";
    $waMsg .= "Please change your password after first login.\n\n";
    $waMsg .= "_Spotcomm Global HR_";
    send_whatsapp($data['whatsapp'], $waMsg);
  }
  // Send Email notification to new employee
  if (!empty($data['email'])) {
    $emailBody = "Dear {$data['first_name']},\n\nWelcome to Spotcomm Global! Your HRIS portal account has been created.\n\nLogin Details:\nURL: " . APP_URL . "index.php\nUsername: $username\nPassword: $tmpPass\n\nPlease login and change your password immediately.\n\nHR Department\nSpotcomm Global";
    send_email($data['email'], 'Welcome to Spotcomm HRIS - Your Login Details', $emailBody);
  }

  set_flash('success',"Employee added! Login details sent via WhatsApp & Email. Username: <strong>$username</strong> · Password: <strong>$tmpPass</strong>");
  redirect(APP_URL.'modules/employees/view.php?id='.$newId);
}

// Now render the page (only for GET requests)
auth_header($e?'Edit Employee':'Add Employee');
$title=$e?'Edit Employee':'Add Employee';
?>
<div class="page-head"><div><h1><?= $title ?></h1><div class="sub"><?= $e?$e['employee_code']:'New employee record' ?></div></div>
  <a href="<?= url('modules/employees/index.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Back</a></div>

<form method="post" class="card card-pad">
  <?= csrf_field() ?>
  <h3 class="section-title" style="margin-bottom:12px">Personal Details</h3>
  <div class="grid cols-3">
    <div><label>First Name</label><input type="text" name="first_name" class="form-control" value="<?= e($e['first_name']??'') ?>" required></div>
    <div><label>Last Name</label><input type="text" name="last_name" class="form-control" value="<?= e($e['last_name']??'') ?>" required></div>
    <div><label>Employee Code</label><input type="text" name="employee_code" class="form-control" value="<?= e($e['employee_code']??'SCG-'.str_pad((int)fetch_one("SELECT COUNT(*) c FROM employees")['c']+1,3,'0',STR_PAD_LEFT)) ?>" required></div>
    <div><label>Email</label><input type="email" name="email" class="form-control" value="<?= e($e['email']??'') ?>"></div>
    <div><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= e($e['phone']??'') ?>"></div>
    <div><label>WhatsApp Number</label><input type="text" name="whatsapp" class="form-control" value="<?= e($e['whatsapp']??'') ?>" placeholder="923001234567 (with country code)"></div>
    <div><label>CNIC</label><input type="text" name="cnic" class="form-control" value="<?= e($e['cnic']??'') ?>"></div>
    <div><label>Date of Birth</label><input type="date" name="dob" class="form-control" value="<?= e($e['dob']??'') ?>"></div>
    <div><label>Gender</label><select name="gender" class="form-select"><option value="">Select</option><?php foreach(['Male','Female','Other'] as $g): ?><option <?= ($e['gender']??'')==$g?'selected':'' ?>><?= $g ?></option><?php endforeach; ?></select></div>
    <div><label>Marital Status</label><select name="marital_status" class="form-select"><option value="">Select</option><?php foreach(['Single','Married','Divorced','Widowed'] as $g): ?><option <?= ($e['marital_status']??'')==$g?'selected':'' ?>><?= $g ?></option><?php endforeach; ?></select></div>
    <div style="grid-column:span 3"><label>Address</label><textarea name="address" class="form-control" rows="2"><?= e($e['address']??'') ?></textarea></div>
  </div>

  <div class="divider"></div>
  <h3 class="section-title" style="margin-bottom:12px">Employment Details</h3>
  <div class="grid cols-3">
    <div><label>Department</label><select name="department_id" class="form-select"><?php foreach(departments_list() as $d): ?><option value="<?= $d['id'] ?>" <?= ($e['department_id']??0)==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Designation</label><input type="text" name="designation" class="form-control" value="<?= e($e['designation']??'') ?>"></div>
    <div><label>Reporting Manager</label><select name="manager_id" class="form-select"><option value="0">None</option><?php foreach(fetch_all("SELECT id,full_name FROM employees WHERE status='Active' AND id!=? ORDER BY full_name",[$id?:0]) as $m): ?><option value="<?= $m['id'] ?>" <?= ($e['manager_id']??0)==$m['id']?'selected':'' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Employment Type</label><select name="employment_type" class="form-select"><?php foreach(['Permanent','Contract','Probation','Intern'] as $t): ?><option <?= ($e['employment_type']??'Probation')==$t?'selected':'' ?>><?= $t ?></option><?php endforeach; ?></select></div>
    <div><label>Joining Date</label><input type="date" name="joining_date" class="form-control" value="<?= e($e['joining_date']??today()) ?>"></div>
    <div><label>Basic Salary (PKR)</label><input type="number" name="basic_salary" class="form-control" value="<?= e($e['basic_salary']??0) ?>"></div>
    <div><label>Shift Start</label><input type="time" name="shift_start" class="form-control" value="<?= e($e['shift_start']??'09:00') ?>"></div>
    <div><label>Shift End</label><input type="time" name="shift_end" class="form-control" value="<?= e($e['shift_end']??'18:00') ?>"></div>
    <div><label>Weekly Off Day(s)</label>
      <div style="display:flex;flex-wrap:wrap;gap:8px;padding:8px;border:1px solid var(--border);border-radius:10px">
        <?php $offDays = explode(',', ($e['weekly_off'] ?? 'Sunday')); foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $day): ?>
          <label style="display:flex;align-items:center;gap:4px;font-size:13px"><input type="checkbox" name="weekly_off[]" value="<?= $day ?>" <?= in_array($day,$offDays)?'checked':'' ?>> <?= $day ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if(!$e): ?>
    <div><label>System Role</label><select name="role" class="form-select"><option value="employee">Employee</option><option value="manager">Manager</option><option value="hr">HR</option><option value="admin">Admin</option></select></div>
    <?php endif; ?>
  </div>

  <div class="divider"></div>
  <h3 class="section-title" style="margin-bottom:12px">Bank &amp; Emergency</h3>
  <div class="grid cols-3">
    <div><label>Bank Name</label><input type="text" name="bank_name" class="form-control" value="<?= e($e['bank_name']??'') ?>"></div>
    <div><label>Bank Account</label><input type="text" name="bank_account" class="form-control" value="<?= e($e['bank_account']??'') ?>"></div>
    <div><label>Emergency Contact Name</label><input type="text" name="emergency_name" class="form-control" value="<?= e($e['emergency_name']??'') ?>"></div>
    <div><label>Emergency Phone</label><input type="text" name="emergency_phone" class="form-control" value="<?= e($e['emergency_phone']??'') ?>"></div>
  </div>

  <div class="divider"></div>
  <h3 class="section-title" style="margin-bottom:12px">Leave Balance Assignment</h3>
  <?php
  // Load this employee's CURRENT allocated balances (so the form shows real
  // values when editing, not the default every time). Empty for new employees.
  $empBalances = [];
  if ($e) {
    foreach (fetch_all("SELECT leave_type_id, allocated FROM leave_balances WHERE employee_id=? AND year=?", [$id, date('Y')]) as $eb) {
      $empBalances[$eb['leave_type_id']] = $eb['allocated'];
    }
  }
  ?>
  <div class="grid cols-3">
    <?php foreach(fetch_all("SELECT * FROM leave_types ORDER BY id") as $lt):
      $curBal = array_key_exists($lt['id'], $empBalances) ? $empBalances[$lt['id']] : $lt['default_balance'];
    ?>
    <div><label><?= e($lt['name']) ?> (days)</label><input type="number" step="0.5" name="leave_<?= $lt['id'] ?>" class="form-control" value="<?= e($curBal) ?>" placeholder="<?= e($lt['default_balance']) ?>"></div>
    <?php endforeach; ?>
  </div>

  <div style="margin-top:18px"><button class="btn btn-primary"><i class="fa-solid fa-save"></i> <?= $e?'Update':'Add' ?> Employee</button>
    <a href="<?= url('modules/employees/index.php') ?>" class="btn btn-light">Cancel</a></div>
</form>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
