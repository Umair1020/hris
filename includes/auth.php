<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - AUTHENTICATION & ROLE-BASED ACCESS CONTROL
 * ============================================================================
 * Roles hierarchy:
 *   admin    - full system access (Management)
 *   hr       - HR department, can edit all employee data
 *   manager  - team lead, approves team leave / reviews
 *   employee - self-service view only (can edit own profile)
 */
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ----------------------------------------------------------------------------
 * LOGIN / LOGOUT
 * -------------------------------------------------------------------------- */
function attempt_login($username, $password)
{
    $user = fetch_one(
        "SELECT u.*, e.full_name, e.status AS emp_status
         FROM users u
         LEFT JOIN employees e ON e.id = u.employee_id
         WHERE u.username = ? AND u.status = 'active'",
        [$username]
    );

    if (!$user || !verify_password($password, $user['password_hash'])) {
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }
    if (!empty($user['emp_status']) && $user['emp_status'] !== 'Active') {
        return ['ok' => false, 'error' => 'Your employee account is not active. Contact HR.'];
    }

    $_SESSION['user'] = [
        'id'         => $user['id'],
        'employee_id'=> $user['employee_id'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'role'       => $user['role'],
        'full_name'  => $user['full_name'] ?: $user['username'],
        'must_change'=> (bool)$user['must_change_password'],
    ];

    update('users', ['last_login' => now(), 'failed_attempts' => 0], 'id = ?', [$user['id']]);
    log_activity('Login', 'User logged in');
    regenerate_session();
    return ['ok' => true, 'must_change' => (bool)$user['must_change_password']];
}

function regenerate_session()
{
    session_regenerate_id(true);
}

function logout()
{
    if (!empty($_SESSION['user'])) {
        log_activity('Logout', 'User logged out');
    }
    $_SESSION = [];
    session_destroy();
}

/* ----------------------------------------------------------------------------
 * SESSION ACCESSORS
 * -------------------------------------------------------------------------- */
function is_logged_in()
{
    return !empty($_SESSION['user']);
}

function current_user()
{
    return $_SESSION['user'] ?? null;
}

function current_user_id()
{
    return $_SESSION['user']['id'] ?? null;
}

function current_employee_id()
{
    return $_SESSION['user']['employee_id'] ?? null;
}

function current_role()
{
    return $_SESSION['user']['role'] ?? 'guest';
}

function current_name()
{
    return $_SESSION['user']['full_name'] ?? 'Guest';
}

/* ----------------------------------------------------------------------------
 * ROLE-BASED ACCESS CONTROL
 * -------------------------------------------------------------------------- */
function role_level($role)
{
    $levels = [
        'employee' => 1,
        'manager'  => 2,
        'hr'       => 3,
        'admin'    => 4,
    ];
    return $levels[$role] ?? 0;
}

function role_at_least($role)
{
    return role_level(current_role()) >= role_level($role);
}

function can_edit_all()
{
    // HR + Management can edit all employee data
    return in_array(current_role(), ['hr', 'admin']);
}

function is_manager()
{
    return role_at_least('manager');
}

function is_hr()
{
    return in_array(current_role(), ['hr', 'admin']);
}

/** Guard: require login (optionally a minimum role) */
function require_login($minRole = 'employee')
{
    if (!is_logged_in()) {
        redirect(APP_URL . 'index.php');
    }
    if (!role_at_least($minRole)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;text-align:center;padding:60px">
            <h2>403 — Access Denied</h2>
            <p>You do not have permission to access this section.</p>
            <a href="' . APP_URL . 'dashboard.php">Go to Dashboard</a></div>');
    }
}

/** Can the current user act on this employee's record? */
function can_access_employee($employeeId)
{
    if (can_edit_all()) return true;
    if (current_employee_id() == $employeeId) return true;
    // managers can view their direct reports
    if (current_role() === 'manager') {
        $row = fetch_one(
            "SELECT 1 FROM employees WHERE id = ? AND manager_id = ?",
            [$employeeId, current_employee_id()]
        );
        return (bool)$row;
    }
    return false;
}
