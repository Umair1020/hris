<?php
/**
 * ============================================================================
 * DEPARTMENTS — Add / Edit / Delete (HR)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = clean($_POST['name']);
        $code = strtoupper(clean($_POST['code']));
        $desc = clean($_POST['description']);
        if (!$name) { set_flash('danger', 'Department name required.'); redirect(APP_URL . 'modules/employees/departments.php'); }
        insert('departments', ['name' => $name, 'code' => $code, 'description' => $desc]);
        log_activity('Department Added', $name);
        set_flash('success', "Department '$name' added.");

    } elseif ($action === 'edit') {
        $id = (int)$_POST['id'];
        update('departments', [
            'name' => clean($_POST['name']),
            'code' => strtoupper(clean($_POST['code'])),
            'description' => clean($_POST['description']),
        ], 'id = ?', [$id]);
        set_flash('success', 'Department updated.');

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $count = (int)fetch_one("SELECT COUNT(*) c FROM employees WHERE department_id = ?", [$id])['c'];
        if ($count > 0) {
            set_flash('danger', "Cannot delete — $count employee(s) are assigned to this department. Reassign them first.");
        } else {
            $stmt = db()->prepare("DELETE FROM departments WHERE id = ?");
            $stmt->execute([$id]);
            set_flash('success', 'Department deleted.');
        }
    }
    redirect(APP_URL . 'modules/employees/departments.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$editDept = $editId ? fetch_one("SELECT * FROM departments WHERE id=?", [$editId]) : null;
$search = clean($_GET['q'] ?? '');
$where = $search ? "WHERE d.name LIKE ? OR d.code LIKE ? OR d.description LIKE ?" : "";
$params = $search ? ["%$search%","%$search%","%$search%"] : [];
$depts = fetch_all("SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id=d.id) emp_count FROM departments d $where ORDER BY d.name", $params);
auth_header('Departments');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-building"></i> Departments</h1>
    <div class="sub">Add, edit and manage company departments</div></div>
  <?php if (!$editDept): ?>
  <div class="flex gap wrap" style="align-items:center">
    <form method="get" class="flex gap" style="gap:8px;flex:1;max-width:320px">
      <input type="text" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search departments...">
      <button class="btn btn-light"><i class="fa-solid fa-search"></i></button>
      <?php if($search): ?><a href="<?= url('modules/employees/departments.php') ?>" class="btn btn-light">Clear</a><?php endif; ?>
    </form>
    <button class="btn btn-primary" onclick="openModal('deptModal')"><i class="fa-solid fa-plus"></i> Add Department</button>
  </div>
  <?php endif; ?>
</div>

<?php if ($editDept): ?>
<div class="card card-pad" style="margin-bottom:18px">
  <h3 class="section-title" style="margin-bottom:12px"><i class="fa-solid fa-pen"></i> Edit Department</h3>
  <form method="post" class="grid cols-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="id" value="<?= $editDept['id'] ?>">
    <div><label>Name</label><input type="text" name="name" class="form-control" value="<?= e($editDept['name']) ?>" required></div>
    <div><label>Code</label><input type="text" name="code" class="form-control" value="<?= e($editDept['code']) ?>" placeholder="HR, IT, FIN"></div>
    <div><label>Description</label><input type="text" name="description" class="form-control" value="<?= e($editDept['description']) ?>"></div>
    <div style="grid-column:span 3"><button class="btn btn-primary"><i class="fa-solid fa-save"></i> Update</button> <a href="<?= url('modules/employees/departments.php') ?>" class="btn btn-light">Cancel</a></div>
  </form>
</div>
<?php endif; ?>

<div class="card card-pad">
  <?php if (!$depts): ?>
    <div class="empty"><i class="fa-solid fa-building"></i><p>No departments yet. Add your first department to get started.</p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Name</th><th>Code</th><th>Description</th><th>Employees</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($depts as $d): ?>
      <tr>
        <td class="bold"><?= e($d['name']) ?></td>
        <td><span class="badge badge-purple"><?= e($d['code'] ?: '—') ?></span></td>
        <td class="small muted"><?= e($d['description'] ?: '—') ?></td>
        <td><span class="badge badge-blue"><?= $d['emp_count'] ?></span></td>
        <td>
          <a href="<?= url('modules/employees/departments.php?edit='.$d['id']) ?>" class="btn btn-sm btn-light"><i class="fa-solid fa-pen"></i></a>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>">
            <button class="btn btn-sm btn-light" data-confirm="Delete this department?"><i class="fa-solid fa-trash"></i></button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="modal-bg" id="deptModal">
  <div class="modal">
    <div class="modal-head"><h3><i class="fa-solid fa-plus"></i> Add Department</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('deptModal')"></i></div>
    <form method="post"><div class="modal-body"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div style="margin-bottom:12px"><label>Department Name *</label><input type="text" name="name" class="form-control" placeholder="e.g. Human Resources" required></div>
      <div style="margin-bottom:12px"><label>Code</label><input type="text" name="code" class="form-control" placeholder="e.g. HR" style="text-transform:uppercase"></div>
      <div><label>Description</label><input type="text" name="description" class="form-control" placeholder="Brief description"></div>
    </div><div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('deptModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add</button></div></form>
  </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
