<?php
/**
 * ============================================================================
 * LEAVE TYPES MANAGEMENT — HR can add/edit/delete leave types
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['lt_action'] ?? '';

    if ($action === 'add') {
        insert('leave_types', [
            'name' => clean($_POST['name']),
            'code' => strtoupper(clean($_POST['code'])),
            'default_balance' => (float)($_POST['default_balance'] ?? 0),
            'is_paid' => isset($_POST['is_paid']) ? 1 : 0,
            'min_lead_days' => (int)($_POST['min_lead_days'] ?? 0),
            'color' => clean($_POST['color']),
        ]);
        log_activity('Leave Type Added', clean($_POST['name']));
        set_flash('success', 'Leave type added!');
    } elseif ($action === 'edit') {
        update('leave_types', [
            'name' => clean($_POST['name']),
            'code' => strtoupper(clean($_POST['code'])),
            'default_balance' => (float)($_POST['default_balance'] ?? 0),
            'is_paid' => isset($_POST['is_paid']) ? 1 : 0,
            'min_lead_days' => (int)($_POST['min_lead_days'] ?? 0),
            'color' => clean($_POST['color']),
        ], 'id = ?', [(int)$_POST['id']]);
        set_flash('success', 'Leave type updated!');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $count = (int)fetch_one("SELECT COUNT(*) c FROM leave_requests WHERE leave_type_id=?", [$id])['c'];
        if ($count > 0) {
            set_flash('danger', "Cannot delete — $count leave request(s) use this type.");
        } else {
            db()->prepare("DELETE FROM leave_balances WHERE leave_type_id=?")->execute([$id]);
            db()->prepare("DELETE FROM leave_types WHERE id=?")->execute([$id]);
            set_flash('success', 'Leave type deleted.');
        }
    }
    redirect(APP_URL . 'modules/leave/types.php');
}

$types = fetch_all("SELECT lt.*, (SELECT COUNT(*) FROM leave_balances lb WHERE lb.leave_type_id=lt.id) balance_count FROM leave_types lt ORDER BY lt.id");
$editId = (int)($_GET['edit'] ?? 0);
$editType = $editId ? fetch_one("SELECT * FROM leave_types WHERE id=?", [$editId]) : null;
auth_header('Leave Types');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-calendar-plus"></i> Leave Types</h1>
    <div class="sub">Manage leave categories, balances &amp; rules</div></div>
  <?php if (!$editType): ?><button class="btn btn-primary" onclick="openModal('ltModal')"><i class="fa-solid fa-plus"></i> Add Leave Type</button><?php endif; ?>
</div>

<?php if ($editType): ?>
<div class="card card-pad" style="margin-bottom:18px">
  <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-pen"></i> Edit Leave Type</h3>
  <form method="post" class="grid cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="lt_action" value="edit"><input type="hidden" name="id" value="<?= $editType['id'] ?>">
    <div><label>Name *</label><input type="text" name="name" class="form-control" value="<?= e($editType['name']) ?>" required></div>
    <div><label>Code</label><input type="text" name="code" class="form-control" value="<?= e($editType['code']) ?>" style="text-transform:uppercase"></div>
    <div><label>Default Balance (days)</label><input type="number" step="0.5" name="default_balance" class="form-control" value="<?= e($editType['default_balance']) ?>"></div>
    <div><label>Min Advance Notice (days)</label><input type="number" name="min_lead_days" class="form-control" value="<?= e($editType['min_lead_days']) ?>"></div>
    <div><label>Color</label><input type="color" name="color" class="form-control" value="<?= e($editType['color']) ?>"></div>
    <div><label>Paid?</label><select name="is_paid" class="form-select"><option value="1" <?= $editType['is_paid']?'selected':'' ?>>Paid</option><option value="0" <?= !$editType['is_paid']?'selected':'' ?>>Unpaid</option></select></div>
    <div style="grid-column:span 3"><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Update</button> <a href="<?= url('modules/leave/types.php') ?>" class="btn btn-light">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<div class="card card-pad">
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Leave Type</th><th>Code</th><th>Default Balance</th><th>Min Notice</th><th>Paid</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($types as $t): ?>
      <tr>
        <td><span class="badge" style="background:<?= e($t['color']) ?>20;color:<?= e($t['color']) ?>"><?= e($t['name']) ?></span></td>
        <td class="bold"><?= e($t['code']) ?></td>
        <td><?= $t['default_balance'] ?> days</td>
        <td><?= $t['min_lead_days'] ?> days</td>
        <td><?= $t['is_paid'] ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-gray">Unpaid</span>' ?></td>
        <td>
          <a href="<?= url('modules/leave/types.php?edit='.$t['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-pen"></i></a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="lt_action" value="delete"><input type="hidden" name="id" value="<?= $t['id'] ?>">
            <button class="btn btn-sm btn-light" data-confirm="Delete this leave type?"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="modal-bg" id="ltModal"><div class="modal"><div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Add Leave Type</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('ltModal')"></i></div>
<form method="post"><div class="modal-body"><?= csrf_field() ?><input type="hidden" name="lt_action" value="add">
  <div class="grid cols-2">
    <div><label>Name *</label><input type="text" name="name" class="form-control" placeholder="e.g. Casual Leave" required></div>
    <div><label>Code</label><input type="text" name="code" class="form-control" placeholder="CL" style="text-transform:uppercase"></div>
    <div><label>Default Balance (days)</label><input type="number" step="0.5" name="default_balance" class="form-control" value="0"></div>
    <div><label>Min Advance Notice (days)</label><input type="number" name="min_lead_days" class="form-control" value="0"></div>
    <div><label>Color</label><input type="color" name="color" class="form-control" value="#7F3E98"></div>
    <div><label>Paid?</label><select name="is_paid" class="form-select"><option value="1">Paid</option><option value="0">Unpaid</option></select></div>
  </div>
</div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('ltModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add</button></div></form>
</div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
