<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - HELPER FUNCTIONS
 * ============================================================================
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/* ----------------------------------------------------------------------------
 * SECURITY HELPERS
 * -------------------------------------------------------------------------- */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean($value)
{
    return trim(strip_tags((string)$value));
}

function redirect($path)
{
    // CRITICAL: discard any buffered output (from auth_header HTML etc)
    // before sending the redirect header. This prevents blank screens.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (strpos($path, 'http') === 0) {
        header("Location: $path");
    } else {
        header('Location: ' . url($path));
    }
    exit;
}

/** Build an absolute URL from a path relative to APP_URL */
function url($path = '')
{
    return APP_URL . ltrim($path, '/');
}

function asset($path)
{
    return APP_URL . 'assets/' . ltrim($path, '/');
}

function current_url()
{
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
}

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

function verify_csrf()
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

/* ----------------------------------------------------------------------------
 * PASSWORD HASHING
 * -------------------------------------------------------------------------- */
function hash_password($plain)
{
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => HASH_COST]);
}

function verify_password($plain, $hash)
{
    return password_verify($plain, $hash);
}

/* ----------------------------------------------------------------------------
 * SQL COMPATIBILITY (SQLite / MySQL agnostic fragments)
 * -------------------------------------------------------------------------- */
/** Year of a date column as integer */
function sql_year($col){ return "CAST(strftime('%Y',$col) AS INTEGER)"; }
/** Month of a date column as integer (1-12) */
function sql_month($col){ return "CAST(strftime('%m',$col) AS INTEGER)"; }
/** Today's date SQL literal */
function sql_today(){ return "date('now')"; }
/** Date N days ago SQL literal */
function sql_days_ago($n){ return "date('now','-$n days')"; }

/* ----------------------------------------------------------------------------
 * DATE / TIME HELPERS — BULLETPROOF Karachi time (UTC+5, no DST)
 * Does NOT depend on date_default_timezone_set or server PHP config.
 * Works on ANY cPanel server regardless of php.ini settings.
 * -------------------------------------------------------------------------- */
function now()
{
    return gmdate('Y-m-d H:i:s', time() + 5 * 3600); // Karachi time (UTC+5, no DST)
}

function today()
{
    return gmdate('Y-m-d', time() + 5 * 3600);
}

/**
 * Calculate work hours between two stored datetimes (both in Karachi time).
 * Uses UTC parsing to avoid PHP timezone double-offset bug.
 */
function calc_hours($clockIn, $clockOut)
{
    if (!$clockIn || !$clockOut) return 0;
    $in = strtotime($clockIn . ' UTC');
    $out = strtotime($clockOut . ' UTC');
    if ($in === false || $out === false || $out <= $in) return 0;
    return round(($out - $in) / 3600, 2);
}

/**
 * Get required work hours for an employee based on THEIR shift times.
 * Handles night shifts (e.g., 22:00 to 10:00 = 12 hours).
 */
function get_required_hours($employee)
{
    $global = (float)get_setting('required_work_hours', '9');

    // If no employee data, use global setting
    if (!$employee) return $global > 0 ? $global : 9;

    $shiftStart = $employee['shift_start'] ?? '';
    $shiftEnd = $employee['shift_end'] ?? '';

    // If shift times exist and are valid, calculate duration
    if ($shiftStart && $shiftEnd && $shiftStart !== '00:00:00' && $shiftEnd !== '00:00:00') {
        $today = date('Y-m-d');
        $startTs = strtotime($today . ' ' . $shiftStart);
        $endTs = strtotime($today . ' ' . $shiftEnd);

        // Handle night shift: if end < start, it means shift crosses midnight
        if ($endTs <= $startTs) {
            $endTs = strtotime('+1 day', $endTs);
        }

        $shiftHours = round(($endTs - $startTs) / 3600, 2);
        if ($shiftHours > 0 && $shiftHours <= 24) {
            return $shiftHours;
        }
    }

    // Fallback to global setting
    return $global > 0 ? $global : 9;
}

function format_date($date, $format = 'd M Y')
{
    if (!$date || $date == '0000-00-00') return '—';
    return gmdate($format, strtotime($date . ' UTC'));
}

function format_datetime($dt, $format = 'd M Y, h:i A')
{
    if (!$dt || $dt == '0000-00-00 00:00:00') return '—';
    // Stored as Karachi time; parse and display as-is
    return gmdate($format, strtotime($dt . ' UTC'));
}

function time_ago($datetime)
{
    if (!$datetime) return '—';
    return gmdate('d M, h:i A', strtotime($datetime . ' UTC'));
}

function money($amount, $currency = 'PKR')
{
    return $currency . ' ' . number_format((float)$amount, 0, '.', ',');
}

function days_between($start, $end)
{
    return (strtotime($end) - strtotime($start)) / 86400 + 1;
}

function month_name($m)
{
    return date('F', mktime(0, 0, 0, $m, 1));
}

/* ----------------------------------------------------------------------------
 * FLASH MESSAGES
 * -------------------------------------------------------------------------- */
function set_flash($type, $message)
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flash()
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

/* ----------------------------------------------------------------------------
 * DATA HELPERS
 * -------------------------------------------------------------------------- */
function fetch_one($sql, $params = [])
{
    $pdo  = db();
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        $info = $pdo->errorInfo();
        throw new Exception("DB prepare failed: " . ($info[2] ?? 'unknown error') . " — SQL: " . $sql);
    }
    if (!$stmt->execute($params)) {
        $info = $stmt->errorInfo();
        throw new Exception("DB execute failed: " . ($info[2] ?? 'unknown error') . " — SQL: " . $sql);
    }
    return $stmt->fetch();
}

function fetch_all($sql, $params = [])
{
    $pdo  = db();
    $stmt = $pdo->prepare($sql);
    if ($stmt === false) {
        $info = $pdo->errorInfo();
        throw new Exception("DB prepare failed: " . ($info[2] ?? 'unknown error') . " — SQL: " . $sql);
    }
    if (!$stmt->execute($params)) {
        $info = $stmt->errorInfo();
        throw new Exception("DB execute failed: " . ($info[2] ?? 'unknown error') . " — SQL: " . $sql);
    }
    return $stmt->fetchAll();
}

function insert($table, $data)
{
    // Only add created_at if the table actually has that column
    if (!isset($data['created_at'])) {
        try {
            $cols = db()->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
            if (in_array('created_at', $cols)) {
                $data['created_at'] = now();
            }
        } catch (Exception $e) {}
    }
    $cols = array_keys($data);
    $placeholders = array_map(fn($c) => ':' . $c, $cols);
    $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = db()->prepare($sql);
    $stmt->execute(array_combine($placeholders, array_values($data)));
    return db()->lastInsertId();
}

function update($table, $data, $where, $whereParams = [])
{
    // Use positional (?) placeholders throughout
    $setParts = [];
    $values = [];
    foreach ($data as $col => $val) {
        $setParts[] = "$col = ?";
        $values[] = $val;
    }
    $setPart = implode(', ', $setParts);
    $sql = "UPDATE $table SET $setPart WHERE $where";
    $stmt = db()->prepare($sql);
    // Data values come first (for SET ?), then WHERE params
    $allParams = array_merge($values, array_values($whereParams));
    $stmt->execute($allParams);
    return $stmt->rowCount();
}

/* ----------------------------------------------------------------------------
 * LOOKUP HELPERS
 * -------------------------------------------------------------------------- */
function employee_name($id)
{
    if (!$id) return '—';
    $row = fetch_one("SELECT full_name FROM employees WHERE id = ?", [$id]);
    return $row ? $row['full_name'] : '—';
}

function department_name($id)
{
    if (!$id) return '—';
    $row = fetch_one("SELECT name FROM departments WHERE id = ?", [$id]);
    return $row ? $row['name'] : '—';
}

function departments_list()
{
    return fetch_all("SELECT * FROM departments ORDER BY name");
}

/**
 * Ensure the currently logged-in user has a linked employee record.
 *
 * Standalone accounts (created from User Management WITHOUT a linked
 * employee) have employee_id = NULL. For such accounts the Profile /
 * "Edit My Info" page silently failed to save, because every UPDATE runs
 * "WHERE id = NULL" (0 rows affected). This helper auto-creates a minimal
 * employee stub and links it to the user on first access to their profile,
 * so editing personal info always works — for admins, HR, everyone.
 *
 * @return int|null  employee_id (or null if not logged in / can't create)
 */
function ensure_self_employee()
{
    if (!function_exists('is_logged_in') || !is_logged_in()) return null;

    $uid   = current_user_id();
    $empId = current_employee_id();

    // Already linked AND the employee row actually exists?
    if ($empId) {
        $row = fetch_one("SELECT id FROM employees WHERE id = ?", [$empId]);
        if ($row) return (int)$empId;
    }

    // No valid employee profile — create a stub and link it to this user
    $u = fetch_one("SELECT username, email, role FROM users WHERE id = ?", [$uid]);
    if (!$u) return null;

    $base = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $u['username'] ?: 'user'), 0, 4));
    if ($base === '') $base = 'USER';
    do {
        $code = 'SCG-' . $base . rand(100, 999);
    } while (fetch_one("SELECT id FROM employees WHERE employee_code = ?", [$code]));

    // Avoid UNIQUE(email) conflict — only set email if no other employee has it
    $emailVal = null;
    if (!empty($u['email'])) {
        $conflict = fetch_one("SELECT id FROM employees WHERE email = ?", [$u['email']]);
        $emailVal = $conflict ? null : $u['email'];
    }

    $fullName = ucfirst($u['username'] ?: 'User');
    $newId = insert('employees', [
        'employee_code'   => $code,
        'first_name'      => $fullName,
        'last_name'       => '',
        'full_name'       => $fullName,
        'email'           => $emailVal,
        'designation'     => ucfirst($u['role'] ?: 'Employee'),
        'employment_type' => 'Permanent',
        'joining_date'    => today(),
        'status'          => 'Active',
    ]);

    // Link the user account to the new employee + update the live session
    update('users', ['employee_id' => $newId], 'id = ?', [$uid]);
    if (isset($_SESSION['user'])) {
        $_SESSION['user']['employee_id'] = $newId;
        $_SESSION['user']['full_name']   = $fullName;
    }
    return (int)$newId;
}

/* ----------------------------------------------------------------------------
 * NOTIFICATIONS
 * -------------------------------------------------------------------------- */
function notify($userId, $title, $message, $link = null)
{
    insert('notifications', [
        'user_id' => $userId,
        'audience' => 'user',
        'title' => $title,
        'message' => $message,
        'link' => $link,
    ]);
}

function log_activity($action, $details = '')
{
    if (function_exists('current_user_id')) {
        insert('activity_log', [
            'user_id'    => current_user_id(),
            'action'     => $action,
            'details'    => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
    }
}

/* ----------------------------------------------------------------------------
 * UPLOAD HELPER
 * -------------------------------------------------------------------------- */
function handle_upload($field, $destDir, $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload failed with error code ' . $file['error']);
    }
    if ($file['size'] > MAX_UPLOAD_MB * 1048576) {
        throw new Exception('File exceeds maximum allowed size (' . MAX_UPLOAD_MB . 'MB).');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        throw new Exception('File type not allowed.');
    }
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $newName = uniqid('up_', true) . '.' . $ext;
    $target = rtrim($destDir, '/') . '/' . $newName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Could not save uploaded file.');
    }
    return basename($target);
}

/* ----------------------------------------------------------------------------
 * MISC
 * -------------------------------------------------------------------------- */
function initials($name)
{
    if (!$name) return '?';
    $parts = preg_split('/\s+/', trim($name));
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) $ini .= strtoupper(substr($p, 0, 1));
    return $ini ?: '?';
}

function json_response($data, $code = 200)
{
    // Clean any buffered output before sending JSON
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function read_pdf_text($filePath)
{
    // Best-effort: requires pdftotext (poppler-utils). Returns '' if unavailable.
    if (!is_file($filePath)) return '';
    $cmd = 'pdftotext ' . escapeshellarg($filePath) . ' - 2>/dev/null';
    $out = @shell_exec($cmd);
    return $out ? trim($out) : '';
}

/* ----------------------------------------------------------------------------
 * SETTINGS (read/write from DB, with fallback to config constants)
 * -------------------------------------------------------------------------- */
function get_setting($key, $default = '')
{
    // Direct DB query — no static cache (prevents stale data after save_setting)
    try {
        $stmt = db()->prepare("SELECT value FROM settings WHERE key_name = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? $row['value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

function save_setting($key, $value)
{
    $stmt = db()->prepare("INSERT OR REPLACE INTO settings (key_name, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

/**
 * AUTO-CLEANUP: Delete attendance selfies older than 30 days
 * Runs silently in background. Removes image files to save server storage.
 * Attendance records (time, location, status) are preserved — only photos deleted.
 */
function cleanup_old_selfies($days = 30)
{
    $cutoffDate = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    
    // Get old records with selfies
    $old = fetch_all(
        "SELECT id, clock_in_selfie, clock_out_selfie FROM attendance 
         WHERE attendance_date < date('now', '-$days days') 
         AND (clock_in_selfie IS NOT NULL OR clock_out_selfie IS NOT NULL)",
        []
    );
    
    $deleted = 0;
    foreach ($old as $row) {
        // Delete clock-in selfie file
        if (!empty($row['clock_in_selfie'])) {
            $filePath = UPLOAD_DIR . $row['clock_in_selfie'];
            if (is_file($filePath)) @unlink($filePath);
        }
        // Delete clock-out selfie file
        if (!empty($row['clock_out_selfie'])) {
            $filePath = UPLOAD_DIR . $row['clock_out_selfie'];
            if (is_file($filePath)) @unlink($filePath);
        }
        // Remove selfie references from DB (keep the attendance record!)
        update('attendance', [
            'clock_in_selfie' => null,
            'clock_out_selfie' => null,
        ], 'id = ?', [$row['id']]);
        $deleted++;
    }
    
    return $deleted;
}

/* ----------------------------------------------------------------------------
 * WHATSAPP API (Baileys integration — settings from DB)
 * -------------------------------------------------------------------------- */

/**
 * Normalize a phone/WhatsApp number to international format (no +, no spaces).
 * Robust: handles 0300..., 300..., +92..., 0092..., 92300..., etc.
 */
function normalize_whatsapp($number, $country = null)
{
    if (!$number) return '';
    $country = $country ?: get_setting('wa_country', '92');
    $n = preg_replace('/[^0-9]/', '', (string)$number);
    if ($n === '') return '';

    // Strip international "00" prefix (e.g. 0092...)
    if (strpos($n, '00') === 0) $n = substr($n, 2);

    // Already has the country code AND looks long enough? keep as-is.
    if (strlen($n) > strlen($country) && strpos($n, $country) === 0) {
        return $n;
    }
    // Otherwise strip any leading 0 and prepend the country code
    if (strpos($n, '0') === 0) $n = substr($n, 1);
    return $country . $n;
}

/**
 * Check whether a number looks like a valid WhatsApp mobile number
 * AFTER normalization (for PK: 92 + 10 digits = 12 total).
 */
function is_valid_whatsapp($number)
{
    $n = normalize_whatsapp($number);
    if ($n === '') return false;
    // generic sanity: between 10 and 15 digits (E.164 range)
    if (strlen($n) < 10 || strlen($n) > 15) return false;
    // for Pakistan (default), expect 12 digits starting with 92
    $country = get_setting('wa_country', '92');
    if ($country === '92' && !(strlen($n) === 12 && substr($n, 0, 2) === '92')) {
        return false;
    }
    return true;
}

/**
 * Send a WhatsApp message via Baileys API.
 * Reads settings from DB (configurable from Settings page).
 */
function send_whatsapp($to, $message)
{
    $enabled = get_setting('wa_enabled', '1');
    if ($enabled !== '1') {
        return ['ok' => false, 'error' => 'WhatsApp disabled in Settings'];
    }
    $number = normalize_whatsapp($to);
    if (!$number) return ['ok' => false, 'error' => 'Invalid number'];
    return send_whatsapp_raw($number, $message);
}

/**
 * Send to an EXACT chatId (e.g. a WhatsApp GROUP JID like 120363xxx@g.us).
 * Bypasses number normalization — use this for groups so the @g.us id is
 * not mangled into a phone number.
 */
function send_whatsapp_raw($chatId, $message)
{
    $enabled = get_setting('wa_enabled', '1');
    if ($enabled !== '1') {
        return ['ok' => false, 'error' => 'WhatsApp disabled in Settings'];
    }
    $chatId = trim((string)$chatId);
    if ($chatId === '') return ['ok' => false, 'error' => 'Empty chatId'];

    $apiUrl = rtrim(get_setting('wa_api_url', ''), '/');
    if (!$apiUrl) return ['ok' => false, 'error' => 'WhatsApp API URL not configured in Settings'];
    $session = get_setting('wa_session', 'default');
    $token = get_setting('wa_token', '');

    // Build endpoint + payload (same as noc.spotcomm.pk ticket system)
    $endpoint = $apiUrl . '/api/sendText';
    $payload = json_encode([
        'chatId'  => $chatId,
        'text'    => $message,
        'session' => $session,
    ]);

    $result = http_post_json($endpoint, $payload, $token);
    return $result['ok']
        ? ['ok' => true, 'error' => null]
        : ['ok' => false, 'error' => $result['error'] ?: "HTTP {$result['http']}"];
}

/**
 * DEBUG send — returns the FULL API response (http code + raw body) so the
 * admin can see exactly what wa.spotcomm.pk replied. Used by the diagnostic
 * "WhatsApp Number Check" page.
 */
function send_whatsapp_debug($to, $message)
{
    $apiUrl = rtrim(get_setting('wa_api_url', ''), '/');
    if (!$apiUrl) return ['ok' => false, 'error' => 'WhatsApp API URL not configured', 'body' => '', 'http' => 0, 'number' => ''];
    $session = get_setting('wa_session', 'default');
    $token = get_setting('wa_token', '');
    $number = normalize_whatsapp($to);
    $endpoint = $apiUrl . '/api/sendText';
    $payload = json_encode(['chatId' => $number, 'text' => $message, 'session' => $session]);
    $r = http_post_json($endpoint, $payload, $token);
    return [
        'ok'     => $r['ok'],
        'error'  => $r['error'] ?? null,
        'http'   => $r['http'] ?? 0,
        'body'   => $r['body'] ?? '',
        'number' => $number,  // the EXACT chatId sent to the API
    ];
}

/**
 * BROADCAST TO WHATSAPP GROUP — sends ONE message to the configured broadcast
 * group (chatId stored in setting 'wa_broadcast_group'). Every member of the
 * group receives it. This is the SAFE method: 1 API send instead of N individual
 * sends, so WhatsApp will NOT ban/block your number.
 *
 * Requires: create a WhatsApp group on the linked phone, add all employees,
 * and paste the group's @g.us id in Settings → WhatsApp → Broadcast Group.
 *
 * @return array ['ok'=>bool, 'error'=>string|null]
 */
function broadcast_to_group($message)
{
    $groupId = trim(get_setting('wa_broadcast_group', ''));
    if ($groupId === '') {
        return ['ok' => false, 'error' => 'No WhatsApp broadcast group configured. Set it in Settings → WhatsApp → Broadcast Group.'];
    }
    $r = send_whatsapp_raw($groupId, $message);
    if ($r['ok']) {
        return ['ok' => true, 'error' => null, 'group' => $groupId];
    }
    return ['ok' => false, 'error' => $r['error']];
}

/**
 * Best-effort: try to list WhatsApp groups from the Baileys API so the admin
 * can pick the broadcast group from a dropdown instead of typing the @g.us id.
 * Different Baileys forks expose groups under different paths, so we try a few.
 *
 * @return array [['id'=>string,'name'=>string], ...]  (empty if not available)
 */
function fetch_whatsapp_groups()
{
    $apiUrl = rtrim(get_setting('wa_api_url', ''), '/');
    if (!$apiUrl) return [];
    $session = get_setting('wa_session', 'default');
    $token = get_setting('wa_token', '');

    // WAHA (the API at wa.spotcomm.pk) puts the session in the PATH, e.g.
    //   GET /api/default/chats
    // and returns [{id:"...@g.us", name:"..."}, ...]. We also keep a few
    // legacy query-style fallbacks in case a different fork is in use.
    $sp = '/api/' . urlencode($session);
    $candidates = [
        $apiUrl . $sp . '/chats?limit=1000&sortBy=name&sortOrder=asc',  // WAHA — all chats (incl. groups)
        $apiUrl . $sp . '/chats/overview?limit=1000',                   // WAHA — chats overview
        $apiUrl . '/api/groups?session=' . urlencode($session),         // other forks (query style)
        $apiUrl . '/api/chats?session=' . urlencode($session),
        $apiUrl . '/api/groups',
    ];

    foreach ($candidates as $url) {
        $resp = http_get_json($url, $token);
        if (!$resp['ok'] || $resp['body'] === '') continue;
        $data = json_decode($resp['body'], true);
        if ($data === null) continue;

        // Normalize various response shapes into a flat list
        $list = $data;
        foreach (['groups', 'response', 'data', 'result'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) { $list = $data[$key]; break; }
        }
        if (!is_array($list)) continue;

        $groups = [];
        foreach ($list as $g) {
            if (!is_array($g)) continue;
            $id   = $g['id'] ?? ($g['jid'] ?? ($g['chatId'] ?? ($g['remoteJid'] ?? '')));
            $name = $g['name'] ?? ($g['subject'] ?? ($g['title'] ?? ($g['groupName'] ?? '')));
            // Only keep real group ids (groups end with @g.us)
            if ($id && strpos($id, '@g.us') !== false) {
                $groups[] = ['id' => $id, 'name' => $name ?: $id];
            }
        }
        if ($groups) return $groups;
    }
    return [];
}

/**
 * Resolve a WhatsApp group JID (@g.us) from a group invite link / code.
 * Works with WAHA via  GET /api/{session}/groups/join-info?code=XXXX
 * The boss can get the invite link from the group on the phone
 * (Group info → Invite via link). Much more reliable than guessing the JID.
 *
 * @param string $inviteInput  full URL (https://chat.whatsapp.com/ABCD) or bare code
 * @return array ['ok'=>bool, 'jid'=>string, 'name'=>string, 'error'=>string|null]
 */
function resolve_group_by_invite($inviteInput)
{
    $apiUrl = rtrim(get_setting('wa_api_url', ''), '/');
    if (!$apiUrl) return ['ok' => false, 'error' => 'WhatsApp API URL not configured', 'jid' => '', 'name' => ''];
    $session = get_setting('wa_session', 'default');
    $token = get_setting('wa_token', '');

    // Extract the invite code from a full URL or use the bare input
    $code = trim((string)$inviteInput);
    if (preg_match('#chat\.whatsapp\.com/([A-Za-z0-9]+)#i', $code, $m)) {
        $code = $m[1];
    }
    if ($code === '') {
        return ['ok' => false, 'error' => 'Could not find an invite code. Paste the full chat.whatsapp.com link.', 'jid' => '', 'name' => ''];
    }

    $url = $apiUrl . '/api/' . urlencode($session) . '/groups/join-info?code=' . urlencode($code);
    $resp = http_get_json($url, $token);
    if (!$resp['ok']) {
        return ['ok' => false, 'error' => 'API error (HTTP ' . $resp['http'] . '). ' . ($resp['error'] ?? ''), 'jid' => '', 'name' => ''];
    }
    $body = (string)$resp['body'];

    $jid = null; $name = null;
    $data = json_decode($body, true);
    if (is_array($data)) {
        foreach (['id', 'jid', 'groupId', 'groupJid', 'chatId', 'remoteJid'] as $k) {
            if (!empty($data[$k]) && strpos($data[$k], '@g.us') !== false) { $jid = $data[$k]; break; }
        }
        foreach (['subject', 'name', 'groupName', 'title'] as $k) {
            if (!empty($data[$k])) { $name = $data[$k]; break; }
        }
    }
    // Fallback: regex-extract the @g.us id from the raw response
    if (!$jid && preg_match('/[\w-]+@g\.us/', $body, $m)) {
        $jid = $m[0];
    }
    if (!$jid) {
        return ['ok' => false, 'error' => 'Could not find a group ID in the API response. Make sure the invite link is valid.', 'jid' => '', 'name' => ''];
    }
    return ['ok' => true, 'jid' => $jid, 'name' => $name ?: 'Broadcast Group', 'error' => null];
}

/**
 * HTTP POST helper — tries multiple methods for max compatibility.
 * Priority: fsockopen (most reliable for SSL) → curl → file_get_contents
 */
function http_post_json($url, $payload, $token = '')
{
    // Method 1: fsockopen (raw sockets — works on virtually ALL servers)
    $result = http_post_fsockopen($url, $payload, $token);
    if ($result !== null) return $result;

    // Method 2: cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        $headers = array_filter(['Content-Type: application/json', $token ? 'Authorization: Bearer ' . $token : '']);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        if ($curlError) return ['ok' => false, 'http' => 0, 'error' => 'curl: ' . $curlError];
        $json = json_decode($response, true);
        if (isset($json['success']) && $json['success'] === true)
            return ['ok' => true, 'http' => $httpCode, 'error' => null];
        return ['ok' => false, 'http' => $httpCode, 'error' => $json['message'] ?? "HTTP $httpCode"];
    }

    // Method 3: file_get_contents
    $headers = "Content-Type: application/json\r\n";
    if ($token) $headers .= "Authorization: Bearer $token\r\n";
    $ctx = stream_context_create([
        'http' => ['method' => 'POST', 'header' => $headers, 'content' => $payload, 'timeout' => 15, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) $httpCode = (int)$m[1];
    }
    if ($response === false && $httpCode === 0)
        return ['ok' => false, 'http' => 0, 'error' => 'All HTTP methods failed (fsockopen, curl, file_get_contents)'];
    $json = json_decode($response, true);
    if (isset($json['success']) && $json['success'] === true)
        return ['ok' => true, 'http' => $httpCode, 'error' => null];
    return ['ok' => false, 'http' => $httpCode, 'error' => $json['message'] ?? "HTTP $httpCode"];
}

/**
 * Raw socket HTTP POST using fsockopen — no SSL certificate issues.
 * Returns null if it can't be used (falls through to other methods).
 */
function http_post_fsockopen($url, $payload, $token = '')
{
    $parts = parse_url($url);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    if (!$host) return null;

    $remote = ($scheme === 'https' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 10);
    if (!$fp) return null; // fall through to other methods

    // CRITICAL: Set stream timeout to prevent infinite hang
    stream_set_timeout($fp, 10);

    $req = "POST $path HTTP/1.1\r\n";
    $req .= "Host: $host\r\n";
    $req .= "User-Agent: Spotcomm-HRIS/1.0\r\n"; // ADDED: Many APIs block default PHP clients
    $req .= "Accept: */*\r\n";
    $req .= "Content-Type: application/json\r\n";
    $req .= "Content-Length: " . strlen($payload) . "\r\n";
    if ($token) $req .= "Authorization: Bearer $token\r\n";
    $req .= "Connection: close\r\n\r\n";
    $req .= $payload;

    fwrite($fp, $req);
    $raw = '';
    $startTime = time();
    while (!feof($fp)) {
        $data = fread($fp, 8192);
        if ($data === false || $data === '') break;
        $raw .= $data;
        // Safety: if more than 10 seconds, force break
        if (time() - $startTime > 10) break;
        // Check stream metadata for timeout
        $info = stream_get_meta_data($fp);
        if ($info['timed_out']) break;
    }
    fclose($fp);

    // Split headers and body
    $split = strpos($raw, "\r\n\r\n");
    $headerBlock = $split !== false ? substr($raw, 0, $split) : '';
    $body = $split !== false ? substr($raw, $split + 4) : $raw;

    // Handle chunked transfer encoding
    if (stripos($headerBlock, 'Transfer-Encoding: chunked') !== false) {
        $body = decode_chunked($body);
    }

    // Extract HTTP status code
    $httpCode = 0;
    if (preg_match('/HTTP\/\S+\s+(\d+)/', $raw, $m)) $httpCode = (int)$m[1];

    $json = json_decode($body, true);
    if (isset($json['success']) && $json['success'] === true) {
        return ['ok' => true, 'http' => $httpCode, 'error' => null, 'body' => $body];
    }
    return ['ok' => false, 'http' => $httpCode, 'error' => ($json['message'] ?? "HTTP $httpCode"), 'body' => $body];
}

/** Decode HTTP chunked transfer encoding */
function decode_chunked($str)
{
    $out = '';
    $pos = 0;
    while ($pos < strlen($str)) {
        $lineEnd = strpos($str, "\r\n", $pos);
        if ($lineEnd === false) break;
        $len = hexdec(trim(substr($str, $pos, $lineEnd - $pos)));
        if ($len == 0) break;
        $out .= substr($str, $lineEnd + 2, $len);
        $pos = $lineEnd + 2 + $len + 2;
    }
    return $out;
}

/**
 * HTTP GET helper — used to fetch the WhatsApp group list from the Baileys API.
 * Tries fsockopen first (works on virtually all servers), then file_get_contents.
 * @return array ['ok'=>bool,'http'=>int,'body'=>string,'error'=>string|null]
 */
function http_get_json($url, $token = '')
{
    // Method 1: fsockopen (raw sockets)
    $result = http_get_fsockopen($url, $token);
    if ($result !== null) return $result;

    // Method 2: file_get_contents
    $headers = '';
    if ($token) $headers = 'Authorization: Bearer ' . $token . "\r\n";
    $ctx = stream_context_create([
        'http' => ['method' => 'GET', 'header' => $headers, 'timeout' => 12, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) $httpCode = (int)$m[1];
    }
    if ($body === false && $httpCode === 0) {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'GET failed (no method worked)'];
    }
    return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http' => $httpCode, 'body' => (string)$body, 'error' => null];
}

/**
 * Raw socket HTTP GET using fsockopen.
 * Returns null if it can't be used (falls through to file_get_contents).
 */
function http_get_fsockopen($url, $token = '')
{
    $parts = parse_url($url);
    $scheme = $parts['scheme'] ?? 'https';
    $host = $parts['host'] ?? '';
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

    if (!$host) return null;
    $remote = ($scheme === 'https' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($remote, $port, $errno, $errstr, 10);
    if (!$fp) return null;

    stream_set_timeout($fp, 10);

    $req = "GET $path HTTP/1.1\r\n";
    $req .= "Host: $host\r\n";
    $req .= "User-Agent: Spotcomm-HRIS/1.0\r\n";
    $req .= "Accept: */*\r\n";
    if ($token) $req .= "Authorization: Bearer $token\r\n";
    $req .= "Connection: close\r\n\r\n";

    fwrite($fp, $req);
    $raw = '';
    $startTime = time();
    while (!feof($fp)) {
        $data = fread($fp, 8192);
        if ($data === false || $data === '') break;
        $raw .= $data;
        if (time() - $startTime > 10) break;
        $info = stream_get_meta_data($fp);
        if ($info['timed_out']) break;
    }
    fclose($fp);

    $split = strpos($raw, "\r\n\r\n");
    $headerBlock = $split !== false ? substr($raw, 0, $split) : '';
    $body = $split !== false ? substr($raw, $split + 4) : $raw;
    if (stripos($headerBlock, 'Transfer-Encoding: chunked') !== false) {
        $body = decode_chunked($body);
    }
    $httpCode = 0;
    if (preg_match('/HTTP\/\S+\s+(\d+)/', $raw, $m)) $httpCode = (int)$m[1];

    return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http' => $httpCode, 'body' => $body, 'error' => null];
}

/**
 * Queue WhatsApp messages for broadcast (SAFE - random intervals)
 * Messages stored in queue, sent 1 per 30-45 seconds by cron.
 * 1st message goes immediately, rest are spaced randomly.
 */
function broadcast_whatsapp_queue($message)
{
    $employees = fetch_all("SELECT id, full_name, whatsapp FROM employees WHERE status='Active' AND whatsapp IS NOT NULL AND whatsapp != ''");
    $queued = 0;
    $baseTime = time();

    foreach ($employees as $i => $emp) {
        if ($i === 0) {
            // 1st message: immediate (0 seconds)
            $sendAfter = gmdate('Y-m-d H:i:s', $baseTime);
        } else {
            // Random gap: 30-45 seconds per message (anti-detection)
            $minGap = 30;
            $maxGap = 45;
            $baseTime += rand($minGap, $maxGap);
            $sendAfter = gmdate('Y-m-d H:i:s', $baseTime);
        }
        insert('whatsapp_queue', [
            'employee_id' => $emp['id'],
            'employee_name' => $emp['full_name'],
            'whatsapp_number' => $emp['whatsapp'],
            'message' => $message,
            'channel' => 'whatsapp',
            'status' => 'pending',
            'send_after' => $sendAfter,
        ]);
        $queued++;
    }
    return ['queued' => $queued];
}

/**
 * Queue WhatsApp + Email messages for broadcast (SAFE - random intervals)
 */
function broadcast_all_queue($message, $subject = 'Spotcomm Global Update')
{
    $employees = fetch_all("SELECT id, full_name, whatsapp, email FROM employees WHERE status='Active'");
    $queued = 0;
    $baseTime = time();
    $msgIndex = 0;

    foreach ($employees as $emp) {
        if (!empty($emp['whatsapp'])) {
            if ($msgIndex === 0) {
                $sendAfter = gmdate('Y-m-d H:i:s', $baseTime);
            } else {
                $baseTime += rand(30, 45);
                $sendAfter = gmdate('Y-m-d H:i:s', $baseTime);
            }
            insert('whatsapp_queue', [
                'employee_id' => $emp['id'],
                'employee_name' => $emp['full_name'],
                'whatsapp_number' => $emp['whatsapp'],
                'message' => $message,
                'channel' => 'whatsapp',
                'status' => 'pending',
                'send_after' => $sendAfter,
            ]);
            $queued++;
            $msgIndex++;
        }
        // Emails can go instantly (no blocking risk), but we queue them too for consistency
        if (!empty($emp['email'])) {
            insert('whatsapp_queue', [
                'employee_id' => $emp['id'],
                'employee_name' => $emp['full_name'],
                'email' => $emp['email'],
                'message' => $message,
                'subject' => $subject,
                'channel' => 'email',
                'status' => 'pending',
                'send_after' => gmdate('Y-m-d H:i:s', time()),
            ]);
            $queued++;
        }
    }
    return ['queued' => $queued];
}

/**
 * Queue EMAIL-only broadcast for all active employees (used when WhatsApp goes
 * to the broadcast group but HR also wants email). Emails are queued and sent
 * by the background queue processor — avoids request timeout for large staff.
 *
 * @return int number of emails queued
 */
function broadcast_emails_queue($message, $subject = 'Spotcomm Global Update')
{
    $employees = fetch_all("SELECT id, full_name, email FROM employees WHERE status='Active' AND email IS NOT NULL AND email != ''");
    $queued = 0;
    $now = gmdate('Y-m-d H:i:s', time());
    foreach ($employees as $emp) {
        insert('whatsapp_queue', [
            'employee_id'   => $emp['id'],
            'employee_name' => $emp['full_name'],
            'email'         => $emp['email'],
            'message'       => $message,
            'subject'       => $subject,
            'channel'       => 'email',
            'status'        => 'pending',
            'send_after'    => $now,
        ]);
        $queued++;
    }
    return $queued;
}

/* ----------------------------------------------------------------------------
 * EMAIL / SMTP
 * -------------------------------------------------------------------------- */

/**
 * Send an email. Uses PHP's built-in mail() by default.
 * Configure SMTP_* constants in config for SMTP relay.
 *
 * @return array ['ok'=>bool, 'error'=>string|null]
 */
function send_email($to, $subject, $body)
{
    $mailFrom = get_setting('mail_from', 'no-reply@spotcomm.pk');
    $mailName = get_setting('mail_from_name', APP_COMPANY);

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $mailName <$mailFrom>\r\n";
    $htmlBody = nl2br(htmlspecialchars($body));
    $htmlBody = '<div style="font-family:Mulish,Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">
      <div style="background:linear-gradient(135deg,#7F3E98,#9B59B6);padding:20px;text-align:center;border-radius:10px 10px 0 0">
        <h2 style="color:#fff;margin:0">' . APP_COMPANY . '</h2>
      </div>
      <div style="padding:24px;background:#fff;border:1px solid #eee">' . $htmlBody . '</div>
      <div style="padding:12px;text-align:center;font-size:11px;color:#999">Spotcomm Global HRIS</div>
    </div>';

    $sent = @mail($to, $subject, $htmlBody, $headers);
    return $sent ? ['ok' => true, 'error' => null] : ['ok' => false, 'error' => 'mail() failed — configure SMTP in cPanel'];
}
