-- ============================================================================
-- SPOTCOMM GLOBAL HRIS - DATABASE SCHEMA
-- Target: MySQL 5.7+ / MariaDB 10.3+
-- Charset: utf8mb4
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- 1. CORE: USERS, DEPARTMENTS, EMPLOYEES
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(120) NOT NULL,
  code            VARCHAR(30) UNIQUE,
  head_employee_id INT NULL,
  description     TEXT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  employee_code    VARCHAR(30) UNIQUE NOT NULL,
  first_name       VARCHAR(80) NOT NULL,
  last_name        VARCHAR(80) NOT NULL,
  full_name        VARCHAR(180) GENERATED ALWAYS AS (CONCAT(first_name,' ',last_name)) STORED,
  email            VARCHAR(150) UNIQUE,
  phone            VARCHAR(30),
  cnic             VARCHAR(20),
  dob              DATE NULL,
  gender           ENUM('Male','Female','Other') NULL,
  marital_status   ENUM('Single','Married','Divorced','Widowed') NULL,
  address          TEXT NULL,
  department_id    INT NULL,
  designation      VARCHAR(120) NULL,
  manager_id       INT NULL,
  employment_type  ENUM('Permanent','Contract','Probation','Intern') DEFAULT 'Probation',
  joining_date     DATE NULL,
  status           ENUM('Active','Resigned','Terminated','Suspended') DEFAULT 'Active',
  basic_salary     DECIMAL(12,2) DEFAULT 0,
  photo            VARCHAR(255) NULL,
  emergency_name   VARCHAR(120) NULL,
  emergency_phone  VARCHAR(30) NULL,
  bank_name        VARCHAR(120) NULL,
  bank_account     VARCHAR(40) NULL,
  shift_start      TIME DEFAULT '09:00:00',
  shift_end        TIME DEFAULT '18:00:00',
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  INDEX (department_id), INDEX (manager_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NULL,
  username        VARCHAR(60) UNIQUE NOT NULL,
  email           VARCHAR(150) UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  role            ENUM('employee','manager','hr','admin') DEFAULT 'employee',
  must_change_password TINYINT(1) DEFAULT 0,
  status          ENUM('active','inactive','locked') DEFAULT 'active',
  last_login      DATETIME NULL,
  failed_attempts INT DEFAULT 0,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  INDEX (role), INDEX (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS activity_log (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,
  action      VARCHAR(150),
  details     TEXT NULL,
  ip_address  VARCHAR(45),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,
  audience    ENUM('user','role','all') DEFAULT 'user',
  role_target VARCHAR(30) NULL,
  title       VARCHAR(200),
  message     TEXT,
  link        VARCHAR(255) NULL,
  is_read     TINYINT(1) DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 2. TIME & ATTENDANCE
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
  id                 BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id        INT NOT NULL,
  attendance_date    DATE NOT NULL,
  clock_in           DATETIME NULL,
  clock_out          DATETIME NULL,
  clock_in_location  VARCHAR(255) NULL,
  clock_out_location VARCHAR(255) NULL,
  clock_in_method    VARCHAR(20) DEFAULT 'portal',
  status             VARCHAR(30) DEFAULT 'present',
  work_hours         DECIMAL(5,2) DEFAULT 0,
  overtime_hours     DECIMAL(5,2) DEFAULT 0,
  undertime_hours    DECIMAL(5,2) DEFAULT 0,
  late_minutes       INT DEFAULT 0,
  notes              VARCHAR(255) NULL,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_emp_date (employee_id, attendance_date),
  INDEX (attendance_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS holidays (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(150),
  holiday_date DATE,
  is_recurring TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_holiday (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 3. PAYROLL
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loans (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  installment   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_installments INT DEFAULT 1,
  paid_installments  INT DEFAULT 0,
  remaining     DECIMAL(12,2) NOT NULL,
  reason        VARCHAR(255) NULL,
  status        ENUM('active','closed') DEFAULT 'active',
  start_date    DATE,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  INDEX (employee_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bonuses (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT NOT NULL,
  amount       DECIMAL(12,2) NOT NULL,
  reason       VARCHAR(255),
  bonus_type   ENUM('performance','eid','annual','spot','other') DEFAULT 'performance',
  applied_date DATE,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_runs (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  month        TINYINT NOT NULL,
  year         SMALLINT NOT NULL,
  status       ENUM('draft','processed','paid') DEFAULT 'draft',
  generated_by INT NULL,
  processed_at DATETIME NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_run (month, year),
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payroll_items (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  payroll_run_id    INT NOT NULL,
  employee_id       INT NOT NULL,
  basic_salary      DECIMAL(12,2) DEFAULT 0,
  allowances        DECIMAL(12,2) DEFAULT 0,
  overtime_pay      DECIMAL(12,2) DEFAULT 0,
  bonus             DECIMAL(12,2) DEFAULT 0,
  loan_deduction    DECIMAL(12,2) DEFAULT 0,
  tax               DECIMAL(12,2) DEFAULT 0,
  other_deductions  DECIMAL(12,2) DEFAULT 0,
  gross_pay         DECIMAL(12,2) DEFAULT 0,
  total_deductions  DECIMAL(12,2) DEFAULT 0,
  net_pay           DECIMAL(12,2) DEFAULT 0,
  present_days      DECIMAL(5,2) DEFAULT 0,
  absent_days       DECIMAL(5,2) DEFAULT 0,
  upload_note       VARCHAR(255) NULL,
  FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_item (payroll_run_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 4. APPLICANT TRACKING SYSTEM (ATS)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_postings (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(180) NOT NULL,
  department_id INT NULL,
  description   TEXT,
  requirements  TEXT,
  keywords      TEXT,
  location      VARCHAR(120) NULL,
  employment_type VARCHAR(60) DEFAULT 'Full-Time',
  openings      INT DEFAULT 1,
  status        ENUM('open','closed','on_hold') DEFAULT 'open',
  posted_date   DATE,
  closing_date  DATE NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS applicants (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  job_posting_id  INT NOT NULL,
  full_name       VARCHAR(150) NOT NULL,
  email           VARCHAR(150),
  phone           VARCHAR(30),
  resume_path     VARCHAR(255),
  resume_text     LONGTEXT NULL,
  cover_letter    TEXT NULL,
  status          ENUM('new','shortlisted','interview_scheduled','interviewed','offered','hired','rejected') DEFAULT 'new',
  match_score     INT DEFAULT 0,
  keywords_matched TEXT NULL,
  source          VARCHAR(80) DEFAULT 'Portal',
  applied_date    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (job_posting_id) REFERENCES job_postings(id) ON DELETE CASCADE,
  INDEX (status), INDEX (match_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS interviews (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  applicant_id  INT NOT NULL,
  interview_date DATETIME,
  round         VARCHAR(80) DEFAULT 'First Round',
  location      VARCHAR(255),
  meeting_link  VARCHAR(255) NULL,
  interviewer   VARCHAR(150),
  status        ENUM('scheduled','completed','cancelled','rescheduled','no_show') DEFAULT 'scheduled',
  feedback      TEXT NULL,
  rating        TINYINT NULL,
  calendar_event_id VARCHAR(255) NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (applicant_id) REFERENCES applicants(id) ON DELETE CASCADE,
  INDEX (status), INDEX (interview_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 5. PERFORMANCE MANAGEMENT
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kpi_templates (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  department_id INT NULL,
  name          VARCHAR(180) NOT NULL,
  description   TEXT,
  weight        DECIMAL(5,2) DEFAULT 10.00,
  max_score     INT DEFAULT 100,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  INDEX (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_kpis (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  employee_id     INT NOT NULL,
  kpi_template_id INT NOT NULL,
  target          VARCHAR(255),
  period          VARCHAR(20),  -- e.g. 2026-Q3 or 2026-H1
  assigned_by     INT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (kpi_template_id) REFERENCES kpi_templates(id) ON DELETE CASCADE,
  INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS performance_reviews (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  reviewer_id   INT NULL,
  review_period VARCHAR(30),
  kpi_scores    TEXT NULL,   -- JSON: [{kpi, score, max, weight}]
  overall_score DECIMAL(5,2) DEFAULT 0,
  rating        ENUM('unsatisfactory','needs_improvement','meets','exceeds','outstanding') DEFAULT 'meets',
  achievements  TEXT NULL,
  areas_to_improve TEXT NULL,
  reviewer_comments TEXT NULL,
  employee_comments TEXT NULL,
  status        ENUM('draft','submitted','acknowledged','closed') DEFAULT 'draft',
  review_date   DATE NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  INDEX (employee_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 6. LEAVE MANAGEMENT
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leave_types (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80) NOT NULL,
  code            VARCHAR(20) UNIQUE,
  default_balance DECIMAL(5,2) DEFAULT 0,
  is_paid         TINYINT(1) DEFAULT 1,
  min_lead_days   INT DEFAULT 0,   -- minimum advance notice in days
  color           VARCHAR(20) DEFAULT '#7d3ef2'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_balances (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  leave_type_id INT NOT NULL,
  year          SMALLINT,
  allocated     DECIMAL(5,2) DEFAULT 0,
  used          DECIMAL(5,2) DEFAULT 0,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_balance (employee_id, leave_type_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leave_requests (
  id               BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT NOT NULL,
  leave_type_id    INT NOT NULL,
  start_date       DATE NOT NULL,
  end_date         DATE NOT NULL,
  days             DECIMAL(5,2) NOT NULL,
  half_day         TINYINT(1) DEFAULT 0,
  half_day_session VARCHAR(20) NULL,
  reason           TEXT,
  is_emergency     TINYINT(1) DEFAULT 0,
  manager_status   ENUM('pending','approved','rejected') DEFAULT 'pending',
  manager_id       INT NULL,
  manager_action_at DATETIME NULL,
  hr_status        ENUM('pending','approved','rejected') DEFAULT 'pending',
  hr_action_by     INT NULL,
  hr_action_at     DATETIME NULL,
  status           ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
  attachment_path  VARCHAR(255) NULL,
  requested_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE,
  INDEX (employee_id), INDEX (status), INDEX (manager_status), INDEX (hr_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 7. HR LETTERS MANAGEMENT
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS letter_templates (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  code        VARCHAR(40) UNIQUE,
  category    VARCHAR(60) DEFAULT 'general',
  subject     VARCHAR(255),
  body        MEDIUMTEXT,
  variables   VARCHAR(255),  -- comma separated e.g. employee_name,joining_date,salary
  is_active   TINYINT(1) DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS generated_letters (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  template_id   INT NOT NULL,
  subject       VARCHAR(255),
  body          MEDIUMTEXT,
  reference_no  VARCHAR(60),
  status        ENUM('requested','approved','generated','rejected') DEFAULT 'requested',
  requested_by  INT NULL,
  approved_by   INT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  FOREIGN KEY (template_id) REFERENCES letter_templates(id) ON DELETE CASCADE,
  INDEX (employee_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 8. SEPARATION MANAGEMENT
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS separation_requests (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  employee_id      INT NOT NULL,
  separation_type  ENUM('resignation','termination','end_of_contract') DEFAULT 'resignation',
  reason           TEXT,
  notice_date      DATE,
  last_working_day DATE,
  handover_notes   TEXT,
  handover_files   VARCHAR(500) NULL,
  status           ENUM('submitted','acknowledged','clearance_in_progress','cleared','completed','withdrawn','rejected') DEFAULT 'submitted',
  exit_interview   TEXT NULL,
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
  INDEX (employee_id), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS clearance_items (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  separation_id    INT NOT NULL,
  item_name        VARCHAR(180),
  department       VARCHAR(120),
  responsible_user VARCHAR(150),
  status           ENUM('pending','cleared','hold') DEFAULT 'pending',
  remarks          VARCHAR(255),
  cleared_at       DATETIME NULL,
  FOREIGN KEY (separation_id) REFERENCES separation_requests(id) ON DELETE CASCADE,
  INDEX (separation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- 9. COMPANY POLICY
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_policies (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  category      VARCHAR(80) DEFAULT 'General',
  content       LONGTEXT,
  version       VARCHAR(20) DEFAULT '1.0',
  effective_date DATE,
  status        ENUM('active','archived') DEFAULT 'active',
  created_by    INT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (category), INDEX (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- SEED DATA
-- ============================================================================

-- Departments
INSERT INTO departments (id, name, code, description) VALUES
(1,'Human Resources','HR','Human Resources & Administration'),
(2,'Information Technology','IT','IT, Network & Infrastructure'),
(3,'Finance & Accounts','FIN','Finance, Accounts & Payroll'),
(4,'Sales & Marketing','SAL','Business Development & Marketing'),
(5,'Operations','OPS','Managed Services & Operations'),
(6,'Management','MGMT','Senior Management');

-- Leave Types
INSERT INTO leave_types (id, name, code, default_balance, is_paid, min_lead_days, color) VALUES
(1,'Casual Leave','CL',10,1,7,'#2e3192'),
(2,'Sick / Medical Leave','ML',8,1,1,'#7d3ef2'),
(3,'Annual / Earned Leave','AL',14,1,7,'#1e1e2d'),
(4,'Emergency Leave','EMG',3,1,0,'#f26223'),
(5,'Unpaid Leave','LWP',0,0,1,'#6c757d');

-- Letter Templates
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
'<p align="center"><strong>SPOTCOMM GLOBAL</strong></p><p align="center"><strong>NO OBJECTION CERTIFICATE</strong></p><p>This is to certify that Spotcomm Global has no objection for <strong>{{employee_name}}</strong> regarding {{purpose}}.</p><p>For Spotcomm Global<br>Human Resources</p>');

-- KPI Templates
INSERT INTO kpi_templates (department_id, name, description, weight, max_score) VALUES
(NULL,'Task Completion Rate','Percentage of assigned tasks completed on time',25.00,100),
(NULL,'Quality of Work','Quality and accuracy of deliverables',25.00,100),
(NULL,'Attendance & Punctuality','Regular attendance and timeliness',15.00,100),
(NULL,'Teamwork & Collaboration','Cooperation with colleagues',15.00,100),
(NULL,'Initiative & Innovation','Proactive problem solving',20.00,100);

-- Policies
INSERT INTO company_policies (title, category, content) VALUES
('Working Hours & Attendance','Attendance','Official working hours are 9:00 AM to 6:00 PM, Monday to Saturday. Employees must mark attendance daily through the HRIS portal or by scanning their ID card. Location confirmation is required for portal clock-in. Late arrivals beyond 15 minutes are marked late.'),
('Leave Policy','Leave','Casual leaves require a minimum of 7 days advance notice and are approved by the immediate manager and HR. Medical leaves require 1 day advance notice. Emergency leaves for medical emergencies are handled by HR immediately. Unused casual leaves lapse at year end.'),
('Code of Conduct','General','All employees are expected to maintain professional conduct, respect colleagues, protect company confidential information, and follow Spotcomm Global values: Outsource, Optimize, Thrive.'),
('Probation & Confirmation','Employment','New employees serve a 3-month probation period. Confirmation is subject to satisfactory performance review by the reporting manager and HR.'),
('Payroll & Salary','Payroll','Salaries are processed monthly and disbursed by the 5th of the following month. Deductions include loans, taxes, and unpaid leave as applicable.');

-- ============================================================================
-- DEFAULT USERS (passwords are hashed)
-- Default password for all seeded users: Spotcomm@2026
-- Hash generated with PHP password_hash(PASSWORD_BCRYPT)
-- ============================================================================
INSERT INTO employees (id, employee_code, first_name, last_name, email, phone, department_id, designation, manager_id, employment_type, joining_date, status, basic_salary, shift_start, shift_end)
VALUES
(1,'SCG-001','System','Administrator','admin@spotcommglobal.com','+92000000000',6,'HRIS Administrator',NULL,'Permanent','2024-01-01','Active',500000,'09:00:00','18:00:00'),
(2,'SCG-002','HR','Manager','hr@spotcommglobal.com','+92000000001',1,'HR Manager',NULL,'Permanent','2024-01-01','Active',350000,'09:00:00','18:00:00'),
(3,'SCG-003','Team','Lead','lead@spotcommglobal.com','+92000000002',2,'IT Team Lead',2,'Permanent','2024-02-01','Active',300000,'09:00:00','18:00:00'),
(4,'SCG-004','John','Employee','john@spotcommglobal.com','+92000000003',2,'Network Engineer',3,'Permanent','2024-03-01','Active',180000,'09:00:00','18:00:00');

-- IMPORTANT: run the password-hash setup after import, OR use the installer.
-- Pre-generated bcrypt hash for "Spotcomm@2026":
INSERT INTO users (id, employee_id, username, email, password_hash, role, status) VALUES
(1,1,'admin','admin@spotcommglobal.com','$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqDQK7eS0mBxXq6qQk1bY8oJ1mE0K2','admin','active'),
(2,2,'hr','hr@spotcommglobal.com','$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqDQK7eS0mBxXq6qQk1bY8oJ1mE0K2','hr','active'),
(3,3,'manager','lead@spotcommglobal.com','$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqDQK7eS0mBxXq6qQk1bY8oJ1mE0K2','manager','active'),
(4,4,'employee','john@spotcommglobal.com','$2y$10$N9qo8uLOickgx2ZMRZoMy.MrqDQK7eS0mBxXq6qQk1bY8oJ1mE0K2','employee','active');

-- Leave balances for sample employee for current year
INSERT INTO leave_balances (employee_id, leave_type_id, year, allocated, used) VALUES
(4,1,YEAR(CURDATE()),10,1),
(4,2,YEAR(CURDATE()),8,0),
(4,3,YEAR(CURDATE()),14,2);

-- Sample loan for portal demo
INSERT INTO loans (employee_id, amount, installment, total_installments, paid_installments, remaining, reason, status, start_date)
VALUES (4, 100000, 10000, 10, 2, 80000, 'Personal Loan', 'active', DATE_SUB(CURDATE(), INTERVAL 2 MONTH));
