<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - APPLICATION CONFIGURATION
 * ============================================================================
 */

/** safe define helper (only sets if not already defined) */
function _def($name, $value) { if (!defined($name)) define($name, $value); }

// Load environment overrides FIRST (created by installer) ---------------------
$envFile = __DIR__ . '/.env.php';
if (is_file($envFile)) require $envFile;

// ---- ERROR HANDLING (CRITICAL: must be set BEFORE anything else) -----------
_def('DEBUG_MODE', false);
if (!DEBUG_MODE) {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
// Output buffering — prevents "headers already sent" warnings from breaking redirects
if (!ob_get_level()) ob_start();

// CRITICAL SAFETY NET: if any PHP fatal/parse error happens (corrupt upload,
// bad code, etc.), SHOW the real error message instead of a blank "HTTP 500".
// Registered here (config.php is the first file loaded) so it catches errors
// in any later include. This means we NEVER get a blind 500 again.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR))) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!doctype html><meta charset="utf-8"><div style="font-family:Mulish,Arial,sans-serif;padding:40px;max-width:760px;margin:0 auto;color:#333">';
        echo '<h2 style="color:#c0392b;margin:0 0 8px">⚠️ HRIS hit an error</h2>';
        echo '<p style="color:#555;margin:0 0 14px">The page could not load because of a technical error. <strong>Your data is safe.</strong></p>';
        echo '<pre style="background:#1e1e2d;color:#a7f3d0;padding:16px;border-radius:8px;overflow:auto;white-space:pre-wrap;font-size:13px">' . htmlspecialchars($e['message']) . '</pre>';
        echo '<p style="color:#555"><strong>File:</strong> ' . htmlspecialchars($e['file']) . ' &nbsp;&nbsp; <strong>Line:</strong> ' . $e['line'] . '</p>';
        echo '<p style="margin-top:18px;color:#888;font-size:13px">Open <a href="' . rtrim(APP_URL ?? '', '/') . '/zz-diag.php" style="color:#7F3E98">zz-diag.php</a> for a full diagnostic, or send the error above to support.</p>';
        echo '</div>';
    }
});

// ---- APPLICATION SETTINGS --------------------------------------------------
_def('APP_NAME', 'Spotcomm HRIS');
_def('APP_COMPANY', 'Spotcomm Global');
_def('APP_TAGLINE', 'Outsource · Optimize · Thrive');
_def('APP_VERSION', '6.2.8');
_def('APP_ROOT', dirname(__DIR__));

// ---- AUTO-DETECT APPLICATION URL ------------------------------------------
if (!defined('APP_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptFs = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $appRootFs = str_replace('\\', '/', APP_ROOT);
    $relScript = '';
    if ($scriptFs && strpos($scriptFs, $appRootFs) === 0) {
        $relScript = ltrim(substr($scriptFs, strlen($appRootFs)), '/');
    }
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = $scriptName;
    if ($relScript !== '' && substr($scriptName, -strlen('/' . $relScript)) === '/' . $relScript) {
        $basePath = substr($scriptName, 0, strlen($scriptName) - strlen('/' . $relScript));
    }
    define('APP_URL', $protocol . '://' . $host . rtrim($basePath, '/') . '/');
}

// ---- AUTO-CACHE RESET (CRITICAL) ------------------------------------------
// This detects when new code is uploaded and automatically clears server cache.
// The user never has to manually clear cache again!
$versionFile = APP_ROOT . '/database/.app_version';
$currentVersion = APP_VERSION;
$storedVersion = is_file($versionFile) ? trim(file_get_contents($versionFile)) : '0';

if ($storedVersion !== $currentVersion) {
    // New version detected! Clear all server caches
    if (function_exists('opcache_reset')) @opcache_reset();
    if (function_exists('apc_clear_cache')) @apc_clear_cache();
    // Save the new version
    @file_put_contents($versionFile, $currentVersion);
}

// ---- SELF-HEAL .htaccess (fixes "Index of /" bare-domain listing) ----------
// .htaccess is a HIDDEN file that zip extractors/FTP often skip during upload.
// When it's missing, the bare domain (https://hris.spotcomm.pk) shows a
// directory listing instead of loading the app. So the app writes its own
// .htaccess on the first page load (e.g. when you open /index.php once).
$htaccessPath = APP_ROOT . '/.htaccess';
if (!is_file($htaccessPath)) {
    $hta = "# Spotcomm HRIS .htaccess (auto-created by app)\n"
         . "DirectoryIndex index.php index.html index.htm\n"
         . "Options -Indexes\n"
         . "ServerSignature Off\n"
         . "AddDefaultCharset UTF-8\n"
         . "<IfModule mod_headers.c>\n"
         . "  Header set Cache-Control \"no-store, no-cache, must-revalidate, max-age=0\"\n"
         . "</IfModule>\n"
         . "<IfModule mod_deflate.c>\n"
         . "  AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/json image/svg+xml\n"
         . "</IfModule>\n"
         . "<FilesMatch \"(^\\.|\\.(env|sql|md|log|ini|sh|bak)$)\">\n"
         . "  Require all denied\n"
         . "</FilesMatch>\n"
         . "RedirectMatch 403 ^/.*/config/.*$\n";
    @file_put_contents($htaccessPath, $hta);
}

// ---- DATABASE: SQLite by default (zero-config, no MySQL needed) ------------
if (!defined('DB_TYPE')) define('DB_TYPE', 'sqlite');
if (!defined('DB_PATH')) define('DB_PATH', APP_ROOT . '/database/spotcomm.db');

// ---- MySQL credentials (only used if DB_TYPE = 'mysql') --------------------
_def('DB_HOST', 'localhost');
_def('DB_NAME', 'spotcomm_hris');
_def('DB_USER', 'spotcomm_hris');
_def('DB_PASS', 'change-this-password');
_def('DB_CHARSET', 'utf8mb4');
if (!defined('DB_SOCKET')) define('DB_SOCKET', null);

// ---- BRANDING / THEME COLORS (extracted from spotcommglobal.com logo) ------
// Logo dominant color: #7F3E98 (purple)
_def('BRAND_PRIMARY',   '#7F3E98'); // brand purple (from actual logo)
_def('BRAND_PRIMARY_DARK', '#6B2F86'); // darker shade for gradients
_def('BRAND_PURPLE',    '#9B59B6'); // lighter purple
_def('BRAND_DARK',      '#2A1B3D'); // dark purple-navy
_def('BRAND_ACCENT',    '#F26223'); // orange accent
_def('BRAND_GREEN',     '#10b981'); // success green
_def('BRAND_RED',       '#ef4444'); // danger red

// ---- SECURITY --------------------------------------------------------------
_def('HASH_COST', 10);
_def('SESSION_LIFETIME', 7200);
_def('APP_KEY', 'CHANGE-ME-to-a-long-random-secret-string-32+chars');

// ---- DATE / TIMEZONE -------------------------------------------------------
date_default_timezone_set('Asia/Karachi');
_def('COMPANY_START_DAY', 'Monday');
_def('WORK_START', '09:00');
_def('WORK_END', '18:00');
_def('GRACE_MINUTES', 15);

// ---- FILE UPLOADS ----------------------------------------------------------
_def('UPLOAD_DIR', APP_ROOT . '/assets/uploads/');
_def('RESUME_DIR', APP_ROOT . '/resumes/');
_def('MAX_UPLOAD_MB', 10);

// ---- GOOGLE CALENDAR (ATS) -------------------------------------------------
_def('GOOGLE_CLIENT_ID', '');
_def('GOOGLE_CLIENT_SECRET', '');
_def('GOOGLE_REDIRECT', APP_URL . 'modules/ats/calendar-callback.php');

// ---- MAIL / SMTP -----------------------------------------------------------
_def('MAIL_FROM', 'no-reply@spotcomm.pk');
_def('MAIL_FROM_NAME', 'Spotcomm HRIS');

// ---- WHATSAPP API (Baileys at wa.spotcomm.pk) ------------------------------
// Confirmed working format: POST https://wa.spotcomm.pk/api/sendText
// Body: { "chatId": "92300xxxxxxx", "text": "message" }
// No token required for your instance.
_def('WA_API_URL', 'https://wa.spotcomm.pk');
_def('WA_API_TOKEN', '');
_def('WA_DEFAULT_COUNTRY', '92');
_def('WA_ENABLED', true);
