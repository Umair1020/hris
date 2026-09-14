<?php
/**
 * ============================================================================
 * USER MANAGEMENT — Admin can create/edit/delete system users & roles
 * Roles: admin, hr, manager, employee
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('admin');

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['uaction'] ?? '';

    if ($action === 'create') {
        $username = strtolower(clean($_POST['username']));
        $email = clean($_POST['email']);
        $password = $_POST['password'] ?? '';
        $role = clean($_POST['role']);
        $empId = (int)($_POST['employee_id'] ?? 0) ?: null;

        if (!$username || strlen($password) < 6) {
            set_flash('danger', 'Username required and password must be at least 6 characters.');
            redirect(APP_URL . 'modules/settings/users.php');
        }
        // Check username uniqueness
        $exists = fetch_one("SELECT id FROM users WHERE username = ?", [$username]);
        if ($exists) {
            set_flash('danger', "Username '$username' is already taken.");
            redirect(APP_URL . 'modules/settings/users.php');
        }

        $newId = insert('users', [
            'employee_id' => $empId, 'username' => $username, 'email' => $email,
            'password_hash' => hash_password($password), 'role' => $role,
            'must_change_password' => 0, 'status' => 'active',
        ]);

        log_activity('User Created', "$username ($role)");
        set_flash('success', "✅ User created! Username: <strong>$username</strong> · Password: <strong>$password</strong>");

        // Notify via WhatsApp + Email if linked employee has details
        if ($empId) {
            $emp = fetch_one("SELECT full_name, whatsapp, email FROM employees WHERE id=?", [$empId]);
            if ($emp) {
                if (!empty($emp['whatsapp'])) {
                    send_whatsapp($emp['whatsapp'], "🔐 *HRIS Account Created*\n\nHi {$emp['full_name']}, your account has been created.\n\nUsername: $username\nPassword: $password\nURL: " . APP_URL . "index.php\n\n_Spotcomm Global HR_");
                }
                if (!empty($emp['email'])) {
                    send_email($emp['email'], 'HRIS Account Created - Spotcomm Global', "Dear {$emp['full_name']},\n\nYour HRIS account has been created.\n\nUsername: $username\nPassword: $password\nURL: " . APP_URL . "index.php\n\nPlease login and change your password if needed.\n\nHR Department\nSpotcomm Global");
                }
            }
        }
        redirect(APP_URL . 'modules/settings/users.php');

    } elseif ($action === 'update_role') {
        $uid = (int)$_POST['user_id'];
        $role = clean($_POST['role']);
        // Don't allow admin to remove their own admin role
        if ($uid == current_user_id() && $role !== 'admin') {
            set_flash('danger', 'You cannot change your own admin role.');
        } else {
            update('users', ['role' => $role], 'id = ?', [$uid]);
            log_activity('User Role Changed', "User #$uid → $role");
            set_flash('success', 'User role updated.');
        }
        redirect(APP_URL . 'modules/settings/users.php');

    } elseif ($action === 'toggle_status') {
        $uid = (int)$_POST['user_id'];
        if ($uid == current_user_id()) {
            set_flash('danger', 'You cannot disable your own account.');
        } else {
            $u = fetch_one("SELECT status, username FROM users WHERE id=?", [$uid]);
            $newStatus = ($u['status'] === 'active') ? 'inactive' : 'active';
            update('users', ['status' => $newStatus], 'id = ?', [$uid]);
            log_activity('User Status', "{$u['username']} → $newStatus");
            set_flash('success', "User {$u['username']} is now " . ucfirst($newStatus) . ".");
        }
        redirect(APP_URL . 'modules/settings/users.php');

    } elseif ($action === 'reset_password') {
        $uid = (int)$_POST['user_id'];
        $newPass = $_POST['new_password'] ?? 'Spotcomm@' . rand(1000, 9999);
        if (strlen($newPass) < 6) {
            set_flash('danger', 'Password must be at least 6 characters.');
        } else {
            update('users', ['password_hash' => hash_password($newPass), 'must_change_password' => 0], 'id = ?', [$uid]);
            $u = fetch_one("SELECT username FROM users WHERE id=?", [$uid]);
            log_activity('Password Reset', "User {$u['username']} (by admin)");
            set_flash('success', "✅ Password reset for <strong>{$u['username']}</strong>. New password: <strong>$newPass</strong>");
        }
        redirect(APP_URL . 'modules/settings/users.php');

    } elseif ($action === 'delete') {
        $uid = (int)$_POST['user_id'];
        if ($uid == current_user_id()) {
            set_flash('danger', 'You cannot delete your own account.');
        } else {
            $u = fetch_one("SELECT username FROM users WHERE id=?", [$uid]);
            db()->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
            log_activity('User Deleted', $u['username']);
            set_flash('success', "User {$u['username']} deleted.");
        }
        redirect(APP_URL . 'modules/settings/users.php');
    }
}

$users = fetch_all(
    "SELECT u.*, e.full_name emp_name FROM users u LEFT JOIN employees e ON e.id=u.employee_id ORDER BY CASE WHEN u.role='admin' THEN 1 WHEN u.role='hr' THEN 2 WHEN u.role='manager' THEN 3 ELSE 4 END, u.username"
);
$employees = fetch_all("SELECT id, full_name, employee_code FROM employees WHERE status='Active' ORDER BY full_name");
$roleColors = ['admin' => 'purple', 'hr' => 'blue', 'manager' => 'amber', 'employee' => 'gray'];
auth_header('User Management');
?>
<div class="page-head">
  <div><h1><i class="fa-solid fa-users-gear"></i> User Management</h1>
    <div class="sub">Create system users &amp; assign roles</div></div>
  <button class="btn btn-primary" onclick="openModal('userModal')"><i class="fa-solid fa-user-plus"></i> Add User</button>
</div>

<div class="card card-pad">
  <div class="table-wrap">
    <table class="tbl">
      <thead><tr><th>User</th><th>Employee</th><th>Role</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td>
            <div class="bold"><?= e($u['username']) ?></div>
            <div class="muted small"><?= e($u['email'] ?: '—') ?></div>
          </td>
          <td class="small"><?= e($u['emp_name'] ?: '<span class="muted">No profile</span>') ?></td>
          <td>
            <form method="post" style="display:inline"><?= csrf_field() ?>
              <input type="hidden" name="uaction" value="update_role">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="role" class="form-select" style="width:auto;font-size:12px;padding:3px 8px;display:inline-block" onchange="this.form.submit()" <?= $u['id'] == current_user_id() ? 'disabled' : '' ?>>
                <?php foreach (['admin', 'hr', 'manager', 'employee'] as $r): ?>
                  <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td>
            <span class="badge badge-<?= $u['status'] === 'active' ? 'green' : 'red' ?>"><?= ucfirst($u['status']) ?></span>
          </td>
          <td class="small muted"><?= $u['last_login'] ? format_datetime($u['last_login']) : 'Never' ?></td>
          <td>
            <div class="flex gap" style="gap:4px">
              <button class="btn btn-sm btn-light" onclick="resetPwd(<?= $u['id'] ?>, '<?= e($u['username']) ?>')" title="Reset Password"><i class="fa-solid fa-key"></i></button>
              <?php if ($u['id'] != current_user_id()): ?>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="uaction" value="toggle_status">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-sm btn-light" title="<?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?>">
                    <i class="fa-solid fa-<?= $u['status'] === 'active' ? 'toggle-off' : 'toggle-on' ?>" style="color:<?= $u['status'] === 'active' ? '#ef4444' : '#10b981' ?>"></i>
                  </button>
                </form>
                <form method="post" style="display:inline"><?= csrf_field() ?>
                  <input type="hidden" name="uaction" value="delete">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button class="btn btn-sm btn-light" data-confirm="Delete user <?= e($u['username']) ?>?"><i class="fa-solid fa-trash" style="color:#ef4444"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Create User Modal -->
<div class="modal-bg" id="userModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-head"><h3><i class="fa-solid fa-user-plus"></i> Add System User</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('userModal')"></i></div>
    <form method="post"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="uaction" value="create">
      <div style="margin-bottom:12px">
        <label>Link to Employee (optional)</label>
        <select name="employee_id" class="form-select">
          <option value="0">— Standalone account (no employee profile) —</option>
          <?php foreach ($employees as $emp): ?>
            <option value="<?= $emp['id'] ?>"><?= e($emp['employee_code'] . ' — ' . $emp['full_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="grid cols-2">
        <div><label>Username *</label><input type="text" name="username" class="form-control" placeholder="e.g. hrdirector" required></div>
        <div><label>Email</label><input type="email" name="email" class="form-control" placeholder="user@spotcomm.pk"></div>
        <div><label>Password *</label>
          <div class="flex gap" style="gap:8px">
            <input type="text" name="password" id="newUserPass" class="form-control" value="Spotcomm@<?= rand(1000,9999) ?>" required>
            <button type="button" class="btn btn-light" onclick="genUserPass()"><i class="fa-solid fa-dice"></i></button>
          </div>
        </div>
        <div><label>Role *</label>
          <select name="role" class="form-select">
            <option value="admin">Admin (full access)</option>
            <option value="hr">HR (HR management)</option>
            <option value="manager">Manager (team approvals)</option>
            <option value="employee">Employee (self-service)</option>
          </select>
        </div>
      </div>
      <div class="alert alert-info" style="margin-top:14px"><i class="fa-solid fa-circle-info"></i> Role permissions: <strong>Admin</strong> = everything including settings & users · <strong>HR</strong> = employees, payroll, ATS, letters, broadcast · <strong>Manager</strong> = team leave/review approvals · <strong>Employee</strong> = self-service view only</div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('userModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Create User</button></div></form>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-bg" id="pwdModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-head"><h3><i class="fa-solid fa-key"></i> Reset Password</h3><i class="fa-solid fa-xmark" style="cursor:pointer" onclick="closeModal('pwdModal')"></i></div>
    <form method="post"><div class="modal-body">
      <?= csrf_field() ?>
      <input type="hidden" name="uaction" value="reset_password">
      <input type="hidden" name="user_id" id="pwdUserId">
      <p>Resetting password for: <strong id="pwdUsername"></strong></p>
      <label>New Password</label>
      <div class="flex gap" style="gap:8px">
        <input type="text" name="new_password" id="pwdInput" class="form-control" required>
        <button type="button" class="btn btn-light" onclick="genModalPwd()"><i class="fa-solid fa-dice"></i></button>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-light" onclick="closeModal('pwdModal')">Cancel</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Reset</button></div></form>
  </div>
</div>

<script>
function resetPwd(id, name) {
  document.getElementById('pwdUserId').value = id;
  document.getElementById('pwdUsername').textContent = name;
  document.getElementById('pwdInput').value = 'Spotcomm@' + Math.floor(Math.random()*9000+1000);
  openModal('pwdModal');
}
function genModalPwd() {
  var c='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$';
  var p=''; for(var i=0;i<10;i++) p+=c.charAt(Math.floor(Math.random()*c.length));
  document.getElementById('pwdInput').value=p;
}
function genUserPass() {
  var c='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$';
  var p='Spotcomm@'; for(var i=0;i<4;i++) p+=Math.floor(Math.random()*10);
  document.getElementById('newUserPass').value=p;
}
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
