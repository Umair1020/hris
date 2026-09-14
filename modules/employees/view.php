<?php
/**
 * ============================================================================
 * EMPLOYEE PROFILE — detail view
 * HR/manager/full self; others restricted. Self can edit personal info only.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Employee Profile');
$id=(int)($_GET['id']??0);
$e=fetch_one("SELECT e.*, d.name dept_name, m.full_name manager_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN employees m ON m.id=e.manager_id WHERE e.id=?",[$id]);
if(!$e){set_flash('danger','Employee not found.');redirect(APP_URL.'modules/employees/index.php');}

$isSelf=$e['id']==current_employee_id();
if(!can_access_employee($e['id'])){ set_flash('danger','Access denied.'); redirect(APP_URL.'dashboard.php'); }

// Fetch login account (for HR Login Access card)
$loginUser = fetch_one("SELECT * FROM users WHERE employee_id=?", [$e['id']]);
$suggestedUsername = strtolower(explode('@', $e['email'] ?: $e['employee_code'])[0]);

$year=date('Y');
$bal=fetch_all("SELECT lb.*,lt.name,lt.code FROM leave_balances lb JOIN leave_types lt ON lt.id=lb.leave_type_id WHERE lb.employee_id=? AND lb.year=?",[$e['id'],$year]);
$loans=fetch_all("SELECT * FROM loans WHERE employee_id=?",[$e['id']]);
$bonuses=fetch_all("SELECT * FROM bonuses WHERE employee_id=? ORDER BY id DESC LIMIT 5",[$e['id']]);
$benefits=fetch_all("SELECT * FROM employee_benefits WHERE employee_id=? AND is_active=1 ORDER BY benefit_type",[$e['id']]);
$benTotal = array_sum(array_column($benefits, 'amount'));
?>
<div class="page-head">
  <div><h1>Employee Profile</h1><div class="sub"><?= e($e['employee_code']) ?></div></div>
  <a href="<?= url('modules/employees/index.php') ?>" class="btn btn-light"><i class="fa-solid fa-arrow-left"></i> Directory</a>
</div>

<div class="card card-pad" style="background:var(--grad);color:#fff;margin-bottom:18px">
  <div class="flex center gap">
    <div class="avatar lg" style="background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4)"><?= e(initials($e['full_name'])) ?></div>
    <div>
      <h2 style="margin:0;font-size:24px"><?= e($e['full_name']) ?></h2>
      <div style="opacity:.9"><?= e($e['designation']?:'—') ?> · <?= e($e['dept_name']?:'—') ?></div>
      <div style="margin-top:6px"><span class="badge" style="background:rgba(255,255,255,.2);color:#fff"><?= e($e['employment_type']) ?></span>
      <span class="badge" style="background:rgba(255,255,255,.2);color:#fff"><?= $e['status'] ?></span></div>
    </div>
    <div style="margin-left:auto">
      <?php if(is_hr()): ?><a href="<?= url('modules/employees/form.php?id='.$e['id']) ?>" class="btn btn-light"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?>
      <?php if($isSelf): ?><a href="<?= url('modules/profile/edit.php') ?>" class="btn btn-light"><i class="fa-solid fa-user-pen"></i> Edit My Info</a><?php endif; ?>
    </div>
  </div>
</div>

<div class="grid cols-3">
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-user"></i> Personal Information</h3>
    <div class="kv"><span class="k">Email</span><span class="v"><?= e($e['email']?:'—') ?></span></div>
    <div class="kv"><span class="k">Phone</span><span class="v"><?= e($e['phone']?:'—') ?></span></div>
    <div class="kv"><span class="k">WhatsApp</span><span class="v"><?php if($e['whatsapp']): ?><i class="fa-brands fa-whatsapp" style="color:#25D366"></i> <?= e($e['whatsapp']) ?><?php else: ?>—<?php endif; ?></span></div>
    <div class="kv"><span class="k">CNIC</span><span class="v"><?= e($e['cnic']?:'—') ?></span></div>
    <div class="kv"><span class="k">Date of Birth</span><span class="v"><?= format_date($e['dob']) ?></span></div>
    <div class="kv"><span class="k">Gender</span><span class="v"><?= e($e['gender']?:'—') ?></span></div>
    <div class="kv"><span class="k">Marital Status</span><span class="v"><?= e($e['marital_status']?:'—') ?></span></div>
    <div class="kv"><span class="k">Address</span><span class="v small"><?= e($e['address']?:'—') ?></span></div>
    <div class="kv"><span class="k">Emergency Contact</span><span class="v small"><?= e($e['emergency_name']?:'—') ?> <?= e($e['emergency_phone']?:'') ?></span></div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-briefcase"></i> Employment</h3>
    <div class="kv"><span class="k">Joining Date</span><span class="v"><?= format_date($e['joining_date']) ?></span></div>
    <div class="kv"><span class="k">Employment Type</span><span class="v"><?= e($e['employment_type']) ?></span></div>
    <div class="kv"><span class="k">Department</span><span class="v"><?= e($e['dept_name']?:'—') ?></span></div>
    <div class="kv"><span class="k">Manager</span><span class="v"><?= e($e['manager_name']?:'—') ?></span></div>
    <?php if(is_hr()): ?><div class="kv"><span class="k">Basic Salary</span><span class="v"><?= money($e['basic_salary']) ?></span></div><?php endif; ?>
    <div class="kv"><span class="k">Shift</span><span class="v"><?= date('h:i A',strtotime($e['shift_start'])) ?> - <?= date('h:i A',strtotime($e['shift_end'])) ?></span></div>
    <div class="kv"><span class="k">Bank</span><span class="v small"><?= e($e['bank_name']?:'—') ?> <?= e($e['bank_account']?:'') ?></span></div>
  </div>
  <div class="card card-pad">
    <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-calendar-check"></i> Leave Balances (<?= $year ?>)</h3>
    <?php if(!$bal): echo '<p class="muted small">No balances allocated.</p>'; endif;
    foreach($bal as $b): $rem=$b['allocated']-$b['used']; $pct=$b['allocated']>0?($b['used']/$b['allocated']*100):0; ?>
      <div style="margin-bottom:10px">
        <div class="flex between center small"><span><?= e($b['name']) ?></span><span class="bold"><?= $rem ?>/<?= $b['allocated'] ?>d</span></div>
        <div class="progress"><div class="bar" style="width:<?= $pct ?>%"></div></div>
      </div>
    <?php endforeach; ?>
    <?php if(is_hr()): ?>
    <div class="divider"></div>
    <h3 class="section-title" style="margin-bottom:8px"><i class="fa-solid fa-hand-holding-dollar"></i> Active Loans</h3>
    <?php $actLoans=array_filter($loans,fn($l)=>$l['status']==='active');
    if(!$actLoans) echo '<p class="muted small">No active loans.</p>';
    foreach($actLoans as $l): ?>
      <div class="flex between center small" style="padding:6px 0"><span><?= money($l['amount']) ?></span><span class="badge badge-amber">Remaining: <?= money($l['remaining']) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php if(is_hr() && $bonuses): ?>
<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-gift"></i> Benefits & Allowances</h3>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Benefit Type</th><th>Amount</th><th>Description</th></tr></thead>
      <tbody>
      <?php if(!$benefits): echo '<tr><td colspan="3" class="muted">No benefits assigned. Add from Benefits page.</td></tr>'; endif;
      foreach($benefits as $ben): ?>
        <tr>
          <td><span class="badge badge-purple"><?= e($ben['benefit_type']) ?></span></td>
          <td class="bold"><?= money($ben['amount']) ?></td>
          <td class="small muted"><?= e($ben['description'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if($benefits): ?>
      <tr style="background:#f4f5fb">
        <td class="bold">Total Benefits</td>
        <td class="bold" style="color:#10b981"><?= money($benTotal) ?></td>
        <td></td>
      </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if(is_hr() && $bonuses): ?>
<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:12px">Recent Bonuses</h3>
  <div class="table-wrap"><table class="tbl"><thead><tr><th>Type</th><th>Amount</th><th>Reason</th><th>Date</th></tr></thead><tbody>
    <?php foreach($bonuses as $b): ?><tr><td><span class="badge badge-purple"><?= e($b['bonus_type']) ?></span></td><td><?= money($b['amount']) ?></td><td class="small"><?= e($b['reason']) ?></td><td class="small"><?= format_date($b['applied_date']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php if(is_hr()): ?>
<!-- ===================== LOGIN ACCESS CARD (HR only) ===================== -->
<div class="card card-pad" style="margin-top:18px;border-left:4px solid var(--purple)">
  <div class="flex between center wrap gap" style="margin-bottom:16px">
    <h3 class="section-title" style="margin:0"><i class="fa-solid fa-key" style="color:var(--purple)"></i> Login Access &amp; Credentials</h3>
    <span class="muted small">HR only — manage username &amp; password for this employee</span>
  </div>

  <?php if($loginUser): ?>
    <!-- ===== ACCOUNT EXISTS: show details + reset password ===== -->
    <div class="grid cols-2">
      <div>
        <div class="card card-pad" style="background:#f4f5fb">
          <div class="flex between center" style="margin-bottom:10px">
            <span class="muted small" style="text-transform:uppercase;letter-spacing:1px">Current Login</span>
            <span class="badge badge-<?= $loginUser['status']==='active'?'green':'red' ?>"><?= ucfirst($loginUser['status']) ?></span>
          </div>
          <div class="kv"><span class="k"><i class="fa-solid fa-user"></i> Username</span><span class="v bold" style="font-size:16px"><?= e($loginUser['username']) ?></span></div>
          <div class="kv"><span class="k"><i class="fa-solid fa-shield-halved"></i> Role</span><span class="v"><span class="badge badge-purple"><?= ucfirst($loginUser['role']) ?></span></span></div>
          <div class="kv"><span class="k"><i class="fa-solid fa-envelope"></i> Email</span><span class="v small"><?= e($loginUser['email']?:'—') ?></span></div>
          <div class="kv"><span class="k"><i class="fa-solid fa-clock"></i> Last Login</span><span class="v small"><?= $loginUser['last_login']?format_datetime($loginUser['last_login']):'<span class="muted">Never</span>' ?></span></div>
          <div class="kv"><span class="k"><i class="fa-solid fa-lock"></i> Password</span><span class="v muted small"><i class="fa-solid fa-asterisk"></i> Hidden (reset below)</span></div>
        </div>

        <form method="post" action="<?= url('modules/employees/credentials.php') ?>" style="margin-top:12px">
          <?= csrf_field() ?>
          <input type="hidden" name="cred_action" value="update_username">
          <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
          <label>Change Username</label>
          <div class="flex gap" style="gap:8px">
            <input type="text" name="username" class="form-control" value="<?= e($loginUser['username']) ?>" required>
            <button class="btn btn-outline" data-confirm="Change username?"><i class="fa-solid fa-check"></i></button>
          </div>
        </form>

        <form method="post" action="<?= url('modules/employees/credentials.php') ?>" style="margin-top:10px">
          <?= csrf_field() ?>
          <input type="hidden" name="cred_action" value="update_role">
          <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
          <label>System Role</label>
          <div class="flex gap" style="gap:8px">
            <select name="role" class="form-select">
              <?php foreach(['employee','manager','hr','admin'] as $r): ?>
                <option value="<?= $r ?>" <?= $loginUser['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline"><i class="fa-solid fa-check"></i></button>
          </div>
        </form>
      </div>

      <div>
        <div class="card card-pad" style="border:2px dashed var(--border)">
          <h3 class="section-title" style="margin-bottom:6px"><i class="fa-solid fa-rotate-right"></i> Reset / Set Password</h3>
          <p class="muted small" style="margin-bottom:14px">Set a new password for this employee. They can log in immediately with it.</p>
          <form method="post" action="<?= url('modules/employees/credentials.php') ?>" id="pwdForm">
            <?= csrf_field() ?>
            <input type="hidden" name="cred_action" value="reset_password">
            <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
            <label>New Password</label>
            <div class="flex gap" style="gap:8px;margin-bottom:10px">
              <input type="text" name="password" id="pwdInput" class="form-control" value="" placeholder="Enter password or generate" required>
              <button type="button" class="btn btn-light" onclick="genPwd()" title="Generate random password"><i class="fa-solid fa-dice"></i></button>
              <button type="button" class="btn btn-light" onclick="togglePwd()" title="Show/Hide"><i class="fa-solid fa-eye" id="pwdEye"></i></button>
            </div>
            <button class="btn btn-primary btn-block" data-confirm="Reset this employee's password?"><i class="fa-solid fa-rotate-right"></i> Reset Password</button>
          </form>

          <div class="divider"></div>
          <div style="background:rgba(125,62,242,.08);border-radius:10px;padding:12px">
            <div class="muted small bold" style="margin-bottom:6px"><i class="fa-solid fa-circle-info"></i> After resetting, give the employee:</div>
            <div class="kv" style="font-size:13px"><span class="k">URL</span><span class="v small"><?= APP_URL ?>index.php</span></div>
            <div class="kv" style="font-size:13px"><span class="k">Username</span><span class="v bold"><?= e($loginUser['username']) ?></span></div>
            <div class="kv" style="font-size:13px"><span class="k">Password</span><span class="v bold" id="pwdDisplay">(set above)</span></div>
          </div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- ===== NO ACCOUNT YET: create one ===== -->
    <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation"></i> This employee has no login account yet. Create one below.</div>
    <div style="max-width:520px">
      <form method="post" action="<?= url('modules/employees/credentials.php') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="cred_action" value="create_account">
        <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
        <div class="grid cols-2">
          <div><label>Username</label><input type="text" name="username" class="form-control" value="<?= e($suggestedUsername) ?>" required></div>
          <div><label>Role</label><select name="role" class="form-select"><?php foreach(['employee','manager','hr','admin'] as $r): ?><option value="<?= $r ?>" <?= $r==='employee'?'selected':'' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></div>
        </div>
        <div style="margin-top:12px"><label>Password</label>
          <div class="flex gap" style="gap:8px">
            <input type="text" name="password" id="pwdInput" class="form-control" placeholder="Enter password or generate" required>
            <button type="button" class="btn btn-light" onclick="genPwd()"><i class="fa-solid fa-dice"></i> Generate</button>
          </div>
        </div>
        <div style="margin-top:16px"><button class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Create Login Account</button></div>
      </form>
    </div>
  <?php endif; ?>
</div>
<script>
function genPwd(){
  var chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$';
  var p=''; for(var i=0;i<10;i++) p+=chars.charAt(Math.floor(Math.random()*chars.length));
  var inp=document.getElementById('pwdInput'); inp.value=p; inp.type='text';
  var disp=document.getElementById('pwdDisplay'); if(disp) disp.textContent=p;
}
function togglePwd(){
  var inp=document.getElementById('pwdInput'); var eye=document.getElementById('pwdEye');
  if(inp.type==='text'){ inp.type='password'; eye.className='fa-solid fa-eye-slash'; }
  else { inp.type='text'; eye.className='fa-solid fa-eye'; }
}
</script>
  <?php if($loginUser): ?>
    <div class="divider"></div>
    <div style="text-align:center">
      <a href="<?= url('modules/employees/qr-card.php?id='.$e['id']) ?>" class="btn btn-purple btn-block"><i class="fa-solid fa-id-card"></i> View / Print QR ID Card</a>
      <p class="muted small" style="margin-top:6px">Employee scans this QR to mark attendance from their phone</p>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
