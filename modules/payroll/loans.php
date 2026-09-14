<?php
/**
 * ============================================================================
 * LOAN MANAGEMENT — HR can add/edit/delete loans for employees
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['loan_action'] ?? '';

    if ($action === 'add') {
        insert('loans', [
            'employee_id' => (int)$_POST['employee_id'],
            'amount' => (float)$_POST['amount'],
            'installment' => (float)$_POST['installment'],
            'total_installments' => (int)$_POST['total_installments'],
            'remaining' => (float)$_POST['amount'],
            'reason' => clean($_POST['reason']),
            'status' => 'active',
            'start_date' => clean($_POST['start_date']) ?: today(),
        ]);
        log_activity('Loan Added', 'Amount: ' . $_POST['amount']);
        set_flash('success', 'Loan added successfully!');
    } elseif ($action === 'edit') {
        update('loans', [
            'amount' => (float)$_POST['amount'],
            'installment' => (float)$_POST['installment'],
            'total_installments' => (int)$_POST['total_installments'],
            'remaining' => (float)$_POST['remaining'],
            'reason' => clean($_POST['reason']),
            'status' => clean($_POST['status']),
        ], 'id = ?', [(int)$_POST['loan_id']]);
        set_flash('success', 'Loan updated!');
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM loans WHERE id=?")->execute([(int)$_POST['loan_id']]);
        set_flash('success', 'Loan deleted.');
    }
    redirect(APP_URL . 'modules/payroll/loans.php');
}

$loans = fetch_all("SELECT l.*, e.full_name, e.employee_code FROM loans l JOIN employees e ON e.id=l.employee_id ORDER BY l.status, l.id DESC");
$employees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");
auth_header('Loan Management');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-hand-holding-dollar"></i> Loan Management</h1>
    <div class="sub">Track employee loans &amp; remaining balances</div></div>
  <button class="btn btn-primary" onclick="openModal('loanModal')"><i class="fa-solid fa-plus"></i> Add Loan</button>
</div>

<div class="grid cols-3" style="margin-bottom:18px">
  <?php
  $totalActive = fetch_one("SELECT SUM(remaining) r, COUNT(*) c FROM loans WHERE status='active'");
  ?>
  <div class="stat"><div class="ico bg-amber"><i class="fa-solid fa-coins"></i></div><div><div class="num"><?= money($totalActive['r'] ?? 0) ?></div><div class="lbl">Total Outstanding</div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-list-check"></i></div><div><div class="num"><?= $totalActive['c'] ?? 0 ?></div><div class="lbl">Active Loans</div></div></div>
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-check-double"></i></div><div><div class="num"><?= count(array_filter($loans, fn($l) => $l['status']==='closed')) ?></div><div class="lbl">Closed Loans</div></div></div>
</div>

<div class="card card-pad">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Employee</th><th>Amount</th><th>Installment</th><th>Paid</th><th>Remaining</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$loans): echo '<tr><td colspan="8" class="empty"><i class="fa-solid fa-hand-holding-dollar"></i>No loans yet</td></tr>'; endif;
    foreach($loans as $l): ?>
      <tr>
        <td class="bold"><?= e($l['full_name']) ?><div class="muted small"><?= e($l['employee_code']) ?></div></td>
        <td><?= money($l['amount']) ?></td>
        <td><?= money($l['installment']) ?>/mo</td>
        <td><span class="badge badge-blue"><?= $l['paid_installments'] ?>/<?= $l['total_installments'] ?></span></td>
        <td class="bold"><?= money($l['remaining']) ?></td>
        <td class="small muted"><?= e($l['reason'] ?: '—') ?></td>
        <td><span class="badge badge-<?= $l['status']==='active'?'amber':'green' ?>"><?= ucfirst($l['status']) ?></span></td>
        <td>
          <button class="btn btn-sm btn-light" onclick='editLoan(<?= json_encode($l) ?>)'><i class="fa-solid fa-pen"></i></button>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="loan_action" value="delete"><input type="hidden" name="loan_id" value="<?= $l['id'] ?>">
            <button class="btn btn-sm btn-light" data-confirm="Delete loan?"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Add Loan Modal -->
<div class="modal-bg" id="loanModal"><div class="modal"><div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Add Loan</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('loanModal')"></i></div>
<form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="loan_action" value="add">
  <div style="margin-bottom:12px"><label>Employee *</label><select name="employee_id" class="form-select" required>
    <?php foreach($employees as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'].' — '.$emp['full_name']) ?></option><?php endforeach; ?>
  </select></div>
  <div class="grid cols-2">
    <div><label>Loan Amount (PKR) *</label><input type="number" name="amount" class="form-control" required></div>
    <div><label>Monthly Installment (PKR) *</label><input type="number" name="installment" class="form-control" required></div>
    <div><label>Total Installments *</label><input type="number" name="total_installments" class="form-control" required></div>
    <div><label>Start Date</label><input type="date" name="start_date" class="form-control" value="<?= today() ?>"></div>
  </div>
  <div style="margin-top:12px"><label>Reason / Purpose</label><input type="text" name="reason" class="form-control" placeholder="e.g. Personal Loan, Salary Advance"></div>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('loanModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add Loan</button></div></form>
</div></div>

<!-- Edit Loan Modal -->
<div class="modal-bg" id="editLoanModal"><div class="modal"><div class="modal-head"><h3><i class="fa-solid fa-pen"></i> Edit Loan</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('editLoanModal')"></i></div>
<form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="loan_action" value="edit"><input type="hidden" name="loan_id" id="el_id">
  <div class="grid cols-2">
    <div><label>Amount</label><input type="number" name="amount" id="el_amount" class="form-control"></div>
    <div><label>Installment</label><input type="number" name="installment" id="el_installment" class="form-control"></div>
    <div><label>Total Installments</label><input type="number" name="total_installments" id="el_total" class="form-control"></div>
    <div><label>Remaining</label><input type="number" name="remaining" id="el_remaining" class="form-control"></div>
    <div><label>Status</label><select name="status" id="el_status" class="form-select"><option value="active">Active</option><option value="closed">Closed</option></select></div>
    <div><label>Reason</label><input type="text" name="reason" id="el_reason" class="form-control"></div>
  </div>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('editLoanModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Update</button></div></form>
</div></div>
<script>
function editLoan(l){document.getElementById('el_id').value=l.id;document.getElementById('el_amount').value=l.amount;document.getElementById('el_installment').value=l.installment;document.getElementById('el_total').value=l.total_installments;document.getElementById('el_remaining').value=l.remaining;document.getElementById('el_status').value=l.status;document.getElementById('el_reason').value=l.reason;openModal('editLoanModal');}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
