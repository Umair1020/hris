# Spotcomm Global HRIS — Zero-Config Edition ⚡

A complete **Human Resource Information System** for Spotcomm Global, built in **vanilla PHP + SQLite**.

> **Unzip → Upload → Open the URL → It just works.** No database creation, no installer, no config.

---

## 🎯 3-Step Setup (truly zero-config)

1. **Upload** `spotcomm-hris.zip` to your hosting (cPanel File Manager, or `public_html` for `hris.spotcomm.pk`, or a subfolder).
2. **Extract** the zip.
3. **Open** `https://hris.spotcomm.pk/` in your browser.

That's it! The database (a single SQLite file) is created automatically on first visit. The app auto-detects its own URL whether it's at a subdomain root or in a subfolder.

### 🔑 Default Logins (password: `Spotcomm@2026`)
| Username | Role | Access |
|----------|------|--------|
| `admin` | Admin (Management) | Everything |
| `hr` | HR | Edit all employee data, payroll, ATS, letters |
| `manager` | Manager | Approve team leaves & reviews |
| `employee` | Employee | Self-service view + edit own profile |

> 👉 Change these passwords from **My Profile → Change Password** after first login.

---

## ✅ What's Inside — 7 Modules

1. **Time & Attendance** — Clock in/out (portal + location capture + ID-card scan), auto hours, overtime & undertime
2. **Payroll Management** — Auto-calculated from attendance, loans & bonuses; Finance CSV upload; printable payslips
3. **Applicant Tracking (ATS)** — Job postings, public apply form, **resume auto-scoring** against keywords, interview scheduling + email
4. **Performance / Evaluation** — KPI templates per department, scoring with live **radar chart** + auto-generated text analysis
5. **Leave Management** — Self-service; Casual (7-day notice) / Medical (1-day) / **Emergency (HR-immediate)** rules enforced
6. **HR Letters** — Templated offer/confirmation/experience letters with automatic variable substitution
7. **Separation** — Resignation submission, handover uploads, **clearance checklist** (HR/IT/Finance/Admin), exit interview

Plus: Employee directory, self-service portal, company policy viewer, role-based access control.

---

## 🛡️ Security Features
- Passwords hashed with **bcrypt**
- **CSRF protection** on every form
- Role-based access (Employee → Manager → HR → Admin)
- Employees can **view** everything but **edit only personal info**
- Only HR/Management can edit all employee data
- The database file is protected from web download via `.htaccess`

---

## 🗄️ About the Database (SQLite)
- The database is a single file: **`database/spotcomm.db`** (auto-created, ~230 KB with sample data)
- **No MySQL needed** — that's why there's nothing to configure
- If your `database/` folder isn't writable, just set its permission to **755** (or 777) in cPanel File Manager
- All data lives in that one file — back it up by downloading `spotcomm.db`

### (Optional) Want MySQL instead?
Create `config/.env.php` with:
```php
<?php
define('DB_TYPE', 'mysql');
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_db');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_pass');
```
Then import `database/schema.sql` via phpMyAdmin.

---

## 🎨 Branding
Colors & fonts matched to **spotcommglobal.com**:
- Indigo `#2e3192` · Purple `#7d3ef2` · Navy `#1e1e2d` · Orange `#f26223`
- Fonts: Oswald (headings) + Mulish (body)
- Logo recreated as SVG — replace `assets/img/logo.svg` with the official file if you have it

---

## ❓ Troubleshooting
| Problem | Fix |
|---------|-----|
| Blank page / "cannot create database file" | Set `database/` folder permission to **755** or **777** |
| Resume keyword scoring empty | Install `pdftotext` (poppler-utils), or use DOC resumes |
| Want to reset everything | Delete `database/spotcomm.db` — it re-creates fresh on next visit |
| Forgot admin password | Delete `database/spotcomm.db` to reset (⚠️ loses all data) |

---
**Spotcomm HRIS v1.0.0** · Outsource · Optimize · Thrive
