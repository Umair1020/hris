<?php
/**
 * ============================================================================
 * WHATSAPP QUEUE PROCESSOR — Runs via cron every minute
 * Sends ALL due messages in one run (1 message per WhatsApp, instant for email)
 * Random intervals set at queue time prevent WhatsApp blocking.
 * 
 * Cron: * * * * * curl -s "https://hris.spotcomm.pk/cron/process-queue.php?key=spotcomm-cron-2026" >/dev/null 2>&1
 * ============================================================================
 */

define('CRON_KEY', 'spotcomm-cron-2026');

if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== CRON_KEY) {
        http_response_code(403);
        die('Access denied.');
    }
}

require_once __DIR__ . '/../includes/header.php';

@set_time_limit(120); // Allow 2 minutes for batch processing

// AUTO-CLEANUP: Delete attendance selfies older than 30 days to save storage
$cleanupDays = (int)get_setting('selfie_cleanup_days', '30');
if ($cleanupDays > 0) {
    $cleaned = cleanup_old_selfies($cleanupDays);
}

$now = now();
$log = [];
$log[] = "Queue processor at $now";

// Get ALL pending WhatsApp messages that are due
$dueWhatsapp = fetch_all(
    "SELECT * FROM whatsapp_queue WHERE status = 'pending' AND channel = 'whatsapp' AND send_after <= ? ORDER BY send_after ASC LIMIT 3",
    [$now]
);

// Get ALL pending email messages (send all instantly - no blocking risk)
$dueEmail = fetch_all(
    "SELECT * FROM whatsapp_queue WHERE status = 'pending' AND channel = 'email' AND send_after <= ? ORDER BY send_after ASC",
    [$now]
);

$waSent = 0; $waFailed = 0; $emailSent = 0; $emailFailed = 0;

// Process WhatsApp messages (max 3 per minute to maintain safe rate)
foreach ($dueWhatsapp as $msg) {
    $r = send_whatsapp($msg['whatsapp_number'], $msg['message']);
    if ($r['ok']) {
        update('whatsapp_queue', ['status' => 'sent', 'sent_at' => now()], 'id = ?', [$msg['id']]);
        $waSent++;
    } else {
        update('whatsapp_queue', ['status' => 'failed', 'error' => $r['error'] ?? 'Unknown', 'sent_at' => now()], 'id = ?', [$msg['id']]);
        $waFailed++;
    }
    // Small delay between WhatsApp sends within same batch
    usleep(1000000); // 1 second
}

// Process email messages (all at once)
foreach ($dueEmail as $msg) {
    $r = send_email($msg['email'], $msg['subject'] ?: 'Spotcomm Global Update', $msg['message']);
    if ($r['ok']) {
        update('whatsapp_queue', ['status' => 'sent', 'sent_at' => now()], 'id = ?', [$msg['id']]);
        $emailSent++;
    } else {
        update('whatsapp_queue', ['status' => 'failed', 'error' => $r['error'] ?? 'Unknown', 'sent_at' => now()], 'id = ?', [$msg['id']]);
        $emailFailed++;
    }
}

// Stats
$stats = fetch_one("SELECT 
    SUM(status='pending' AND channel='whatsapp') as wa_pending,
    SUM(status='pending' AND channel='email') as email_pending,
    SUM(status='sent') as sent,
    SUM(status='failed') as failed
    FROM whatsapp_queue");

$log[] = "Processed: WA sent={$waSent} failed={$waFailed} | Email sent={$emailSent} failed={$emailFailed}";
$log[] = "Still pending: WA={$stats['wa_pending']} Email={$stats['email_pending']}";
$log[] = "Total: Sent={$stats['sent']} Failed={$stats['failed']}";

// Output (works for both browser and background fetch)
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'processed_wa' => $waSent,
    'processed_email' => $emailSent,
    'pending_wa' => (int)$stats['wa_pending'],
    'pending_email' => (int)$stats['email_pending'],
    'total_sent' => (int)$stats['sent'],
    'total_failed' => (int)$stats['failed'],
    'time' => $now,
]);
