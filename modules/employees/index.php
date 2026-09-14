<?php
/**
 * ============================================================================
 * EMPLOYEES — directory + management (HR can add/edit/terminate/delete)
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
auth_header('Employees');

// Handle terminate / reactivate / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_hr()) {
    verify_csrf();
    $action = $_POST['emp_action'] ?? '';
    $empId = (int)($_POST['employee_id'] ?? 0);
    $emp = fetch_one("SELECT * FROM employees WHERE id=?", [$empId]);

    if ($emp) {
        if ($action === 'terminate') {
            update('employees', ['status' => 'Terminated'], 'id = ?', [$empId]);
            // deactivate login
            db()->prepare("UPDATE users SET status='inactive' WHERE employee_id=?")->execute([$empId]);
            log_activity('Employee Terminated', $emp['full_name']);
            // WhatsApp + Email notification
            if (!empty($emp['whatsapp'])) {
                send_whatsapp($emp['whatsapp'], "Dear {$emp['first_name']},\n\nYour employment with Spotcomm Global has been terminated effective " . date('d M Y') . ".\n\nPlease contact HR for clearance and final settlement.\n\nHR Department\nSpotcomm Global");
            }
            if (!empty($emp['email'])) {
                send_email($emp['email'], 'Employment Termination - Spotcomm Global', "Dear {$emp['first_name']},\n\nYour employment with Spotcomm Global has been terminated effective " . date('d M Y') . ".\n\nPlease contact HR for clearance and final settlement.\n\nHR Department\nSpotcomm Global");
            }
            set_flash('success', "Employee '{$emp['full_name']}' has been terminated. Login disabled.");
        } elseif ($action === 'suspend') {
            update('employees', ['status' => 'Suspended'], 'id = ?', [$empId]);
            db()->prepare("UPDATE users SET status='inactive' WHERE employee_id=?")->execute([$empId]);
            log_activity('Employee Suspended', $emp['full_name']);
            set_flash('success', "Employee '{$emp['full_name']}' has been suspended.");
        } elseif ($action === 'reactivate') {
            update('employees', ['status' => 'Active'], 'id = ?', [$empId]);
            db()->prepare("UPDATE users SET status='active' WHERE employee_id=?")->execute([$empId]);
            log_activity('Employee Reactivated', $emp['full_name']);
            set_flash('success', "Employee '{$emp['full_name']}' has been reactivated.");
        } elseif ($action === 'delete') {
            // Hard delete — remove employee + login + related data
            db()->prepare("DELETE FROM users WHERE employee_id=?")->execute([$empId]);
            db()->prepare("DELETE FROM employees WHERE id=?")->execute([$empId]);
            log_activity('Employee Deleted', $emp['full_name']);
            set_flash('success', "Employee '{$emp['full_name']}' has been permanently deleted.");
        }
    }
    redirect(APP_URL . 'modules/employees/index.php' . (isset($_POST['show']) ? '?show=' . $_POST['show'] : ''));
}

$q = clean($_GET['q'] ?? '');
$dept = (int)($_GET['dept'] ?? 0);
$show = clean($_GET['show'] ?? 'active');

// Status filter
$statusWhere = "e.status='Active'";
if ($show === 'all') $statusWhere = "1=1";
elseif ($show === 'terminated') $statusWhere = "e.status='Terminated'";
elseif ($show === 'suspended') $statusWhere = "e.status='Suspended'";

$where = "WHERE $statusWhere";
$params = [];
if ($q) { $where .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ? OR e.email LIKE ? OR e.designation LIKE ?)"; $p = "%$q%"; array_push($params, $p, $p, $p, $p); }
if ($dept) { $where .= " AND e.department_id=?"; $params[] = $dept; }

$emps = fetch_all("SELECT e.*, d.name dept FROM employees e LEFT JOIN departments d ON d.id=e.department_id $where ORDER BY e.status, e.full_name", $params);
?>
<div class="page-head">
  <div><h1>Employee Directory</h1><div class="sub"><?= count($emps) ?> employees</div></div>
  <?php if (is_hr()): ?><a href="<?= url('modules/employees/form.php') ?>" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> Add Employee</a><?php endif; ?>
</div>

<div class="flex center gap" style="margin-bottom:12px;flex-wrap:wrap">
  <form method="get" class="flex center gap">
    <div class="input-icon" style="flex:1;min-width:220px"><i class="fa-solid fa-magnifying-glass"></i><input type="text" name="q" class="form-control" placeholder="Search name, code, email..." value="<?= e($q) ?>"></div>
    <select name="dept" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="0">All Departments</option>
      <?php foreach (departments_list() as $d): ?><option value="<?= $d['id'] ?>" <?= $dept == $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="show" class="form-select" style="width:auto" onchange="this.form.submit()">
      <option value="active" <?= $show === 'active' ? 'selected' : '' ?>>Active Only</option>
      <option value="all" <?= $show === 'all' ? 'selected' : '' ?>>All Employees</option>
      <option value="terminated" <?= $show === 'terminated' ? 'selected' : '' ?>>Terminated</option>
      <option value="suspended" <?= $show === 'suspended' ? 'selected' : '' ?>>Suspended</option>
    </select>
    <button class="btn btn-primary"><i class="fa-solid fa-search"></i></button>
  </form>
</div>

<div class="table-wrap">
  <table class="tbl">
    <thead><tr><th>Employee</th><th>Code</th><th>Designation</th><th>Department</th><th>Status</th><?php if (is_hr()) echo '<th>Actions</th>' ?></tr></thead>
    <tbody>
    <?php if (!$emps): echo '<tr><td colspan="' . (is_hr() ? 6 : 5) . '" class="empty"><i class="fa-solid fa-users-slash"></i>No employees found</td></tr>'; endif; ?>
    <?php foreach ($emps as $e): ?>
      <tr>
        <td>
          <div class="flex center gap">
            <div class="avatar sm"><?= e(initials($e['full_name'])) ?></div>
            <a href="<?= url('modules/employees/view.php?id=' . $e['id']) ?>" style="color:inherit"><strong><?= e($e['full_name']) ?></strong></a>
          </div>
        </td>
        <td class="small muted"><?= e($e['employee_code']) ?></td>
        <td class="small"><?= e($e['designation'] ?: '—') ?></td>
        <td class="small"><?= e($e['dept'] ?: '—') ?></td>
        <td><span class="badge badge-<?= $e['status'] === 'Active' ? 'green' : ($e['status'] === 'Suspended' ? 'amber' : 'red') ?>"><?= $e['status'] ?></span></td>
        <?php if (is_hr()): ?>
        <td>
          <div class="flex gap" style="gap:4px">
            <a href="<?= url('modules/employees/view.php?id=' . $e['id']) ?>" class="btn btn-sm btn-light" title="View"><i class="fa-solid fa-eye"></i></a>
            <a href="<?= url('modules/employees/form.php?id=' . $e['id']) ?>" class="btn btn-sm btn-light" title="Edit"><i class="fa-solid fa-pen"></i></a>
            <?php if ($e['status'] === 'Active' && $e['id'] != current_employee_id()): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="emp_action" value="suspend">
                <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
                <input type="hidden" name="show" value="<?= e($show) ?>">
                <button class="btn btn-sm btn-light" data-confirm="Suspend this employee? Their login will be disabled."><i class="fa-solid fa-pause" style="color:#f59e0b"></i></button>
              </form>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="emp_action" value="terminate">
                <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
                <input type="hidden" name="show" value="<?= e($show) ?>">
                <button class="btn btn-sm btn-light" data-confirm="TERMINATE this employee? Their login will be disabled and notification sent."><i class="fa-solid fa-user-xmark" style="color:#ef4444"></i></button>
              </form>
            <?php elseif ($e['status'] !== 'Active'): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?>
                <input type="hidden" name="emp_action" value="reactivate">
                <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
                <input type="hidden" name="show" value="<?= e($show) ?>">
                <button class="btn btn-sm btn-light" data-confirm="Reactivate this employee?"><i class="fa-solid fa-user-check" style="color:#10b981"></i></button>
            <?php endif; ?>
              <?php if ($e['status'] !== 'Active'): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="emp_action" value="delete">
                  <input type="hidden" name="employee_id" value="<?= $e['id'] ?>">
                  <input type="hidden" name="show" value="<?= e($show) ?>">
                  <button class="btn btn-sm btn-light" data-confirm="PERMANENTLY DELETE this employee? All their data will be removed. This cannot be undone!"><i class="fa-solid fa-trash" style="color:#ef4444"></i></button>
                </form>
              <?php endif; ?>
            </form>
          </div>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
