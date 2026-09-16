<?php
/**
 * ============================================================================
 * SPOTCOMM GLOBAL HRIS - DATABASE CONNECTION (ZERO-CONFIG)
 * ============================================================================
 * Uses SQLite by default — the database is a single FILE.
 * No MySQL setup required. On first run, the schema + seed data are
 * created automatically. Truly "unzip and go".
 *
 * To use MySQL instead, set DB_TYPE='mysql' + credentials in config/.env.php
 * ============================================================================
 */
require_once __DIR__ . '/config.php';

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';

        if ($type === 'mysql') {
            $this->connectMySQL();
        } else {
            $this->connectSQLite();
        }
    }

    /** SQLite: single-file DB, auto-created on first run */
    private function connectSQLite()
    {
        $dbPath = defined('DB_PATH') ? DB_PATH : (dirname(__DIR__) . '/database/spotcomm.db');
        $schemaFile = dirname(__DIR__) . '/database/schema_sqlite.sql';

        // Ensure the directory exists & is writable
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $isNew = !file_exists($dbPath);

        try {
            $this->pdo = new PDO('sqlite:' . $dbPath);
        } catch (PDOException $e) {
            die('Cannot create/open the database file. Please make the <code>database/</code> folder writable (chmod 755 or 777). Error: ' . htmlspecialchars($e->getMessage()));
        }

        // SQLite performance + integrity settings
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');

        // SELF-HEAL: create schema if this is a new DB OR any CORE table is
        // missing (e.g. a partially-written/corrupt DB). Safe — uses
        // CREATE TABLE IF NOT EXISTS and re-seeds only if rows are absent,
        // so EXISTING data is never deleted; only missing tables get created.
        if ($isNew || !$this->tableExists('users') || !$this->tableExists('employees') || !$this->tableExists('settings')) {
            $this->initSchema($schemaFile);
        }

        // Auto-migrate: add missing columns/tables for upgrades from older versions
        $this->autoMigrate();
    }

    /**
     * Auto-migrate — silently adds missing columns & tables so old databases
     * upgrade automatically without needing to delete spotcomm.db.
     */
    private function autoMigrate()
    {
        $pdo = $this->pdo;

        // 1. Ensure 'settings' table exists
        if (!$this->tableExists('settings')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key_name TEXT PRIMARY KEY, value TEXT)");
        }

        // 2. Seed default settings if missing
        $defaults = [
            'wa_api_url' => 'https://wa.spotcomm.pk',
            'wa_session' => 'default',
            'wa_token' => '',
            'wa_enabled' => '1',
            'wa_country' => '92',
            'wa_broadcast_group' => '',
            'mail_from' => 'no-reply@spotcomm.pk',
            'mail_from_name' => 'Spotcomm HRIS',
            'smtp_host' => '',
            'smtp_port' => '465',
            'smtp_user' => '',
            'smtp_pass' => '',
            'smtp_enabled' => '0',
            'att_selfie_required' => '1',
            'att_geofence_enabled' => '0',
            'reminder_checkin_enabled' => '1',
            'reminder_checkin_time' => '10:30',
            'reminder_checkout_enabled' => '1',
            'reminder_checkout_time' => '19:00',
            'late_grace_minutes' => '15',
            'late_threshold_count' => '3',
            'late_deduction_type' => 'half_day',
            'required_work_hours' => '9',
            'ot_to_compleave_hours' => '24',
            'ot_against_late' => '1',
            'regularize_monthly_limit' => '1',
            'saturday_off' => '0',
            'auto_absent_enabled' => '1',
            'absent_sync_days' => '45',
            'undertime_grace_minutes' => '10',
            'letter_header' => '<div style="text-align:center;margin-bottom:20px"><img src="' . (defined('APP_URL') ? APP_URL : '') . 'assets/img/logo.png" style="height:50px"><br><strong style="font-size:14px">SPOTCOMM GLOBAL</strong><br><span style="font-size:10px;color:#666">Outsource · Optimize · Thrive</span></div>',
            'letter_footer' => '<div style="text-align:center;margin-top:30px;border-top:1px solid #ddd;padding-top:10px;font-size:10px;color:#666"><strong>Spotcomm Global HR Department</strong><br>Phone: +971 557015596 · Email: sales@spotcommglobal.com · Web: www.spotcommglobal.com</div>',
        ];
        foreach ($defaults as $k => $v) {
            $ex = $pdo->query("SELECT key_name FROM settings WHERE key_name=" . $pdo->quote($k))->fetch();
            if (!$ex) {
                $pdo->exec("INSERT INTO settings (key_name, value) VALUES (" . $pdo->quote($k) . ", " . $pdo->quote($v) . ")");
            }
        }

        // 3. Add missing columns to existing tables (ALTER TABLE ADD COLUMN is safe in SQLite)
        $columnChecks = [
            'employees' => ['whatsapp' => "TEXT", 'weekly_off' => "TEXT DEFAULT 'Sunday'"],
            'attendance' => [
                'clock_in_selfie' => "TEXT",
                'clock_out_selfie' => "TEXT",
                'is_regularized' => "INTEGER DEFAULT 0",
                'regularized_by' => "INTEGER",
                'regularized_note' => "TEXT",
                'undertime_hours' => "REAL DEFAULT 0",
                'late_minutes' => "INTEGER DEFAULT 0",
                'notes' => "TEXT",
            ],
            'leave_requests' => [
                'half_day_session' => "TEXT",
            ],
            'grievances' => [
                'assigned_to' => "INTEGER",
                'resolution_note' => "TEXT",
                'updated_at' => "TEXT",
            ],
            'holidays' => [
                'is_recurring' => "INTEGER DEFAULT 0",
            ],
            'letter_templates' => [
                'type' => "TEXT DEFAULT 'letter'",
                'header_html' => "TEXT",
                'footer_html' => "TEXT",
            ],
        ];
        foreach ($columnChecks as $table => $cols) {
            if (!$this->tableExists($table)) continue;
            $existingCols = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
            foreach ($cols as $col => $type) {
                if (!in_array($col, $existingCols)) {
                    try { $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type"); } catch (Exception $e) {}
                }
            }
        }

        // 3b. Ensure holidays table exists (Public Holidays)
        if (!$this->tableExists('holidays')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS holidays (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                holiday_date TEXT UNIQUE,
                is_recurring INTEGER DEFAULT 0,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        }
        // 3c. Ensure grievances table exists (HR complaint desk)
        if (!$this->tableExists('grievances')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grievances (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER,
                category TEXT,
                subject TEXT,
                description TEXT,
                priority TEXT DEFAULT 'normal',
                status TEXT DEFAULT 'open',
                is_anonymous INTEGER DEFAULT 0,
                admin_response TEXT,
                responded_by INTEGER,
                responded_at TEXT,
                assigned_to INTEGER,
                resolution_note TEXT,
                updated_at TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        }

        // 4. Ensure attendance_reminders table exists
        if (!$this->tableExists('attendance_reminders')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_reminders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                reminder_type TEXT,
                reminder_date TEXT,
                sent_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (employee_id, reminder_type, reminder_date)
            )");
        }

        // 5. Ensure employee_benefits table exists
        if (!$this->tableExists('employee_benefits')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS employee_benefits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER NOT NULL,
                benefit_type TEXT NOT NULL,
                amount REAL DEFAULT 0,
                description TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        }

        // 6. Ensure whatsapp_queue table exists
        if (!$this->tableExists('whatsapp_queue')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_queue (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id INTEGER,
                employee_name TEXT,
                whatsapp_number TEXT,
                message TEXT,
                subject TEXT,
                channel TEXT DEFAULT 'whatsapp',
                email TEXT,
                status TEXT DEFAULT 'pending',
                send_after TEXT,
                sent_at TEXT,
                error TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        }
    }

    /** MySQL connection (optional — for those who prefer MySQL) */
    private function connectMySQL()
    {
        $socket = defined('DB_SOCKET') && DB_SOCKET ? DB_SOCKET : null;
        $dsn = $socket
            ? 'mysql:unix_socket=' . $socket . ';dbname=' . DB_NAME . ';charset=utf8mb4'
            : 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => DEBUG_MODE ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (DEBUG_MODE) die('MySQL connection failed: ' . htmlspecialchars($e->getMessage()));
            die('Cannot connect to the database. Please check your configuration.');
        }
    }

    /** Run a schema .sql file (splits on semicolons at line ends) */
    private function initSchema($file)
    {
        if (!is_file($file)) return;
        $sql = file_get_contents($file);
        $sql = preg_replace('/--.*$/m', '', $sql);     // strip comments
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || $stmt === ';') continue;
            try { $this->pdo->exec($stmt); }
            catch (PDOException $e) {
                // ignore "already exists" so re-runs are safe
                if (DEBUG_MODE && strpos($e->getMessage(), 'exists') === false) {
                    error_log('Schema: ' . $e->getMessage());
                }
            }
        }
    }

    private function tableExists($name)
    {
        $r = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $this->pdo->quote($name))->fetch();
        return (bool)$r;
    }

    public static function db()
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance->pdo;
    }
}

/** Shorthand: returns the shared PDO instance */
function db()
{
    return Database::db();
}
