<?php
/**
 * ============================================================================
 * EMPLOYEE BENEFITS — HR manages benefits (Petrol, Medical, Car, etc.)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['benefit_action'] ?? '';

    if ($action === 'add') {
        insert('employee_benefits', [
            'employee_id' => (int)$_POST['employee_id'],
            'benefit_type' => clean($_POST['benefit_type']),
            'amount' => (float)($_POST['amount'] ?? 0),
            'description' => clean($_POST['description'] ?? ''),
            'is_active' => 1,
        ]);
        log_activity('Benefit Added', clean($_POST['benefit_type']) . ' → Employee #' . $_POST['employee_id']);
        set_flash('success', 'Benefit added!');
    } elseif ($action === 'edit') {
        update('employee_benefits', [
            'benefit_type' => clean($_POST['benefit_type']),
            'amount' => (float)($_POST['amount'] ?? 0),
            'description' => clean($_POST['description'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ], 'id = ?', [(int)$_POST['benefit_id']]);
        set_flash('success', 'Benefit updated!');
    } elseif ($action === 'delete') {
        db()->prepare("DELETE FROM employee_benefits WHERE id=?")->execute([(int)$_POST['benefit_id']]);
        set_flash('success', 'Benefit deleted.');
    }
    redirect(APP_URL . 'modules/payroll/benefits.php');
}

$benefits = fetch_all("SELECT b.*, e.full_name, e.employee_code FROM employee_benefits b JOIN employees e ON e.id=b.employee_id ORDER BY b.is_active DESC, e.full_name");
$employees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");
$benefitTypes = ['Petrol Allowance', 'Medical Allowance', 'Car Allowance', 'Mobile Allowance', 'Internet Allowance', 'Food Allowance', 'House Rent', 'Utilities', 'Insurance', 'Other'];
auth_header('Employee Benefits');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-gift"></i> Employee Benefits</h1>
    <div class="sub">Manage allowances &amp; benefits for employees</div></div>
  <button class="btn btn-primary" onclick="openModal('benModal')"><i class="fa-solid fa-plus"></i> Add Benefit</button>
</div>

<div class="grid cols-3" style="margin-bottom:18px">
  <?php
  $totalBen = fetch_one("SELECT SUM(amount) a, COUNT(*) c FROM employee_benefits WHERE is_active=1");
  $empWithBen = fetch_one("SELECT COUNT(DISTINCT employee_id) c FROM employee_benefits WHERE is_active=1")['c'];
  ?>
  <div class="stat"><div class="ico bg-green"><i class="fa-solid fa-coins"></i></div><div><div class="num"><?= money($totalBen['a'] ?? 0) ?></div><div class="lbl">Total Monthly Benefits</div></div></div>
  <div class="stat"><div class="ico bg-blue"><i class="fa-solid fa-users"></i></div><div><div class="num"><?= $empWithBen ?></div><div class="lbl">Employees with Benefits</div></div></div>
  <div class="stat"><div class="ico bg-purple"><i class="fa-solid fa-list-check"></i></div><div><div class="num"><?= $totalBen['c'] ?? 0 ?></div><div class="lbl">Active Benefits</div></div></div>
</div>

<div class="card card-pad">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Employee</th><th>Benefit Type</th><th>Amount</th><th>Description</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$benefits): echo '<tr><td colspan="6" class="empty"><i class="fa-solid fa-gift"></i>No benefits added yet</td></tr>'; endif;
    foreach($benefits as $b): ?>
      <tr>
        <td class="bold"><?= e($b['full_name']) ?><div class="muted small"><?= e($b['employee_code']) ?></div></td>
        <td><span class="badge badge-purple"><?= e($b['benefit_type']) ?></span></td>
        <td class="bold"><?= money($b['amount']) ?></td>
        <td class="small muted"><?= e($b['description'] ?: '—') ?></td>
        <td><span class="badge badge-<?= $b['is_active']?'green':'gray' ?>"><?= $b['is_active']?'Active':'Inactive' ?></span></td>
        <td>
          <button class="btn btn-sm btn-light" onclick='editBenefit(<?= json_encode($b) ?>)'><i class="fa-solid fa-pen"></i></button>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="benefit_action" value="delete"><input type="hidden" name="benefit_id" value="<?= $b['id'] ?>">
            <button class="btn btn-sm btn-light" data-confirm="Delete benefit?"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<!-- Add Benefit Modal -->
<div class="modal-bg" id="benModal"><div class="modal"><div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Add Benefit</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('benModal')"></i></div>
<form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="benefit_action" value="add">
  <div style="margin-bottom:12px"><label>Employee *</label><select name="employee_id" class="form-select" required>
    <option value="">— Choose Employee —</option>
    <?php foreach($employees as $emp): ?><option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'].' — '.$emp['full_name']) ?></option><?php endforeach; ?>
  </select></div>
  <div class="grid cols-2">
    <div><label>Benefit Type *</label>
      <select name="benefit_type" class="form-select" required id="benTypeSelect">
        <?php foreach($benefitTypes as $bt): ?><option value="<?= e($bt) ?>"><?= e($bt) ?></option><?php endforeach; ?>
        <option value="custom">+ Custom (type below)</option>
      </select>
      <input type="text" id="customBenType" class="form-control" style="display:none;margin-top:8px" placeholder="Enter custom benefit name">
    </div>
    <div><label>Amount (PKR)</label><input type="number" name="amount" class="form-control" value="0"></div>
  </div>
  <div style="margin-top:12px"><label>Description (optional)</label><input type="text" name="description" class="form-control" placeholder="e.g. Monthly petrol for field duty"></div>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('benModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add Benefit</button></div></form>
</div></div>

<!-- Edit Benefit Modal -->
<div class="modal-bg" id="editBenModal"><div class="modal"><div class="modal-head"><h3><i class="fa-solid fa-pen"></i> Edit Benefit</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('editBenModal')"></i></div>
<form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="benefit_action" value="edit"><input type="hidden" name="benefit_id" id="eb_id">
  <div class="grid cols-2">
    <div><label>Benefit Type</label><input type="text" name="benefit_type" id="eb_type" class="form-control"></div>
    <div><label>Amount (PKR)</label><input type="number" name="amount" id="eb_amount" class="form-control"></div>
  </div>
  <div style="margin-top:12px"><label>Description</label><input type="text" name="description" id="eb_desc" class="form-control"></div>
  <div style="margin-top:12px"><label class="flex center gap" style="gap:8px;cursor:pointer"><input type="checkbox" name="is_active" id="eb_active" style="width:20px;height:20px"> <span class="bold">Active</span></label></div>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('editBenModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Update</button></div></form>
</div></div>

<script>
document.getElementById('benTypeSelect').addEventListener('change', function() {
  var custom = document.getElementById('customBenType');
  if (this.value === 'custom') {
    custom.style.display = '';
    custom.name = 'benefit_type';
    this.name = '_benefit_type_disabled';
    custom.required = true;
  } else {
    custom.style.display = 'none';
    this.name = 'benefit_type';
    custom.name = '_custom_disabled';
    custom.required = false;
  }
});

function editBenefit(b) {
  document.getElementById('eb_id').value = b.id;
  document.getElementById('eb_type').value = b.benefit_type;
  document.getElementById('eb_amount').value = b.amount;
  document.getElementById('eb_desc').value = b.description || '';
  document.getElementById('eb_active').checked = b.is_active == 1;
  openModal('editBenModal');
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
