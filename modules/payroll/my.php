<?php
/**
 * ============================================================================
 * MY PAYSLIPS — employee view (read-only) of processed payroll,
 * salary deductions, loan balances, bonuses.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('My Payslips');
$empId = current_employee_id();

$payslips = fetch_all(
    "SELECT pi.*, pr.month, pr.year, pr.status
     FROM payroll_items pi JOIN payroll_runs pr ON pr.id=pi.payroll_run_id
     WHERE pi.employee_id=? ORDER BY pr.year DESC, pr.month DESC", [$empId]
);
$year = date('Y');
$loanRow = fetch_one("SELECT SUM(remaining) r FROM loans WHERE employee_id=? AND status='active'", [$empId]);
$bonusRow = fetch_one("SELECT SUM(amount) a FROM bonuses WHERE employee_id=? AND " . sql_year('applied_date') . "=?", [$empId,$year]);
$loans = fetch_all("SELECT * FROM loans WHERE employee_id=? ORDER BY id DESC",[$empId]);
?>
<div class="page-head"><div><h1>My Payslips &amp; Compensation</h1><div class="sub">Your processed salary, deductions, loans and bonuses</div></div></div>

<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="num"><?= money($bonusRow['a']??0) ?></div><div class="lbl">Bonuses (<?= $year ?>)</div></div></div>
  <div class="stat"><div class="ico bg-accent"><i class="fa-solid fa-hand-holding-dollar"></i></div><div><div class="num"><?= money($loanRow['r']??0) ?></div><div class="lbl">Outstanding Loan</div></div></div>
  <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-file-invoice"></i></div><div><div class="num"><?= count($payslips) ?></div><div class="lbl">Payslips Processed</div></div></div>
</div>

<div class="card card-pad">
  <h3 class="section-title" style="margin-bottom:14px">Salary Slips</h3>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Period</th><th>Days Paid</th><th>Gross</th><th>Deductions</th><th>Net Pay</th><th>Status</th><th>Slip</th></tr></thead>
      <tbody>
      <?php if(!$payslips): echo '<tr><td colspan="7" class="empty"><i class="fa-solid fa-file-circle-exclamation"></i>No payslips processed yet</td></tr>';
      else: foreach($payslips as $p): ?>
        <tr>
          <td class="bold"><?= month_name($p['month']).' '.$p['year'] ?></td>
          <td><?= $p['present_days'] ?></td>
          <td><?= money($p['gross_pay']) ?></td>
          <td><span class="badge badge-red"><?= money($p['total_deductions']) ?></span></td>
          <td class="bold"><?= money($p['net_pay']) ?></td>
          <td><span class="badge badge-<?= $p['status']==='paid'?'green':'amber' ?>"><?= ucfirst($p['status']) ?></span></td>
          <td><a href="<?= url('modules/payroll/slip.php?id='.$p['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if($loans): ?>
<div class="card card-pad" style="margin-top:18px">
  <h3 class="section-title" style="margin-bottom:14px">My Loans</h3>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Amount</th><th>Installment</th><th>Paid</th><th>Remaining</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach($loans as $l): $pct=$l['total_installments']>0?($l['paid_installments']/$l['total_installments']*100):0; ?>
      <tr>
        <td><?= money($l['amount']) ?></td>
        <td><?= money($l['installment']) ?></td>
        <td><?= $l['paid_installments'].'/'.$l['total_installments'] ?></td>
        <td class="bold"><?= money($l['remaining']) ?>
          <div class="progress" style="margin-top:4px"><div class="bar" style="width:<?= $pct ?>%"></div></div>
        </td>
        <td><span class="badge badge-<?= $l['status']==='active'?'amber':'green' ?>"><?= ucfirst($l['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
