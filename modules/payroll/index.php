<?php
/**
 * ============================================================================
 * PAYROLL MANAGEMENT — HR
 *  - Create/close monthly payroll run
 *  - Auto-calc items from attendance (present days, OT, loans, bonuses)
 *  - Upload additional Finance data via CSV (deductions, allowances)
 *  - Mark as paid
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Payroll Management', 'hr');

$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $month = (int)($_POST['month'] ?? date('m'));
    $year = (int)($_POST['year'] ?? date('Y'));

    if ($action === 'create' || $action === 'recalc') {
        // find or create run
        $run = fetch_one("SELECT * FROM payroll_runs WHERE month=? AND year=?",[$month,$year]);
        if (!$run) {
            $runId = insert('payroll_runs', ['month'=>$month,'year'=>$year,'status'=>'draft','generated_by'=>current_user_id()]);
        } else {
            $runId = $run['id'];
            db()->prepare("DELETE FROM payroll_items WHERE payroll_run_id=?")->execute([$runId]);
        }
        // build items for all active employees
        $emps = fetch_all("SELECT * FROM employees WHERE status='Active'");
        foreach ($emps as $e) {
            $att = fetch_one("SELECT SUM(CASE WHEN status IN('present','late') THEN 1 ELSE 0 END) present, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) absent, SUM(CASE WHEN status='half_day' THEN 1 ELSE 0 END) halfday, SUM(overtime_hours) ot
                              FROM attendance WHERE employee_id=? AND " . sql_month('attendance_date') . "=? AND " . sql_year('attendance_date') . "=?",
                             [$e['id'],$month,$year]);
            $present = (float)($att['present']??0) + 0.5*(float)($att['halfday']??0);
            $absent = (float)($att['absent']??0);
            $otHours = (float)($att['ot']??0);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN,$month,$year);
            $perDay = $e['basic_salary']/$daysInMonth;
            $basicEarned = round($perDay*$present,2);
            // overtime pay = perDay/8 * otHours
            $otPay = round(($perDay/8)*$otHours,2);
            // bonus this month
            $bonus = (float)fetch_one("SELECT SUM(amount) a FROM bonuses WHERE employee_id=? AND " . sql_month('applied_date') . "=? AND " . sql_year('applied_date') . "=?",[$e['id'],$month,$year])['a'];
            // loan deduction
            $loan = fetch_one("SELECT * FROM loans WHERE employee_id=? AND status='active' ORDER BY id DESC LIMIT 1",[$e['id']]);
            $loanDed = 0;
            if ($loan) {
                $loanDed = min($loan['installment'],$loan['remaining']);
            }
            $tax = round($basicEarned*0.02,2); // placeholder tax 2%
            $gross = $basicEarned + $otPay + $bonus;
            $deductions = $loanDed + $tax;
            insert('payroll_items',[
                'payroll_run_id'=>$runId,'employee_id'=>$e['id'],
                'basic_salary'=>$basicEarned,'overtime_pay'=>$otPay,'bonus'=>$bonus,
                'loan_deduction'=>$loanDed,'tax'=>$tax,'other_deductions'=>0,
                'gross_pay'=>$gross,'total_deductions'=>$deductions,'net_pay'=>$gross-$deductions,
                'present_days'=>$present,'absent_days'=>$absent,
            ]);
        }
        log_activity('Payroll Processed', month_name($month).' '.$year);
        set_flash('success','Payroll processed for '.month_name($month).' '.$year.'. Review and upload Finance adjustments if needed.');

    } elseif ($action==='delete_item') {
        db()->prepare("DELETE FROM payroll_items WHERE id=?")->execute([(int)$_POST['item_id']]);
        set_flash('success','Payroll item deleted.');
        redirect(APP_URL.'modules/payroll/index.php?m='.$month.'&y='.$year);
    } elseif ($action==='mark_paid') {
        update('payroll_runs',['status'=>'paid','processed_at'=>now()],'month=? AND year=?',[$month,$year]);
        // close loans that finished
        foreach (fetch_all("SELECT id FROM loans WHERE status='active'") as $l) {
            $loan = fetch_one("SELECT * FROM loans WHERE id=?",[$l['id']]);
            $newRem = $loan['remaining'] - $loan['installment'];
            $paid = $loan['paid_installments']+1;
            if ($newRem<=0) update('loans',['remaining'=>0,'paid_installments'=>$paid,'status'=>'closed'],'id=?',[$l['id']]);
            else update('loans',['remaining'=>$newRem,'paid_installments'=>$paid],'id=?',[$l['id']]);
        }
        set_flash('success','Payroll marked as PAID for '.month_name($month).' '.$year.'.');
    }
    redirect(APP_URL.'modules/payroll/index.php?m='.$month.'&y='.$year);
}

$m = (int)($_GET['m'] ?? date('m'));
$y = (int)($_GET['y'] ?? date('Y'));
$run = fetch_one("SELECT * FROM payroll_runs WHERE month=? AND year=?",[$m,$y]);
$items = $run ? fetch_all("SELECT pi.*, e.full_name, e.employee_code FROM payroll_items pi JOIN employees e ON e.id=pi.employee_id WHERE pi.payroll_run_id=? ORDER BY e.full_name",[$run['id']]) : [];
$totNet = array_sum(array_column($items,'net_pay'));
$totGross = array_sum(array_column($items,'gross_pay'));
$totDed = array_sum(array_column($items,'total_deductions'));
?>
<div class="page-head">
  <div><h1>Payroll Management</h1><div class="sub">Process monthly payroll · Upload Finance data · Mark as paid</div></div>
</div>

<div class="card card-pad" style="margin-bottom:18px">
  <div class="flex between center wrap gap">
    <div class="flex center gap">
      <form method="get" class="flex center gap">
        <select name="m" class="form-select" style="width:auto" onchange="this.form.submit()">
          <?php for($i=1;$i<=12;$i++): ?><option value="<?= $i ?>" <?= $i==$m?'selected':'' ?>><?= month_name($i) ?></option><?php endfor; ?>
        </select>
        <select name="y" class="form-select" style="width:auto" onchange="this.form.submit()">
          <?php for($yr=date('Y');$yr>=2023;$yr--): ?><option <?= $yr==$y?'selected':'' ?>><?= $yr ?></option><?php endfor; ?>
        </select>
      </form>
      <span class="badge badge-<?= $run?($run['status']==='paid'?'green':'amber'):'gray' ?>"><?= $run?ucfirst($run['status']):'Not started' ?></span>
    </div>
    <div class="flex gap">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="year" value="<?= $y ?>">
        <input type="hidden" name="action" value="<?= $run?'recalc':'create' ?>">
        <button class="btn btn-primary" data-confirm="Process payroll for <?= month_name($m) ?> <?= $y ?>? This auto-calculates from attendance, loans and bonuses."><i class="fa-solid fa-calculator"></i> <?= $run?'Re-calculate':'Process Payroll' ?></button>
      </form>
      <?php if($run && $run['status']!=='paid'): ?>
      <button class="btn btn-outline" onclick="openModal('uploadModal')"><i class="fa-solid fa-upload"></i> Upload Finance Data</button>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="year" value="<?= $y ?>"><input type="hidden" name="action" value="mark_paid">
        <button class="btn btn-success" data-confirm="Mark this payroll as PAID? This will deduct loan installments."><i class="fa-solid fa-check"></i> Mark Paid</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if($items): ?>
<div class="grid cols-3" style="margin-bottom:18px">
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="num"><?= money($totNet) ?></div><div class="lbl">Total Net Payable</div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-money-bill-trend-up"></i></div><div><div class="num"><?= money($totGross) ?></div><div class="lbl">Total Gross</div></div></div>
  <div class="stat"><div class="ico bg-red"><i class="fa-solid fa-minus"></i></div><div><div class="num"><?= money($totDed) ?></div><div class="lbl">Total Deductions</div></div></div>
</div>
<?php endif; ?>

<div class="card card-pad">
  <h3 class="section-title" style="margin-bottom:14px">Payroll Items — <?= month_name($m).' '.$y ?></h3>
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>Employee</th><th>Code</th><th>Days</th><th>Basic</th><th>OT</th><th>Bonus</th><th>Loan Ded.</th><th>Tax</th><th>Net Pay</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if(!$items): echo '<tr><td colspan="10" class="empty"><i class="fa-solid fa-file-circle-exclamation"></i>No payroll processed yet. Click "Process Payroll" to begin.</td></tr>';
      else: foreach($items as $it): ?>
        <tr>
          <td class="bold"><?= e($it['full_name']) ?></td>
          <td class="small muted"><?= e($it['employee_code']) ?></td>
          <td><?= $it['present_days'] ?></td>
          <td><?= money($it['basic_salary']) ?></td>
          <td><?= $it['overtime_pay']?money($it['overtime_pay']):'—' ?></td>
          <td><?= $it['bonus']?'<span class="badge badge-green">'.money($it['bonus']).'</span>':'—' ?></td>
          <td><?= $it['loan_deduction']?'<span class="badge badge-red">'.money($it['loan_deduction']).'</span>':'—' ?></td>
          <td><?= money($it['tax']) ?></td>
          <td class="bold"><?= money($it['net_pay']) ?></td>
          <td>
            <div class="flex gap" style="gap:4px">
              <a href="<?= url('modules/payroll/slip.php?id='.$it['id']) ?>" class="btn btn-sm btn-light" title="View Slip"><i class="fa-solid fa-eye"></i></a>
              <?php if($run && $run['status']!=='paid'): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                <input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="year" value="<?= $y ?>">
                <button class="btn btn-sm btn-light" data-confirm="Delete this payroll item?"><i class="fa-solid fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Finance upload modal -->
<div class="modal-bg" id="uploadModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-upload"></i> Upload Finance Department Data</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('uploadModal')"></i></div>
    <form method="post" action="upload.php" enctype="multipart/form-data">
      <div class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="month" value="<?= $m ?>"><input type="hidden" name="year" value="<?= $y ?>">
        <p class="muted small">Upload a CSV from Finance to update allowances, bonuses or additional deductions. <br>Format: <code>employee_code,allowance,bonus,other_deduction</code></p>
        <label>CSV File</label><input type="file" name="csv" class="form-control" accept=".csv" required>
      </div>
      <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('uploadModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload &amp; Apply</button></div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
