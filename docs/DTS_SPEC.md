# VVU Document Tracking System (DTS) — System Specification

**Version:** 1.0 (Phase 1 / MVP)
**Client:** Valley View University (VVU)
**Developer:** Nadics Solutions
**Stack:** PHP 8.3 (no framework, custom lightweight MVC) · MySQL 8 · Bootstrap 5 · Composer
**Target server:** Ubuntu VPS, Nginx + PHP-FPM (CloudPanel), served at e.g. `https://dts.vvu.edu.gh`

---

## 1. Purpose

When one office or department at VVU sends a document (memo, letter, voucher, request, file) to another, there is no reliable record of where it is. Documents get lost, delayed, or sit unattended, and nobody can prove who had it last.

The DTS gives every document a **unique tracking number and QR code**, records **every movement between departments**, and requires the receiving office to **acknowledge receipt**. At any moment, anyone authorised can see **exactly which department and which person holds a document**, and how long it has been there.

### 1.1 Goals
1. Every document has one unique tracking number and a printable QR label.
2. Every movement is logged: from, to, sent by, received by, and when.
3. A document is only considered "at" a department once that department **acknowledges receipt**.
4. Documents stuck in transit or idle too long are flagged automatically.
5. Users see their department's incoming, outgoing, pending and overdue documents at a glance.
6. Movement history is permanent and cannot be edited or deleted.

### 1.2 Out of scope for Phase 1
SMS notifications, multi-step routing templates, digital signatures and approvals, archiving and retention, advanced reports, OCR, and integrations with other VVU systems. See §15.

---

## 2. Glossary

| Term | Meaning |
|---|---|
| **Document** | A physical and/or digital item registered in the system. |
| **Tracking number** | Unique ID, e.g. `VVU-REG-2026-00042`. |
| **Movement** | One transfer of a document from one department to another. |
| **Holder department** | The department that currently has the document (after receipt). |
| **In transit** | Sent but not yet acknowledged by the receiving department. |
| **Acknowledge / Receive** | The receiving department confirms it physically/digitally has the document. |
| **SLA** | Number of working days a department should take to act before the document is overdue. |

---

## 3. User Roles & Permissions

Three roles in Phase 1. Every user belongs to exactly **one department**.

| Permission | Staff | HOD | Admin |
|---|:-:|:-:|:-:|
| Log in, view own dashboard | ✅ | ✅ | ✅ |
| Register a document (origin = own department) | ✅ | ✅ | ✅ |
| Send/forward a document held by own department | ✅ | ✅ | ✅ |
| Receive a document sent to own department | ✅ | ✅ | ✅ |
| Return a document to the sender department | ✅ | ✅ | ✅ |
| Recall (cancel) a send that hasn't been received yet | ✅ (own sends) | ✅ (dept sends) | ✅ |
| Mark a document Completed | ✅ | ✅ | ✅ |
| View documents that passed through own department | ✅ | ✅ | ✅ |
| View **confidential** document details | Only if their department is/was on the route | Same + all of own dept | ✅ |
| View department performance/overdue list | ❌ | ✅ (own dept) | ✅ (all) |
| Receive escalation alerts for own department | ❌ | ✅ | ✅ |
| Manage users, departments, document types, settings | ❌ | ❌ | ✅ |
| View audit logs | ❌ | ❌ | ✅ |
| View all documents university-wide | ❌ | ❌ | ✅ |

**Rule:** document actions are department-scoped. Any active user in the holder department can act on a document the department holds. This avoids documents getting stuck when one person is absent.

---

## 4. Document Lifecycle (State Machine)

### 4.1 Document statuses

| Status | Meaning |
|---|---|
| `draft` | Registered but not yet sent. Held by the origin department. |
| `in_transit` | Sent; waiting for the receiving department to acknowledge. |
| `received` | Acknowledged and held by `current_department_id`. |
| `completed` | Action finished; document closed. Read-only. |
| `cancelled` | Registered in error and voided before its first send (Admin or creator only). |

### 4.2 Allowed transitions

```
            send                 receive
 draft ─────────────▶ in_transit ─────────▶ received
   │                    │   ▲                 │  │
   │ cancel             │   │ forward/return  │  │ complete
   ▼                    │   └─────────────────┘  ▼
 cancelled              │ recall            completed
                        ▼
              (back to previous state:
               draft or received at sender dept)
```

| Action | From status | Who | Result |
|---|---|---|---|
| **Send** | `draft` | User in origin dept | New movement; doc → `in_transit` |
| **Receive** | `in_transit` | User in movement's `to_department` | Movement `received`; doc → `received`; `current_department_id = to_department_id`; `current_holder_id = receiver` |
| **Forward** | `received` | User in holder dept | New movement; doc → `in_transit` |
| **Return** | `received` | User in holder dept | New movement with `action='return'` back to the department it came from; doc → `in_transit` |
| **Recall** | `in_transit` | Original sender / HOD / Admin, only before it's received | Movement → `recalled`; doc returns to previous status at sender dept |
| **Complete** | `received` | User in holder dept | doc → `completed`, `completed_at = NOW()` |
| **Cancel** | `draft` | Creator or Admin | doc → `cancelled` |

**Invariants (enforce in the service layer, not just the UI):**
- A document has **at most one** movement with status `in_transit` at any time.
- Only the holder department can forward, return, or complete.
- Only the `to_department` of the open movement can receive.
- A department cannot send a document to itself.
- `completed` and `cancelled` documents cannot be changed.
- Every state change runs inside a **DB transaction** with `SELECT ... FOR UPDATE` on the document row, so two users can't act on it at the same time.
- Every state change writes an `audit_logs` row.

---

## 5. Tracking Number & QR Code

### 5.1 Format
```
VVU-{DEPT_CODE}-{YYYY}-{SEQ}
e.g. VVU-REG-2026-00042   VVU-BUR-2026-00007
```
- `DEPT_CODE` = the **origin** department's short code (2–6 uppercase letters).
- `SEQ` = 5-digit zero-padded counter, **per department per year**, resetting each January.
- Generated from the `tracking_sequences` table inside a transaction (`SELECT ... FOR UPDATE`, increment, insert). Never use `MAX(id)+1`.

### 5.2 QR code
- Encodes the URL `{APP_URL}/track/{tracking_no}`.
- Generated server-side with `endroid/qr-code` and printed as a **label** (A4 sheet or sticker size). The label shows the QR, tracking number, title (omitted if confidential), origin department, and date registered.
- **Scanning while logged in** opens the document page. If the document is in transit to the scanner's department, a prominent **"Receive this document"** button appears, and receipt is recorded with `receive_method='qr'`.
- **Scanning while logged out** opens the public tracking page (§7.3).

---

## 6. Functional Requirements (Phase 1)

### FR-1 Authentication
- Login with **email or staff ID** + password. Passwords hashed with `password_hash()` (bcrypt/argon2).
- Lock the account for 15 minutes after 5 failed attempts.
- "Forgot password" sends a time-limited reset link by email (token stored hashed, expires in 60 min).
- Session regenerated on login; idle timeout 30 min (configurable).
- Users can change their own password and update phone and profile details.

### FR-2 Department & User Management (Admin)
- CRUD departments: name, code (unique), parent department (optional), location, email, active flag.
- CRUD users: staff ID, full name, email, phone, department, role, active flag.
- Set the HOD for each department (`departments.head_user_id`).
- Deactivate rather than delete users and departments that have history.
- Bulk import users from CSV (staff_id, full_name, email, phone, department_code, role).

### FR-3 Document Types (Admin)
- CRUD document types (Memo, Letter, Payment Voucher, Transcript Request, Leave Request, Contract, Other…), each with a default `sla_days` (working days).

### FR-4 Register Document
- Fields: title*, document type*, reference number, description, priority* (`normal`, `urgent`), confidentiality* (`normal`, `confidential`), format* (`physical`, `digital`, `both`), number of pages, attachments (optional).
- On save: generate the tracking number, set status `draft`, set origin and current department to the user's department, and log the action.
- After saving, offer **"Print QR label"** and **"Send now"**.

### FR-5 Attachments
- Allowed: PDF, JPG, PNG, DOCX, XLSX. Max 10 MB per file (configurable), max 5 per upload.
- Stored **outside the web root** (`storage/uploads/{year}/{month}/`) under a random file name. The original name is kept in the DB.
- Downloaded only through a controller that checks permission. MIME type checked with `finfo`, not the file extension.
- Holder-department users can add attachments at any stage before completion. Attachments are never deleted (soft-hide by Admin only).

### FR-6 Send / Forward / Return / Recall
- **Send/Forward form:** destination department* (searchable dropdown, excluding own dept), specific recipient (optional, filtered to that dept's users), action note* (e.g. "For your approval", "For your information", "Please treat"), due date (defaults to today + document type SLA in working days).
- **Return:** sends back to the department it came from; a reason is required.
- **Recall:** only while unreceived; a reason is required.
- Each action notifies the receiving department (§FR-9).

### FR-7 Receive (Acknowledge)
- **Incoming** list for the department: all `in_transit` movements addressed to it.
- Receive by clicking **Receive** (single or bulk-select) or by **scanning the QR code**.
- Optional receipt note (e.g. "Received with 3 attachments, 1 missing").
- Records `received_by`, `received_at`, `receive_method`.

### FR-8 Tracking & History
- **Document detail page:** all metadata, current status badge, current department and holder, time at the current location ("3 days"), attachments, and the action buttons the user is allowed to use.
- **Movement timeline:** vertical timeline, newest first. Each entry shows from dept → to dept, action, note, sent by and time, received by and time (or "Awaiting receipt"), receive method, and time taken.
- Visual progress strip of the departments visited.
- **Track by number:** a search box in the top bar accepts a tracking number and goes straight to the document.

### FR-9 Notifications (in-app + email)

| Event | Who is notified |
|---|---|
| Document sent/forwarded/returned to a department | Specific recipient if set; otherwise all active users of the to-department |
| Document received | Sender (user who created the movement) |
| Document recalled | Users of the to-department |
| Document completed | Document creator |
| Not acknowledged within `unreceived_alert_hours` (default 24h) | To-department users + sender; HOD of to-department after 2× threshold |
| Overdue at holder department (past due date) | Holder-department users + HOD |

- In-app: bell icon with unread count; notifications page; mark one or all as read.
- Email: PHPMailer via SMTP (settings in `.env`). Emails are queued in `email_queue` and sent by cron, so actions stay fast.
- Duplicate alerts are not resent within `alert_repeat_hours` (default 24h).

### FR-10 Overdue & Stuck Detection (Cron)
`cron/check_alerts.php` runs every 30 minutes:
1. **Unacknowledged:** movements `in_transit` older than `unreceived_alert_hours` → notify and flag.
2. **Overdue:** documents `received` where the current movement's `due_date` < today → notify and flag.
3. Set `last_alert_at` on the movement to prevent duplicate alerts.
4. Working days exclude Saturday and Sunday plus dates in the `holidays` table.

Overdue and stuck documents are highlighted in red/amber in all lists.

### FR-11 Dashboards
- **Staff/HOD (department) dashboard:** stat cards for **Incoming** (awaiting our receipt), **With us** (received, not completed), **Outgoing** (sent by us, not yet received elsewhere), **Overdue**, **Completed this month**. Below the cards: an "Awaiting your receipt" table and a "Recently moved" table.
- **HOD extra:** department's average processing time, and an overdue list with holders.
- **Admin dashboard:** university-wide totals by status, top 5 departments by overdue count, documents in transit > 24h, recent activity feed.

### FR-12 Search & Lists
- Lists: Incoming · With Us · Outgoing · Registered by Us · Completed · All (Admin).
- Filters: tracking no, title/keyword, document type, status, priority, origin department, current department, date range.
- Server-side pagination (25 per page) and sortable columns.
- Export the current list to CSV.

### FR-13 Audit Trail (Admin)
- Logs: login/logout/failed login, document create/view (confidential only)/send/receive/forward/return/recall/complete/cancel, attachment upload/download, user and department changes, settings changes.
- Stores user, action, document (if any), JSON details, IP, user agent, timestamp.
- Admin page to filter and view logs. **There is no edit or delete in the UI or code.**

### FR-14 Public Tracking Page (no login)
- `/track` with a tracking-number input, plus `/track/{tracking_no}`.
- Shows only: tracking no, document type, status, current department, last updated. Title is shown only if confidentiality = `normal`.
- Rate-limited (e.g. 30 requests per IP per 10 min).

### FR-15 Settings (Admin)
- App name, logo, primary color, `unreceived_alert_hours`, `alert_repeat_hours`, `default_sla_days`, max upload size, holidays list, SMTP test-email button.

---

## 7. Pages & Routes

### 7.1 Auth
| Method | Route | Page/Action |
|---|---|---|
| GET/POST | `/login` | Login |
| POST | `/logout` | Logout |
| GET/POST | `/forgot-password` | Request reset |
| GET/POST | `/reset-password/{token}` | Set new password |

### 7.2 App (logged in)
| Method | Route | Page/Action |
|---|---|---|
| GET | `/` | Dashboard |
| GET | `/documents` | All lists (`?tab=incoming|with-us|outgoing|registered|completed|all`) |
| GET/POST | `/documents/create` | Register document |
| GET | `/documents/{id}` | Detail + timeline |
| GET | `/documents/{id}/label` | Printable QR label |
| POST | `/documents/{id}/send` | Send/forward |
| POST | `/documents/{id}/receive` | Receive |
| POST | `/documents/receive-bulk` | Bulk receive |
| POST | `/documents/{id}/return` | Return |
| POST | `/documents/{id}/recall` | Recall |
| POST | `/documents/{id}/complete` | Complete |
| POST | `/documents/{id}/cancel` | Cancel draft |
| POST | `/documents/{id}/attachments` | Upload attachment |
| GET | `/attachments/{id}/download` | Secure download |
| GET | `/scan` | Camera QR scanner page (mobile) |
| GET | `/notifications` | Notifications list |
| POST | `/notifications/read-all` | Mark all read |
| GET/POST | `/profile` | Profile & password |

### 7.3 Public
| GET | `/track`, `/track/{tracking_no}` | Public tracking |

### 7.4 Admin
| Route prefix | Purpose |
|---|---|
| `/admin/users` | CRUD + CSV import |
| `/admin/departments` | CRUD |
| `/admin/document-types` | CRUD |
| `/admin/holidays` | CRUD |
| `/admin/settings` | Settings |
| `/admin/audit-logs` | View logs |

### 7.5 JSON endpoints (AJAX)
| GET | `/api/departments/{id}/users` | Recipients for the send form |
| GET | `/api/notifications/unread-count` | Bell badge polling (every 60s) |

---

## 8. Database Schema (MySQL 8, InnoDB, utf8mb4_unicode_ci)

All tables have `created_at`/`updated_at` unless noted. Use foreign keys.

```sql
CREATE TABLE departments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(6) NOT NULL UNIQUE,
  parent_id INT UNSIGNED NULL,
  head_user_id INT UNSIGNED NULL,          -- FK added after users table
  location VARCHAR(150) NULL,
  email VARCHAR(150) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (parent_id) REFERENCES departments(id)
);

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id VARCHAR(30) NOT NULL UNIQUE,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(20) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','hod','staff') NOT NULL DEFAULT 'staff',
  department_id INT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (department_id) REFERENCES departments(id)
);

ALTER TABLE departments
  ADD FOREIGN KEY (head_user_id) REFERENCES users(id);

CREATE TABLE password_resets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX (token_hash)
);

CREATE TABLE document_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  sla_days TINYINT UNSIGNED NOT NULL DEFAULT 3,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE tracking_sequences (
  department_id INT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (department_id, year),
  FOREIGN KEY (department_id) REFERENCES departments(id)
);

CREATE TABLE documents (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tracking_no VARCHAR(30) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  document_type_id INT UNSIGNED NOT NULL,
  reference_no VARCHAR(100) NULL,
  description TEXT NULL,
  priority ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  confidentiality ENUM('normal','confidential') NOT NULL DEFAULT 'normal',
  format ENUM('physical','digital','both') NOT NULL DEFAULT 'physical',
  pages SMALLINT UNSIGNED NULL,
  origin_department_id INT UNSIGNED NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  current_department_id INT UNSIGNED NOT NULL,
  current_holder_id INT UNSIGNED NULL,
  status ENUM('draft','in_transit','received','completed','cancelled') NOT NULL DEFAULT 'draft',
  completed_at DATETIME NULL,
  completed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (document_type_id) REFERENCES document_types(id),
  FOREIGN KEY (origin_department_id) REFERENCES departments(id),
  FOREIGN KEY (created_by) REFERENCES users(id),
  FOREIGN KEY (current_department_id) REFERENCES departments(id),
  FOREIGN KEY (current_holder_id) REFERENCES users(id),
  FOREIGN KEY (completed_by) REFERENCES users(id),
  INDEX (status), INDEX (current_department_id, status), INDEX (created_at),
  FULLTEXT (title, description, reference_no)
);

CREATE TABLE document_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  action ENUM('send','forward','return') NOT NULL,
  from_department_id INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  to_department_id INT UNSIGNED NOT NULL,
  to_user_id INT UNSIGNED NULL,               -- optional specific recipient
  note VARCHAR(500) NOT NULL,
  due_date DATE NULL,
  status ENUM('in_transit','received','recalled') NOT NULL DEFAULT 'in_transit',
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by INT UNSIGNED NULL,
  received_at DATETIME NULL,
  receive_method ENUM('click','qr','bulk') NULL,
  receipt_note VARCHAR(500) NULL,
  recalled_by INT UNSIGNED NULL,
  recalled_at DATETIME NULL,
  recall_reason VARCHAR(500) NULL,
  last_alert_at DATETIME NULL,
  FOREIGN KEY (document_id) REFERENCES documents(id),
  FOREIGN KEY (from_department_id) REFERENCES departments(id),
  FOREIGN KEY (from_user_id) REFERENCES users(id),
  FOREIGN KEY (to_department_id) REFERENCES departments(id),
  FOREIGN KEY (to_user_id) REFERENCES users(id),
  FOREIGN KEY (received_by) REFERENCES users(id),
  FOREIGN KEY (recalled_by) REFERENCES users(id),
  INDEX (document_id, sent_at),
  INDEX (to_department_id, status),
  INDEX (from_department_id, status)
);

CREATE TABLE attachments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  document_id INT UNSIGNED NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  uploaded_by INT UNSIGNED NOT NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (document_id) REFERENCES documents(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  document_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,      -- incoming, received, returned, recalled, completed, unreceived_alert, overdue_alert
  message VARCHAR(500) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (document_id) REFERENCES documents(id),
  INDEX (user_id, is_read)
);

CREATE TABLE email_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(150) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  INDEX (status)
);

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  document_id INT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  details JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id), INDEX (document_id), INDEX (action), INDEX (created_at)
);

CREATE TABLE holidays (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL
);

CREATE TABLE settings (
  `key` VARCHAR(60) PRIMARY KEY,
  `value` TEXT NULL
);

CREATE TABLE login_throttle (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_address VARCHAR(45) NOT NULL,
  scope VARCHAR(30) NOT NULL,     -- login, public_track
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (ip_address, scope, created_at)
);
```

**Implementation note (Phase 8, performance):** `documents.current_movement_id` (nullable FK to
`document_movements.id`) points at the movement that brought the document to its current holder
(the last *received* movement). `MovementService::receive()` sets it in the same transaction. It
replaces a per-row "latest received movement" subquery in lists, dashboards and alerts. Migration
`016` adds it (with a backfill) plus indexes on `document_movements (document_id, status)`,
`(status, sent_at)`, `documents (origin_department_id, created_at)`, `(status, completed_at)` and
`notifications (user_id, created_at)`.

### 8.1 Seed data
- **Departments (sample):** Office of the Vice-Chancellor (VC), Registry (REG), Bursary/Finance (BUR), Human Resources (HR), Admissions (ADM), Academic Affairs (ACA), Faculty of Science (FOS), Faculty of Business (FOB), ICT Directorate (ICT), Library (LIB), Chaplaincy (CHP), Students' Affairs (SAF). Edit these to match VVU's actual structure.
- **Document types:** Memo (3), Letter (3), Payment Voucher (2), Transcript Request (5), Leave Request (2), Contract (5), Student Petition (5), Other (3).
- **Users:** 1 Admin, plus 1 HOD and 2 staff in REG, BUR and ICT for testing. Default password `ChangeMe@123` for dev only.
- **Settings:** `unreceived_alert_hours=24`, `alert_repeat_hours=24`, `default_sla_days=3`, `max_upload_mb=10`, `app_name=VVU Document Tracking System`.
- **Holidays:** Ghana public holidays for the current year.

---

## 9. Architecture & Folder Structure

Plain PHP using a lightweight MVC pattern. **Only `public/` is web-accessible.**

```
vvu-dts/
├── public/
│   ├── index.php              # Front controller: bootstrap + router dispatch
│   ├── assets/{css,js,img}/   # Bootstrap 5, Bootstrap Icons, app.css, app.js, html5-qrcode
│   └── .htaccess              # (Apache local dev only)
├── app/
│   ├── Controllers/           # AuthController, DashboardController, DocumentController, ...
│   │   └── Admin/             # UserController, DepartmentController, ...
│   ├── Models/                # Thin data-access classes (PDO, prepared statements)
│   ├── Services/              # Business logic: DocumentService, TrackingNumberService,
│   │                          #   MovementService, NotificationService, MailService,
│   │                          #   QrService, AuditService, WorkingDayService, UploadService
│   ├── Middleware/            # AuthMiddleware, RoleMiddleware, CsrfMiddleware, GuestMiddleware
│   ├── Policies/              # DocumentPolicy (canView, canSend, canReceive, canComplete...)
│   ├── Views/
│   │   ├── layouts/           # app.php (sidebar+topbar), auth.php, print.php, public.php
│   │   ├── partials/          # flash, pagination, status-badge, timeline
│   │   └── {auth,dashboard,documents,notifications,admin,track,errors}/
│   └── Helpers/functions.php  # e(), url(), asset(), old(), csrf_field(), flash(), auth()
├── core/
│   ├── App.php  Router.php  Request.php  Response.php
│   ├── Database.php           # PDO singleton, transaction helper
│   ├── Session.php  Auth.php  Csrf.php  Validator.php  View.php  Config.php
├── config/
│   ├── app.php  database.php  mail.php
├── database/
│   ├── migrations/            # 001_create_departments.sql ... (numbered, run in order)
│   ├── seeds/                 # seed.sql or seed.php
│   └── migrate.php            # CLI runner: php database/migrate.php [--seed] [--fresh]
├── cron/
│   ├── check_alerts.php       # every 30 min
│   └── send_emails.php        # every 2 min
├── storage/
│   ├── uploads/               # NOT web-accessible
│   ├── logs/app.log
│   └── cache/
├── tests/                     # PHPUnit: services & policies
├── docs/DTS_SPEC.md
├── .env.example
├── composer.json
├── CLAUDE.md
└── README.md
```

### 9.1 Composer packages
- `vlucas/phpdotenv`: environment config
- `phpmailer/phpmailer`: SMTP email
- `endroid/qr-code`: QR generation
- `phpunit/phpunit` (dev): tests

Front-end (local files, no build step): Bootstrap 5.3, Bootstrap Icons, `html5-qrcode` (camera scanning), and vanilla JS. No jQuery.

### 9.2 Request flow
`public/index.php` → load `.env` and config → start session → `Router` matches route → middleware chain (Auth → Role → CSRF on POST) → Controller → (Policy check) → Service (business rules + transaction + audit + notifications) → Model (PDO) → `View::render()` with layout.

---

## 10. Non-Functional Requirements

### 10.1 Security
- **SQL:** PDO prepared statements only; `PDO::ATTR_EMULATE_PREPARES=false`; never interpolate user input into SQL.
- **XSS:** escape all output with `e()` (`htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`).
- **CSRF:** token on every POST form and AJAX POST; verified by middleware.
- **Authorisation:** every document action checks `DocumentPolicy` on the server; the UI hiding a button is not enough.
- **Sessions:** `httponly`, `secure` (in production), `SameSite=Lax`; regenerate ID on login.
- **Uploads:** whitelist extensions + `finfo` MIME check, random stored names, outside web root, size limit.
- **Headers:** `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, basic CSP.
- **Errors:** `APP_DEBUG=false` in production; log to `storage/logs`, show friendly 404/403/500 pages.
- **Rate limiting:** login and public tracking (`login_throttle`).

### 10.2 Performance
- Indexed queries (see schema), pagination everywhere, emails sent via queue + cron.
- Target: pages under 500 ms with 50k documents.

### 10.3 Usability
- Responsive (works on phones for QR scanning and receiving).
- Clear status badges: Draft (grey), In Transit (amber), Received (blue), Completed (green), Cancelled (dark), Overdue (red outline/flag).
- Times shown as Africa/Accra; relative times ("2 hours ago") with exact time on hover.
- Flash messages for every action; confirmation modal for Complete, Recall, Cancel.

### 10.4 Reliability
- All state changes are transactional.
- Daily MySQL backup (cron `mysqldump`, keep 14 days) and backup of `storage/uploads`.

---

## 11. UI Layout Guidelines
- **App layout:** left sidebar (Dashboard, Register Document, Incoming [badge], With Us, Outgoing, Registered, Completed, Scan QR, and an Admin section for admins); top bar with the tracking-number quick search, notification bell, and user menu.
- **Branding:** VVU logo and a configurable primary color (`settings.primary_color`); clean, professional, institutional look.
- **Document detail:** two columns on desktop (details + actions left, timeline right); stacked on mobile.
- **QR label print view:** no navigation, printer-friendly CSS, "Print" button.

---

## 12. Environment Config (`.env.example`)
```
APP_NAME="VVU Document Tracking System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=Africa/Accra

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vvu_dts
DB_USERNAME=root
DB_PASSWORD=

MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=dts@vvu.edu.gh
MAIL_FROM_NAME="VVU DTS"

SESSION_LIFETIME_MINUTES=30
```

---

## 13. Build Phases & Acceptance Criteria

| Phase | Scope | Done when… |
|---|---|---|
| **0. Foundation** | Folder structure, Composer, `.env`, core (Router, DB, Session, View, CSRF, Validator), layouts, error pages | `/` renders a layout page; 404 page works; DB connects |
| **1. Database** | All migrations, migrate runner, seeds | `php database/migrate.php --fresh --seed` builds everything with no errors |
| **2. Auth & Admin setup** | Login/logout, throttle, reset password, profile; Admin CRUD for departments, users (+CSV import), document types, holidays, settings | Seeded users can log in; roles restrict admin pages; CRUD works |
| **3. Registration** | Register document, tracking number service, attachments, QR label | Created docs get correct sequential numbers per dept/year; QR label prints and scans to the right URL |
| **4. Movement engine** | Send, receive (click/bulk), forward, return, recall, complete, cancel; DocumentPolicy; audit logging | All transitions in §4.2 work; illegal ones are rejected server-side; concurrent receive can't double-record |
| **5. Tracking UI** | Document detail, timeline, lists with tabs/filters/pagination/CSV, quick search, public tracking page, mobile QR scan-to-receive | A document can be followed end to end across 4 departments; QR scan on phone receives it |
| **6. Notifications** | In-app notifications, bell polling, email queue + sender cron | Each event in FR-9 produces the right notifications/emails |
| **7. Alerts & Dashboards** | `check_alerts.php`, working-day due dates, dashboards for staff/HOD/admin | Unreceived and overdue docs flagged and alerted once per repeat window; dashboard counts are correct |
| **8. Hardening & Deploy** | Security review, tests, README, Nginx config, cron setup, backups | Checklist in §14 passes; app runs on the VPS over HTTPS |

---

## 14. Testing Checklist
- [ ] Tracking numbers unique and sequential under concurrent creation
- [ ] Cannot receive a document sent to another department (direct POST blocked)
- [ ] Cannot forward a document your department doesn't hold
- [ ] Cannot send to own department
- [ ] Only one open in-transit movement per document
- [ ] Recall blocked after receipt
- [ ] Completed/cancelled documents are read-only
- [ ] Confidential documents hidden from unrelated departments, and title hidden on the public page
- [ ] CSRF token missing → 419/403
- [ ] Upload rejects `.php` renamed as `.pdf`
- [ ] Attachments not reachable by direct URL
- [ ] Login locks after 5 failures
- [ ] Alerts not duplicated within repeat window
- [ ] Due dates skip weekends and holidays
- [ ] Audit log row written for every action
- [ ] Works on mobile Chrome (scan + receive)

---

## 15. Phase 2 Backlog (not now)
SMS alerts (Hubtel/Arkesel/mNotify) · Predefined routing templates (multi-step routes) · Approvals & digital signatures · Delegation when staff are on leave · Archiving with shelf/box location and retention · Reports (PDF/Excel, department performance) · Watermarked downloads · OCR full-text search · Student self-service requests · Integration with VVU portals, HR data and DSpace · REST API.
