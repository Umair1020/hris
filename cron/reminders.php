<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - ATTENDANCE REMINDERS (CRON JOB)
 * ============================================================================
 * Set up a cron job in cPanel to run this every 15 minutes:
 *   (every 15 min) php -q /home/username/public_html/cron/reminders.php
 * 
 * Or call via URL (for web cron services):
 *   https://hris.spotcomm.pk/cron/reminders.php?key=YOUR_CRON_KEY
 * 
 * Sends WhatsApp + Email reminders when:
 *   1. Employee forgets to check-in (default: 10:30 AM)
 *   2. Employee checked in but forgot to check-out (default: 7:00 PM)
 * ============================================================================
 */

// --- CRON KEY SECURITY (prevent unauthorized access via web) ---
// Set this to a random string. Required when accessing via URL.
define('CRON_KEY', 'spotcomm-cron-2026');

// Allow CLI access without key, web access requires ?key=
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== CRON_KEY) {
        http_response_code(403);
        die('Access denied. This script requires a cron key.');
    }
}

require_once __DIR__ . '/../includes/header.php';

$today = today();
$now = date('H:i');
$sentCount = 0;
$log = [];

// Get reminder settings
$checkinEnabled = get_setting('reminder_checkin_enabled', '1') === '1';
$checkinTime = get_setting('reminder_checkin_time', '10:30');
$checkoutEnabled = get_setting('reminder_checkout_enabled', '1') === '1';
$checkoutTime = get_setting('reminder_checkout_time', '19:00');

// Get all active employees
$employees = fetch_all("SELECT * FROM employees WHERE status = 'Active'");
$log[] = "Running reminders at $now for " . count($employees) . " employees";

// Keep the attendance sheet complete (auto Absent for past working days)
$absents = att_sync_absents(true);
$log[] = "Auto-absent sync: $absents day(s) marked";

foreach ($employees as $emp) {
    // Skip if no contact info
    if (empty($emp['whatsapp']) && empty($emp['email'])) continue;

    // Skip weekly off / public holidays — no reminder needed
    if (att_day_kind($emp, $today) !== 'work') continue;

    // Get today's attendance
    $att = fetch_one("SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?", [$emp['id'], $today]);
    // On approved leave / holiday rows nothing to remind
    if ($att && !$att['clock_in'] && !in_array($att['status'], ['present', 'late', 'absent', 'half_day'])) continue;

    // --- REMINDER 1: Forgot to check-in ---
    if ($checkinEnabled && $now >= $checkinTime) {
        if (!$att || !$att['clock_in']) {
            // Check if reminder already sent today
            $alreadySent = fetch_one(
                "SELECT id FROM attendance_reminders WHERE employee_id = ? AND reminder_type = 'checkin' AND reminder_date = ?",
                [$emp['id'], $today]
            );
            if (!$alreadySent) {
                $msg = "⏰ *Attendance Reminder*\n\n";
                $msg .= "Hi {$emp['full_name']},\n\n";
                $msg .= "You haven't marked your Clock IN yet today.\n\n";
                $msg .= "Please mark your attendance now:\n";
                $msg .= APP_URL . "modules/attendance/mark.php\n\n";
                $msg .= "_Spotcomm Global HR_";

                $sent = false;
                if (!empty($emp['whatsapp'])) {
                    $r = send_whatsapp($emp['whatsapp'], $msg);
                    if ($r['ok']) $sent = true;
                }
                if (!empty($emp['email'])) {
                    $r = send_email($emp['email'], '⏰ Attendance Reminder - Clock IN Pending', $msg);
                    if ($r['ok']) $sent = true;
                }

                if ($sent) {
                    insert('attendance_reminders', [
                        'employee_id' => $emp['id'], 'reminder_type' => 'checkin', 'reminder_date' => $today,
                    ]);
                    $sentCount++;
                    $log[] = "  ✅ Check-in reminder → {$emp['full_name']}";
                }
            }
        }
    }

    // --- REMINDER 2: Forgot to check-out ---
    if ($checkoutEnabled && $now >= $checkoutTime) {
        if ($att && $att['clock_in'] && !$att['clock_out']) {
            // Check if reminder already sent today
            $alreadySent = fetch_one(
                "SELECT id FROM attendance_reminders WHERE employee_id = ? AND reminder_type = 'checkout' AND reminder_date = ?",
                [$emp['id'], $today]
            );
            if (!$alreadySent) {
                $clockInTime = date('h:i A', strtotime($att['clock_in']));
                $msg = "⏰ *Checkout Reminder*\n\n";
                $msg .= "Hi {$emp['full_name']},\n\n";
                $msg .= "You clocked IN at {$clockInTime} but haven't clocked OUT yet.\n\n";
                $msg .= "Please mark your Clock OUT now:\n";
                $msg .= APP_URL . "modules/attendance/mark.php\n\n";
                $msg .= "_Spotcomm Global HR_";

                $sent = false;
                if (!empty($emp['whatsapp'])) {
                    $r = send_whatsapp($emp['whatsapp'], $msg);
                    if ($r['ok']) $sent = true;
                }
                if (!empty($emp['email'])) {
                    $r = send_email($emp['email'], '⏰ Checkout Reminder - Clock OUT Pending', $msg);
                    if ($r['ok']) $sent = true;
                }

                if ($sent) {
                    insert('attendance_reminders', [
                        'employee_id' => $emp['id'], 'reminder_type' => 'checkout', 'reminder_date' => $today,
                    ]);
                    $sentCount++;
                    $log[] = "  ✅ Check-out reminder → {$emp['full_name']}";
                }
            }
        }
    }
}

$log[] = "Done. Total reminders sent: $sentCount";

// Output log
if (php_sapi_name() === 'cli') {
    echo implode("\n", $log) . "\n";
} else {
    header('Content-Type: text/plain');
    echo implode("\n", $log) . "\n";
}
