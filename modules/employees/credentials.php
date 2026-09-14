<?php
/**
 * ============================================================================
 * EMPLOYEE CREDENTIALS — HR sets/resets username & password
 * Accessible from the employee profile "Login Access" card.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php';
require_login('hr');
verify_csrf();

$empId = (int)($_POST['employee_id'] ?? 0);
$emp = fetch_one("SELECT * FROM employees WHERE id=?", [$empId]);
if (!$emp) { set_flash('danger', 'Employee not found.'); redirect(APP_URL . 'modules/employees/index.php'); }

$action = $_POST['cred_action'] ?? '';

// Find existing user record
$user = fetch_one("SELECT * FROM users WHERE employee_id=?", [$empId]);

if ($action === 'create_account') {
    // Create login account if none exists
    $username = strtolower(clean($_POST['username']));
    $password = $_POST['password'] ?? '';
    if (strlen($password) < 6) { set_flash('danger', 'Password must be at least 6 characters.'); redirect(APP_URL . 'modules/employees/view.php?id=' . $empId); }
    // check username uniqueness
    $exists = fetch_one("SELECT id FROM users WHERE username=? AND employee_id!=?", [$username, $empId]);
    if ($exists) { set_flash('danger', "Username '$username' is already taken. Please choose another."); redirect(APP_URL . 'modules/employees/view.php?id=' . $empId); }
    insert('users', [
        'employee_id' => $empId, 'username' => $username, 'email' => $emp['email'],
        'password_hash' => hash_password($password), 'role' => clean($_POST['role'] ?? 'employee'),
        'must_change_password' => 0,
    ]);
    // seed leave balances if not already present
    foreach (fetch_all("SELECT * FROM leave_types WHERE default_balance>0") as $lt) {
        $bal = fetch_one("SELECT id FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?", [$empId, $lt['id'], date('Y')]);
        if (!$bal) insert('leave_balances', ['employee_id' => $empId, 'leave_type_id' => $lt['id'], 'year' => date('Y'), 'allocated' => $lt['default_balance'], 'used' => 0]);
    }
    log_activity('Login Account Created', "{$emp['first_name']} {$emp['last_name']} — username: $username");
    set_flash('success', "✅ Login account created! Username: <strong>$username</strong> · Password set. Give these to the employee.");
    redirect(APP_URL . 'modules/employees/view.php?id=' . $empId);

} elseif ($action === 'update_username') {
    // Change username
    $username = strtolower(clean($_POST['username']));
    $exists = fetch_one("SELECT id FROM users WHERE username=? AND employee_id!=?", [$username, $empId]);
    if ($exists) { set_flash('danger', "Username '$username' is already taken."); redirect(APP_URL . 'modules/employees/view.php?id=' . $empId); }
    update('users', ['username' => $username], 'employee_id=?', [$empId]);
    log_activity('Username Changed', "{$emp['first_name']} {$emp['last_name']} → $username");
    set_flash('success', "Username updated to: <strong>$username</strong>");
    redirect(APP_URL . 'modules/employees/view.php?id=' . $empId);

} elseif ($action === 'reset_password') {
    // Reset/Set password (custom or random)
    $password = $_POST['password'] ?? '';
    if (strlen($password) < 6) { set_flash('danger', 'Password must be at least 6 characters.'); redirect(APP_URL . 'modules/employees/view.php?id=' . $empId); }
    update('users', ['password_hash' => hash_password($password), 'must_change_password' => 0], 'employee_id=?', [$empId]);
    log_activity('Password Reset', "{$emp['first_name']} {$emp['last_name']} (by HR)");
    set_flash('success', "✅ Password reset! Give the employee: Username: <strong>" . ($user['username'] ?? '') . "</strong> · Password: <strong>$password</strong>");
    redirect(APP_URL . 'modules/employees/view.php?id=' . $empId);

} elseif ($action === 'update_role') {
    $role = clean($_POST['role'] ?? 'employee');
    if ($user) {
        update('users', ['role' => $role], 'employee_id=?', [$empId]);
        log_activity('Role Changed', "{$emp['first_name']} {$emp['last_name']} → $role");
        set_flash('success', 'User role updated to: <strong>' . ucfirst($role) . '</strong>');
    }
    redirect(APP_URL . 'modules/employees/view.php?id=' . $empId);
}

redirect(APP_URL . 'modules/employees/view.php?id=' . $empId);
