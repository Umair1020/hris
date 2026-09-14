<?php
/**
 * ============================================================================
 * BROADCAST SEND-NEXT — AJAX endpoint used by the Broadcast Sender page.
 * Sends ONE pending WhatsApp message (the oldest), then returns the updated
 * queue stats as JSON. The Sender page calls this on a 40-50s timer so each
 * employee gets a separate, private message with a safe gap between sends
 * (anti-ban) — and employees never see each other's numbers.
 * ============================================================================
 */
require_once __DIR__ . '/../../includes/header.php'; // loads functions + session, NO html output
require_login('hr');

header('Content-Type: application/json');

// CSRF check (token passed from the sender page)
$token = $_GET['token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session token. Reload the page.']);
    exit;
}

function sender_stats()
{
    $row = fetch_one("SELECT
        SUM(status='pending' AND channel='whatsapp') AS pending,
        SUM(status='sent'    AND channel='whatsapp') AS sent,
        SUM(status='failed'  AND channel='whatsapp') AS failed
        FROM whatsapp_queue");
    return [
        'pending' => (int)($row['pending'] ?? 0),
        'sent'    => (int)($row['sent'] ?? 0),
        'failed'  => (int)($row['failed'] ?? 0),
    ];
}

$action = $_GET['do'] ?? 'next';

// ----- CLEAR all pending WhatsApp queue (fresh start) -----
if ($action === 'clear') {
    db()->prepare("DELETE FROM whatsapp_queue WHERE status='pending' AND channel='whatsapp'")->execute();
    $stats = sender_stats();
    echo json_encode(['ok' => true, 'cleared' => true] + $stats);
    exit;
}

// ----- RETRY failed messages (move them back to pending) -----
if ($action === 'retry') {
    db()->prepare("UPDATE whatsapp_queue SET status='pending', error=NULL WHERE status='failed' AND channel='whatsapp'")->execute();
    $stats = sender_stats();
    echo json_encode(['ok' => true, 'retried' => true] + $stats);
    exit;
}

// ----- SEND the next pending message -----
$msg = fetch_one("SELECT * FROM whatsapp_queue WHERE status='pending' AND channel='whatsapp' ORDER BY id ASC LIMIT 1");

if (!$msg) {
    $stats = sender_stats();
    echo json_encode(['ok' => true, 'done' => true] + $stats);
    exit;
}

$recipient = $msg['employee_name'] ?: $msg['whatsapp_number'];
$r = send_whatsapp($msg['whatsapp_number'], $msg['message']);

if ($r['ok']) {
    update('whatsapp_queue', ['status' => 'sent', 'sent_at' => now(), 'error' => null], 'id=?', [$msg['id']]);
    $result = 'sent';
} else {
    update('whatsapp_queue', ['status' => 'failed', 'error' => mb_substr($r['error'] ?? 'Unknown', 0, 200), 'sent_at' => now()], 'id=?', [$msg['id']]);
    $result = 'failed';
}

$stats = sender_stats();
echo json_encode([
    'ok'        => true,
    'done'      => false,
    'result'    => $result,                       // 'sent' | 'failed'
    'recipient' => $recipient,
    'number'    => $msg['whatsapp_number'],
    'error'     => $result === 'failed' ? ($r['error'] ?? 'Unknown') : null,
] + $stats);
