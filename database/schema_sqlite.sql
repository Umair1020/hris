-- ============================================================================
-- SPOTCOMM GLOBAL HRIS - SQLite SCHEMA (zero-config, EMPTY - no demo data)
-- Auto-executed on first run. No MySQL needed. DB stored as a single file.
-- Default login: admin / Spotcomm@2026
-- ============================================================================

PRAGMA foreign_keys = OFF;

-- ----------------------------------------------------------------------------
-- CORE TABLES
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT NOT NULL,
  code            TEXT UNIQUE,
  head_employee_id INTEGER,
  description     TEXT,
  created_at      TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_departments_code ON departments(code);

-- ----------------------------------------------------------------------------
-- SYSTEM SETTINGS (key-value store — WhatsApp, SMTP, etc.)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  key_name   TEXT PRIMARY KEY,
  value      TEXT
);

CREATE TABLE IF NOT EXISTS employees (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_code    TEXT UNIQUE NOT NULL,
  first_name       TEXT NOT NULL,
  last_name        TEXT NOT NULL,
  full_name        TEXT,
  email            TEXT UNIQUE,
  phone            TEXT,
  whatsapp         TEXT,
  cnic             TEXT,
  dob              TEXT,
  gender           TEXT,
  marital_status   TEXT,
  address          TEXT,
  department_id    INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  designation      TEXT,
  manager_id       INTEGER,
  employment_type  TEXT DEFAULT 'Probation',
  joining_date     TEXT,
  status           TEXT DEFAULT 'Active',
  basic_salary     REAL DEFAULT 0,
  photo            TEXT,
  emergency_name   TEXT,
  emergency_phone  TEXT,
  bank_name        TEXT,
  bank_account     TEXT,
  shift_start      TEXT DEFAULT '09:00',
  shift_end        TEXT DEFAULT '18:00',
  weekly_off       TEXT DEFAULT 'Sunday',
  created_at       TEXT DEFAULT CURRENT_TIMESTAMP,
  updated_at       TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_emp_dept ON employees(department_id);
CREATE INDEX IF NOT EXISTS idx_emp_manager ON employees(manager_id);
CREATE INDEX IF NOT EXISTS idx_emp_status ON employees(status);

CREATE TABLE IF NOT EXISTS users (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id     INTEGER REFERENCES employees(id) ON DELETE CASCADE,
  username        TEXT UNIQUE NOT NULL,
  email           TEXT UNIQUE,
  password_hash   TEXT NOT NULL,
  role            TEXT DEFAULT 'employee',
  must_change_password INTEGER DEFAULT 0,
  status          TEXT DEFAULT 'active',
  last_login      TEXT,
  failed_attempts INTEGER DEFAULT 0,
  created_at      TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_users_role ON users(role);

CREATE TABLE IF NOT EXISTS activity_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER,
  action      TEXT,
  details     TEXT,
  ip_address  TEXT,
  created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS notifications (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER,
  audience    TEXT DEFAULT 'user',
  role_target TEXT,
  title       TEXT,
  message     TEXT,
  link        TEXT,
  is_read     INTEGER DEFAULT 0,
  created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- ATTENDANCE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id        INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  attendance_date    TEXT NOT NULL,
  clock_in           TEXT,
  clock_out          TEXT,
  clock_in_location  TEXT,
  clock_out_location TEXT,
  clock_in_method    TEXT DEFAULT 'portal',
  status             TEXT DEFAULT 'present',
  work_hours         REAL DEFAULT 0,
  overtime_hours     REAL DEFAULT 0,
  undertime_hours    REAL DEFAULT 0,
  clock_in_selfie    TEXT,
  clock_out_selfie   TEXT,
  is_regularized     INTEGER DEFAULT 0,
  regularized_by     INTEGER,
  regularized_note   TEXT,
  notes              TEXT,
  created_at         TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (employee_id, attendance_date)
);

CREATE TABLE IF NOT EXISTS holidays (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  title      TEXT,
  holiday_date TEXT UNIQUE,
  is_recurring INTEGER DEFAULT 0,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- ATTENDANCE REMINDERS (tracks sent reminders to avoid duplicates)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_reminders (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id     INTEGER NOT NULL,
  reminder_type   TEXT,
  reminder_date   TEXT,
  sent_at         TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (employee_id, reminder_type, reminder_date)
);

-- ----------------------------------------------------------------------------
-- PAYROLL
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loans (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  amount        REAL NOT NULL,
  installment   REAL NOT NULL DEFAULT 0,
  total_installments INTEGER DEFAULT 1,
  paid_installments  INTEGER DEFAULT 0,
  remaining     REAL NOT NULL,
  reason        TEXT,
  status        TEXT DEFAULT 'active',
  start_date    TEXT,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bonuses (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id  INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  amount       REAL NOT NULL,
  reason       TEXT,
  bonus_type   TEXT DEFAULT 'performance',
  applied_date TEXT,
  created_at   TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payroll_runs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  month        INTEGER NOT NULL,
  year         INTEGER NOT NULL,
  status       TEXT DEFAULT 'draft',
  generated_by INTEGER,
  processed_at TEXT,
  created_at   TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (month, year)
);

CREATE TABLE IF NOT EXISTS payroll_items (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  payroll_run_id    INTEGER NOT NULL REFERENCES payroll_runs(id) ON DELETE CASCADE,
  employee_id       INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  basic_salary      REAL DEFAULT 0,
  allowances        REAL DEFAULT 0,
  overtime_pay      REAL DEFAULT 0,
  bonus             REAL DEFAULT 0,
  loan_deduction    REAL DEFAULT 0,
  tax               REAL DEFAULT 0,
  other_deductions  REAL DEFAULT 0,
  gross_pay         REAL DEFAULT 0,
  total_deductions  REAL DEFAULT 0,
  net_pay           REAL DEFAULT 0,
  present_days      REAL DEFAULT 0,
  absent_days       REAL DEFAULT 0,
  upload_note       TEXT,
  UNIQUE (payroll_run_id, employee_id)
);

-- ----------------------------------------------------------------------------
-- ATS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_postings (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  title         TEXT NOT NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  description   TEXT,
  requirements  TEXT,
  keywords      TEXT,
  location      TEXT,
  employment_type TEXT DEFAULT 'Full-Time',
  openings      INTEGER DEFAULT 1,
  status        TEXT DEFAULT 'open',
  posted_date   TEXT,
  closing_date  TEXT,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS applicants (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  job_posting_id  INTEGER NOT NULL REFERENCES job_postings(id) ON DELETE CASCADE,
  full_name       TEXT NOT NULL,
  email           TEXT,
  phone           TEXT,
  resume_path     TEXT,
  resume_text     TEXT,
  cover_letter    TEXT,
  status          TEXT DEFAULT 'new',
  match_score     INTEGER DEFAULT 0,
  keywords_matched TEXT,
  source          TEXT DEFAULT 'Portal',
  applied_date    TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS interviews (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  applicant_id  INTEGER NOT NULL REFERENCES applicants(id) ON DELETE CASCADE,
  interview_date TEXT,
  round         TEXT DEFAULT 'First Round',
  location      TEXT,
  meeting_link  TEXT,
  interviewer   TEXT,
  status        TEXT DEFAULT 'scheduled',
  feedback      TEXT,
  rating        INTEGER,
  calendar_event_id TEXT,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- PERFORMANCE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kpi_templates (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  department_id INTEGER REFERENCES departments(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  description   TEXT,
  weight        REAL DEFAULT 10.0,
  max_score     INTEGER DEFAULT 100,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS employee_kpis (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id     INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  kpi_template_id INTEGER NOT NULL REFERENCES kpi_templates(id) ON DELETE CASCADE,
  target          TEXT,
  period          TEXT,
  assigned_by     INTEGER,
  created_at      TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS performance_reviews (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  reviewer_id   INTEGER,
  review_period TEXT,
  kpi_scores    TEXT,
  overall_score REAL DEFAULT 0,
  rating        TEXT DEFAULT 'meets',
  achievements  TEXT,
  areas_to_improve TEXT,
  reviewer_comments TEXT,
  employee_comments TEXT,
  status        TEXT DEFAULT 'draft',
  review_date   TEXT,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- LEAVE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_types (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT NOT NULL,
  code            TEXT UNIQUE,
  default_balance REAL DEFAULT 0,
  is_paid         INTEGER DEFAULT 1,
  min_lead_days   INTEGER DEFAULT 0,
  color           TEXT DEFAULT '#7F3E98'
);

CREATE TABLE IF NOT EXISTS leave_balances (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  leave_type_id INTEGER NOT NULL REFERENCES leave_types(id) ON DELETE CASCADE,
  year          INTEGER,
  allocated     REAL DEFAULT 0,
  used          REAL DEFAULT 0,
  UNIQUE (employee_id, leave_type_id, year)
);

CREATE TABLE IF NOT EXISTS leave_requests (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id      INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  leave_type_id    INTEGER NOT NULL REFERENCES leave_types(id) ON DELETE CASCADE,
  start_date       TEXT NOT NULL,
  end_date         TEXT NOT NULL,
  days             REAL NOT NULL,
  half_day         INTEGER DEFAULT 0,
  reason           TEXT,
  is_emergency     INTEGER DEFAULT 0,
  manager_status   TEXT DEFAULT 'pending',
  manager_id       INTEGER,
  manager_action_at TEXT,
  hr_status        TEXT DEFAULT 'pending',
  hr_action_by     INTEGER,
  hr_action_at     TEXT,
  status           TEXT DEFAULT 'pending',
  attachment_path  TEXT,
  requested_at     TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- LETTERS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS letter_templates (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  code        TEXT UNIQUE,
  category    TEXT DEFAULT 'general',
  type        TEXT DEFAULT 'letter',
  subject     TEXT,
  body        TEXT,
  header_html TEXT,
  footer_html TEXT,
  variables   TEXT,
  is_active   INTEGER DEFAULT 1,
  created_at  TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS generated_letters (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id   INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  template_id   INTEGER NOT NULL REFERENCES letter_templates(id) ON DELETE CASCADE,
  subject       TEXT,
  body          TEXT,
  reference_no  TEXT,
  status        TEXT DEFAULT 'requested',
  requested_by  INTEGER,
  approved_by   INTEGER,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- SEPARATION
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS separation_requests (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id      INTEGER NOT NULL REFERENCES employees(id) ON DELETE CASCADE,
  separation_type  TEXT DEFAULT 'resignation',
  reason           TEXT,
  notice_date      TEXT,
  last_working_day TEXT,
  handover_notes   TEXT,
  handover_files   TEXT,
  status           TEXT DEFAULT 'submitted',
  exit_interview   TEXT,
  created_at       TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS clearance_items (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  separation_id    INTEGER NOT NULL REFERENCES separation_requests(id) ON DELETE CASCADE,
  item_name        TEXT,
  department       TEXT,
  responsible_user TEXT,
  status           TEXT DEFAULT 'pending',
  remarks          TEXT,
  cleared_at       TEXT
);

-- ----------------------------------------------------------------------------
-- POLICY
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_policies (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  title         TEXT NOT NULL,
  category      TEXT DEFAULT 'General',
  content       TEXT,
  version       TEXT DEFAULT '1.0',
  effective_date TEXT,
  status        TEXT DEFAULT 'active',
  created_by    INTEGER,
  created_at    TEXT DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- SEED DATA (minimal — only system essentials, NO demo employees)
-- ============================================================================

-- Default system settings (WhatsApp + Email — editable from Settings page)
INSERT INTO settings (key_name, value) VALUES
('wa_api_url', 'https://wa.spotcomm.pk'),
('wa_session', 'default'),
('wa_token', ''),
('wa_enabled', '1'),
('wa_country', '92'),
('mail_from', 'no-reply@spotcomm.pk'),
('mail_from_name', 'Spotcomm HRIS'),
('smtp_host', ''),
('smtp_port', '465'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_enabled', '0'),
('att_selfie_required', '1'),
('att_geofence_enabled', '0'),
('att_office_lat', ''),
('att_office_lng', ''),
('att_geofence_radius', '200'),
('att_office_ips', ''),
('reminder_checkin_enabled', '1'),
('reminder_checkin_time', '10:30'),
('reminder_checkout_enabled', '1'),
('reminder_checkout_time', '19:00'),
('reminder_missing_checkout_enabled', '1'),
('reminder_missing_checkout_time', '20:00'),
-- ATTENDANCE POLICY SETTINGS (per HR Director requirements)
('late_grace_minutes', '15'),
('late_threshold_count', '3'),
('late_deduction_type', 'half_day'),
('required_work_hours', '9'),
('ot_to_compleave_hours', '24'),
('ot_against_late', '1'),
('regularize_monthly_limit', '1'),
('saturday_off', '0');

-- Leave types (required for system to function)
INSERT INTO leave_types (name, code, default_balance, is_paid, min_lead_days, color) VALUES
('Casual Leave','CL',10,1,7,'#7F3E98'),
('Sick / Medical Leave','ML',8,1,1,'#9B59B6'),
('Annual / Earned Leave','AL',14,1,7,'#2A1B3D'),
('Emergency Leave','EMG',3,1,0,'#F26223'),
('Unpaid Leave','LWP',0,0,1,'#6c757d');

-- Letter templates (required for HR letters module)
INSERT INTO letter_templates (name, code, category, subject, variables, body) VALUES
('Offer Letter','OFFER','employment','Offer of Employment - Spotcomm Global','candidate_name,designation,department,joining_date,salary',
'<p align="center"><strong>SPOTCOMM GLOBAL</strong></p><p>Date: {{date}}</p><p>Dear {{candidate_name}},</p><p>We are pleased to offer you the position of <strong>{{designation}}</strong> in the <strong>{{department}}</strong> department at Spotcomm Global. Your joining date will be <strong>{{joining_date}}</strong> with a starting salary of <strong>{{salary}}</strong> per month.</p><p>We look forward to welcoming you to our team.</p><p>Sincerely,<br>Human Resources<br>Spotcomm Global</p>'),
('Confirmation Letter','CONFIRM','employment','Confirmation of Employment','employee_name,designation,confirmation_date',
'<p align="center"><strong>SPOTCOMM GLOBAL</strong></p><p>Date: {{date}}</p><p>Dear {{employee_name}},</p><p>This is to confirm your employment as <strong>{{designation}}</strong> with Spotcomm Global effective <strong>{{confirmation_date}}</strong>.</p><p>Sincerely,<br>Human Resources</p>'),
('Experience Certificate','EXPERIENCE','certificates','Experience Certificate','employee_name,designation,start_date,end_date',
'<p align="center"><strong>SPOTCOMM GLOBAL</strong></p><p align="center"><strong>EXPERIENCE CERTIFICATE</strong></p><p>This is to certify that <strong>{{employee_name}}</strong> was employed with Spotcomm Global as <strong>{{designation}}</strong> from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p><p>During this period, we found {{employee_name}} to be sincere, hardworking and dedicated.</p><p>We wish {{employee_name}} all the best in future endeavors.</p><p>For Spotcomm Global<br>Human Resources</p>'),
('Salary Certificate','SALARY','certificates','Salary Certificate','employee_name,designation,salary',
'<p align="center"><strong>SPOTCOMM GLOBAL</strong></p><p align="center"><strong>SALARY CERTIFICATE</strong></p><p>This is to certify that <strong>{{employee_name}}</strong> is employed with Spotcomm Global as <strong>{{designation}}</strong> drawing a gross monthly salary of <strong>{{salary}}</strong>.</p><p>This certificate is issued upon request.</p><p>For Spotcomm Global<br>Human Resources</p>'),
('NOC Letter','NOC','general','No Objection Certificate','employee_name,purpose',
'<p align="center"><strong>SPOTCOMM GLOBAL</strong></p><p align="center"><strong>NO OBJECTION CERTIFICATE</strong></p><p>This is to certify that Spotcomm Global has no objection for <strong>{{employee_name}}</strong> regarding {{purpose}}.</p><p>For Spotcomm Global<br>Human Resources</p>'),
('Warning Letter','WARN','disciplinary','Warning Letter','employee_name',
'<p>Dear {{employee_name}},</p><p>This letter serves as a formal warning regarding your recent conduct/performance. Management has observed concerns that need to be addressed immediately.</p><p>We expect immediate improvement. Further violations may result in stricter disciplinary action.</p><p>HR Department<br>Spotcomm Global</p>'),
('Appreciation Letter','APPREC','recognition','Letter of Appreciation','employee_name,designation',
'<p>Dear {{employee_name}},</p><p>We are pleased to recognize your outstanding performance and dedication as {{designation}}. Your hard work and commitment to excellence are truly appreciated.</p><p>Thank you for being a valued member of the Spotcomm Global team.</p><p>HR Department<br>Spotcomm Global</p>'),
('Employee Certificate','EMPCERT','certificate','Employee Certificate','employee_name,designation,joining_date',
'<p style="text-align:center;font-size:18px"><strong>CERTIFICATE OF EMPLOYMENT</strong></p><p>This is to certify that <strong>{{employee_name}}</strong> is a confirmed employee of Spotcomm Global, currently serving as <strong>{{designation}}</strong> since <strong>{{joining_date}}</strong>.</p><p>This certificate is issued upon request for official purposes.</p><p>Spotcomm Global<br>HR Department</p>'),
('Termination Letter','TERM','disciplinary','Termination Letter','employee_name',
'<p>Dear {{employee_name}},</p><p>We regret to inform you that your employment with Spotcomm Global is being terminated effective immediately, due to reasons discussed with management.</p><p>Please contact HR for your final settlement and clearance process.</p><p>HR Department<br>Spotcomm Global</p>');

-- KPI templates (default, reusable)
INSERT INTO kpi_templates (department_id, name, description, weight, max_score) VALUES
(NULL,'Task Completion Rate','Percentage of assigned tasks completed on time',25.0,100),
(NULL,'Quality of Work','Quality and accuracy of deliverables',25.0,100),
(NULL,'Attendance & Punctuality','Regular attendance and timeliness',15.0,100),
(NULL,'Teamwork & Collaboration','Cooperation with colleagues',15.0,100),
(NULL,'Initiative & Innovation','Proactive problem solving',20.0,100);

-- Default company policies
INSERT INTO company_policies (title, category, content, version, effective_date) VALUES
('Working Hours & Attendance','Attendance','Official working hours are 9:00 AM to 6:00 PM, Monday to Saturday. Employees must mark attendance daily through the HRIS portal or by scanning their ID card. Location confirmation is required for portal clock-in. Late arrivals beyond 15 minutes are marked late.','1.0',date('now')),
('Leave Policy','Leave','Casual leaves require a minimum of 7 days advance notice and are approved by the immediate manager and HR. Medical leaves require 1 day advance notice. Emergency leaves for medical emergencies are handled by HR immediately. Unused casual leaves lapse at year end.','1.0',date('now')),
('Code of Conduct','General','All employees are expected to maintain professional conduct, respect colleagues, protect company confidential information, and follow Spotcomm Global values: Outsource, Optimize, Thrive.','1.0',date('now')),
('Probation & Confirmation','Employment','New employees serve a 3-month probation period. Confirmation is subject to satisfactory performance review by the reporting manager and HR.','1.0',date('now')),
('Payroll & Salary','Payroll','Salaries are processed monthly and disbursed by the 5th of the following month. Deductions include loans, taxes, and unpaid leave as applicable.','1.0',date('now'));

-- ============================================================================
-- DEFAULT ADMIN USER (login: admin / Spotcomm@2026)
-- ============================================================================
INSERT INTO employees (id, employee_code, first_name, last_name, full_name, email, department_id, designation, employment_type, joining_date, status, basic_salary, shift_start, shift_end)
VALUES (1, 'SCG-001', 'Admin', 'User', 'Admin User', 'admin@spotcomm.pk', NULL, 'System Administrator', 'Permanent', date('now'), 'Active', 0, '09:00:00', '18:00:00');

INSERT INTO users (id, employee_id, username, email, password_hash, role, status, must_change_password)
VALUES (1, 1, 'admin', 'admin@spotcomm.pk', '$2y$10$c2OXwKydgoOH4fkeosK3Ju1BXDH7MrEF9kRJW.HtyEAodPGMnh/tO', 'admin', 'active', 1);

PRAGMA foreign_keys = ON;
