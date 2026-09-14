<?php
/**
 * ============================================================================
 * NOTIFICATIONS API — mark read, fetch count
 * ============================================================================
 */
require_once __DIR__ . '/includes/header.php';
require_login('employee');
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

if ($action === 'mark_read') {
    update('notifications', ['is_read' => 1], 'user_id = ?', [current_user_id()]);
    $count = (int)fetch_one("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0", [current_user_id()])['c'];
    json_response(['ok' => true, 'remaining' => $count]);
}

json_response(['ok' => false]);
