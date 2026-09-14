<?php
/**
 * ============================================================================
 * PAYSLIP - printable payslip view
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Payslip');
$id = (int)($_GET['id'] ?? 0);

$slip = fetch_one("SELECT pi.*, pr.month, pr.year, pr.status, e.full_name, e.employee_code, e.designation, d.name dept, e.bank_name, e.bank_account
                   FROM payroll_items pi
                   JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
                   JOIN employees e ON e.id=pi.employee_id
                   LEFT JOIN departments d ON d.id=e.department_id
                   WHERE pi.id=?", [$id]);

if (!$slip) { set_flash('danger','Payslip not found.'); redirect(APP_URL.'dashboard.php'); }
// employees can only view their own
if (!can_edit_all() && $slip['employee_id'] != current_employee_id()) {
    require_login('hr');
}
?>
<div class="page-head">
  <div><h1>Salary Slip</h1><div class="sub"><?= month_name($slip['month']).' '.$slip['year'] ?></div></div>
  <button class="btn btn-outline" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</div>

<div class="card card-pad" style="max-width:760px;margin:0 auto">
  <div class="flex between center" style="border-bottom:3px solid var(--primary);padding-bottom:16px;margin-bottom:16px">
    <div>
      <img src="<?= asset('img/logo.png') ?>" style="height:44px">
    </div>
    <div class="right small muted">
      <strong>Spotcomm Global</strong><br>
      HR &amp; Payroll Department<br>
      Generated: <?= format_date(today()) ?>
    </div>
  </div>
  <div class="grid cols-2" style="margin-bottom:18px">
    <div class="kv"><span class="k">Employee</span><span class="v"><?= e($slip['full_name']) ?></span></div>
    <div class="kv"><span class="k">Employee Code</span><span class="v"><?= e($slip['employee_code']) ?></span></div>
    <div class="kv"><span class="k">Designation</span><span class="v"><?= e($slip['designation']) ?></span></div>
    <div class="kv"><span class="k">Department</span><span class="v"><?= e($slip['dept']) ?></span></div>
    <div class="kv"><span class="k">Pay Period</span><span class="v"><?= month_name($slip['month']).' '.$slip['year'] ?></span></div>
    <div class="kv"><span class="k">Status</span><span class="v"><span class="badge badge-<?= $slip['status']==='paid'?'green':'amber' ?>"><?= ucfirst($slip['status']) ?></span></span></div>
  </div>

  <div class="grid cols-2">
    <div class="card card-pad">
      <h3 class="section-title" style="color:var(--green);margin-bottom:12px">Earnings</h3>
      <div class="kv"><span class="k">Basic Salary (<?= $slip['present_days'] ?> days)</span><span class="v"><?= money($slip['basic_salary']) ?></span></div>
      <div class="kv"><span class="k">Allowances</span><span class="v"><?= money($slip['allowances']) ?></span></div>
      <div class="kv"><span class="k">Overtime</span><span class="v"><?= money($slip['overtime_pay']) ?></span></div>
      <div class="kv"><span class="k">Bonus</span><span class="v"><?= money($slip['bonus']) ?></span></div>
      <div class="divider"></div>
      <div class="kv"><span class="k bold">Gross Pay</span><span class="v bold" style="color:var(--green)"><?= money($slip['gross_pay']) ?></span></div>
    </div>
    <div class="card card-pad">
      <h3 class="section-title" style="color:var(--red);margin-bottom:12px">Deductions</h3>
      <div class="kv"><span class="k">Tax</span><span class="v"><?= money($slip['tax']) ?></span></div>
      <div class="kv"><span class="k">Loan Installment</span><span class="v"><?= money($slip['loan_deduction']) ?></span></div>
      <div class="kv"><span class="k">Other Deductions</span><span class="v"><?= money($slip['other_deductions']) ?></span></div>
      <div class="divider"></div>
      <div class="kv"><span class="k bold">Total Deductions</span><span class="v bold" style="color:var(--red)"><?= money($slip['total_deductions']) ?></span></div>
    </div>
  </div>

  <div style="background:var(--grad);color:#fff;border-radius:12px;padding:20px;margin-top:18px;display:flex;justify-content:space-between;align-items:center">
    <div>
      <div class="small" style="opacity:.85">NET PAYABLE</div>
      <div style="font-family:'Oswald';font-size:28px"><?= money($slip['net_pay']) ?></div>
    </div>
    <div class="right small" style="opacity:.85">
      <?php if($slip['bank_name']): ?>Bank: <?= e($slip['bank_name']) ?><br>A/C: <?= e($slip['bank_account']) ?><?php endif; ?>
    </div>
  </div>
  <?php if($slip['upload_note']): ?><p class="muted small" style="margin-top:10px"><i class="fa-solid fa-circle-info"></i> <?= e($slip['upload_note']) ?></p><?php endif; ?>
  <p class="muted small center" style="margin-top:18px">This is a computer-generated payslip and does not require a physical signature.</p>
</div>
<style>@media print{.sidebar,.topbar,.page-head,.overlay{display:none!important}.main{margin:0}.content{padding:0}}</style>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
