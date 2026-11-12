# Claude Code Prompts — VVU Document Tracking System

## How to use this file

1. Create a project folder, e.g. `vvu-dts/`, and open it in VS Code.
2. Put **`CLAUDE.md`** in the project root and **`DTS_SPEC.md`** + this file in `docs/`. Claude Code automatically loads `CLAUDE.md` at the start of every session.
3. Open Claude Code in VS Code and paste the **Master Kickoff Prompt** below.
4. Then run the **phase prompts in order**, one at a time. After each phase:
   - test it yourself in the browser,
   - commit with git (`git add . && git commit -m "Phase X: ..."`),
   - start a fresh conversation (`/clear`) before the next phase to keep context clean. CLAUDE.md and the spec keep Claude on track.
5. Tip: for bigger phases, switch to **Plan mode** first (Shift+Tab in Claude Code) so it plans before editing files, then approve the plan.

Local requirements: PHP 8.3 with extensions `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `openssl`; MySQL 8 (XAMPP/Laragon on Windows is fine); Composer; Git.

---

## ⭐ Master Kickoff Prompt (paste first)

```
You are a senior PHP engineer building a production-quality web application for
Valley View University (VVU): the VVU Document Tracking System (DTS).

CONTEXT
- Read CLAUDE.md (project rules) and docs/DTS_SPEC.md (full specification) completely
  before doing anything. The spec is the source of truth for features, roles, the
  document state machine (§4), tracking number format (§5), routes (§7), database
  schema (§8), folder structure (§9), security requirements (§10) and build phases (§13).
- Stack: PHP 8.3 (no framework, custom lightweight MVC), MySQL 8 via PDO, Bootstrap 5.3,
  vanilla JS, Composer (phpdotenv, PHPMailer, endroid/qr-code, PHPUnit).
- The core purpose: when a department sends a document to another department, the
  system must always show exactly where it is. A document is only "at" a department
  after that department acknowledges receipt. Movement history is permanent.

YOUR TASK RIGHT NOW
1. Summarise your understanding of the system in 10 bullet points or fewer, including
   the document state machine, so I can confirm you understood it.
2. List any contradictions or gaps you find in the spec, with the assumption you
   will make for each (don't block on them).
3. Produce a build plan for Phase 0 (Foundation) only: every file you will create
   and its responsibility.
4. Then implement Phase 0 fully:
   - Folder structure exactly as spec §9
   - composer.json with PSR-4 autoload (App\ → app/, Core\ → core/) and the packages
   - .env.example (spec §12), config/*.php reading from env
   - core/: App, Router (GET/POST, route params like {id}, named middleware groups),
     Request, Response, Database (PDO singleton, transaction helper), Session, Csrf,
     Validator (required, email, max, min, in, unique, exists, file rules), View
     (layouts + partials), Config
   - app/Helpers/functions.php: e(), url(), asset(), redirect(), old(), csrf_field(),
     flash(), auth(), config(), now()
   - Middleware base + CsrfMiddleware
   - public/index.php front controller with error handling (debug vs production),
     security headers (spec §10.1)
   - Layouts: app (sidebar + topbar placeholder), auth, public, print; partials for
     flash messages and pagination; 403/404/419/500 error pages
   - A temporary home route that renders the app layout to prove it works
   - README.md with setup steps
   - .gitignore (vendor, .env, storage/uploads/*, storage/logs/*)

QUALITY BAR
- declare(strict_types=1), PSR-12, small classes, docblocks on public methods.
- Secure by default: prepared statements, escaped output, CSRF on all POSTs.
- No placeholder "TODO" code in delivered features; if something belongs to a later
  phase, leave it out cleanly.

WHEN DONE
- Tell me the exact commands to install and run it locally.
- List what you built, how to verify it, and the assumptions you made.
- Stop and wait for me before starting Phase 1.
```

---

## Phase 1 — Database

```
Implement Phase 1 (Database) of docs/DTS_SPEC.md.

1. Create numbered SQL migration files in database/migrations/ for every table in
   spec §8, in dependency order (handle the departments.head_user_id ↔ users FK by
   adding it in a later migration).
2. Create database/migrate.php CLI runner:
   - tracks applied migrations in a `migrations` table
   - flags: (none) = run pending, --fresh = drop all tables then run, --seed = run seeds
   - clear console output; stops on first error with the failing file name
3. Create seeds (spec §8.1): sample VVU departments, document types with SLA days,
   test users (1 admin; 1 HOD + 2 staff each in REG, BUR, ICT) with password
   ChangeMe@123 hashed via password_hash, default settings, Ghana public holidays for
   the current year.
4. Create app/Models base class (find, all, where, insert, update, paginate using
   prepared statements) and one model per table.

Verify by running: php database/migrate.php --fresh --seed
Report the output, list tables created, and print the test login credentials.
Stop when done.
```

---

## Phase 2 — Authentication & Admin Setup

```
Implement Phase 2 of docs/DTS_SPEC.md: FR-1 (Authentication), FR-2 (Departments &
Users), FR-3 (Document Types), FR-15 (Settings) and the holidays admin.

Requirements:
- Login with email OR staff ID; lockout after 5 failed attempts for 15 minutes; IP
  throttle via login_throttle; session regeneration; idle timeout from .env.
- Forgot/reset password with hashed, single-use, 60-minute tokens (email is queued
  into email_queue for now; sending comes in Phase 6, but also log the reset link to
  storage/logs/app.log in local env so I can test).
- Profile page: update name/phone, change password (requires current password).
- AuthMiddleware, GuestMiddleware, RoleMiddleware('admin'), wired into the router.
- Admin CRUD (list with search + pagination, create, edit, activate/deactivate; no
  hard delete when history exists) for: departments (incl. parent and HOD selection),
  users (incl. CSV bulk import with a downloadable template and a row-by-row error
  report), document types (with SLA days), holidays.
- Settings page for the keys in spec §8.1 / FR-15, plus logo upload and primary color.
- Build the real app layout now: sidebar with menu items per spec §11 (Admin section
  visible only to admins), topbar with quick tracking search box (non-functional
  until Phase 5), notification bell placeholder, user dropdown.
- Write audit_logs entries via an AuditService for login, logout, failed login and
  all admin changes.

Show me a plan first, then implement. When done, give a manual test script
(steps to click through) and stop.
```

---

## Phase 3 — Document Registration, Tracking Number & QR

```
Implement Phase 3 of docs/DTS_SPEC.md: FR-4 (Register Document), FR-5 (Attachments),
and spec §5 (Tracking number & QR label).

Requirements:
- TrackingNumberService: format VVU-{DEPT_CODE}-{YYYY}-{00001}, sequence per origin
  department per year using tracking_sequences inside a transaction with
  SELECT ... FOR UPDATE (insert the row if missing). Must be safe under concurrency.
- DocumentService::register(): validates input, generates tracking number, creates
  the document as 'draft' with origin/current department = user's department,
  saves attachments, writes an audit log. All in one transaction.
- UploadService: whitelist (pdf, jpg, jpeg, png, docx, xlsx), finfo MIME check, size
  limit from settings, random file names under storage/uploads/{Y}/{m}/.
- Secure download route that checks DocumentPolicy::canView before streaming.
- Create DocumentPolicy now with canView (incl. confidentiality rules from spec §3),
  canEdit, canSend, canReceive, canReturn, canRecall, canComplete, canCancel. Later
  phases use it.
- Registration form (Bootstrap, validation errors, old input), and after save a
  success page with buttons "Print QR label", "Send now" (link only for now), "View".
- QrService using endroid/qr-code encoding {APP_URL}/track/{tracking_no}.
- Printable label view (print layout): QR, tracking number, title (hidden if
  confidential), origin department, date. Provide A4 single-label and small-sticker
  sizes via CSS.
- Cancel draft action (creator or admin) with a confirmation modal.

Add PHPUnit tests for TrackingNumberService (format, per-dept sequencing, yearly reset).
Plan first, then implement, then stop with a test script.
```

---

## Phase 4 — Movement Engine (core logic)

```
Implement Phase 4 of docs/DTS_SPEC.md: the movement engine, FR-6 and FR-7, strictly
following the state machine and invariants in spec §4.

Create MovementService with methods: send(), receive(), receiveBulk(), forward(),
returnToSender(), recall(), complete(). Each method must:
1. Run in a DB transaction and lock the document row (SELECT ... FOR UPDATE).
2. Re-check the current status AFTER locking (to prevent race conditions).
3. Enforce DocumentPolicy and the allowed transitions table in §4.2; throw a
   DomainException with a clear user-friendly message on violation.
4. Enforce invariants: one open in_transit movement max; no sending to own
   department; only holder dept can forward/return/complete; only the to_department
   can receive; recall only before receipt; completed/cancelled are read-only.
5. Update documents.status, current_department_id, current_holder_id correctly.
6. Calculate due_date with a WorkingDayService (skip Sat/Sun and holidays table) using
   the document type's sla_days (or default_sla_days) unless the user provides one.
7. Write an audit_logs row with useful JSON details.
8. Call NotificationService (create it now with a notify() method that inserts
   notifications rows; email comes in Phase 6).

UI on the document detail page (simple version; polished in Phase 5):
- Action buttons shown only if the policy allows: Send/Forward (modal: destination
  department searchable select, optional recipient loaded via
  GET /api/departments/{id}/users, required note, optional due date), Receive (with
  optional receipt note), Return (reason), Recall (reason), Complete (confirm).
- Controller actions catch DomainException → flash error; success → flash success.

Add PHPUnit tests for MovementService covering every allowed transition and every
forbidden one listed in spec §14. Plan first, implement, run tests, report results, stop.
```

---

## Phase 5 — Tracking UI, Lists, Search, Public Page, QR Scan

```
Implement Phase 5 of docs/DTS_SPEC.md: FR-8, FR-12, FR-14, and QR scan-to-receive.

- Document detail page (polished): two-column desktop / stacked mobile; metadata card;
  status badge (colors in spec §10.3); "Currently at: {Department} — held by {name}
  — for {relative time}"; attachments list with upload form (holder dept only);
  action buttons; horizontal progress strip of departments visited; vertical
  movement timeline (newest first) with from → to, action, note, sent by/at,
  received by/at or "Awaiting receipt" (amber), receive method icon, time taken,
  and red "Overdue" flag where applicable.
- /documents with tabs: Incoming, With Us, Outgoing, Registered by Us, Completed, All
  (admin). Filters per FR-12, server-side pagination (25), sortable columns,
  "Export CSV" of the current filtered result. Bulk-select + "Receive selected" on
  the Incoming tab. Highlight urgent priority and overdue rows.
- Topbar quick search: exact tracking number → go to document; otherwise go to
  /documents?tab=all-accessible&q=... using FULLTEXT search, respecting permissions.
- Public tracking page /track and /track/{tracking_no} (public layout, VVU branding),
  limited fields per FR-14, rate-limited by IP.
- /scan page using html5-qrcode (local asset): opens rear camera, reads the QR URL,
  redirects to the document. If the document is in transit to the user's
  department, show a large "Receive this document" button that records
  receive_method='qr'. Must work on mobile Chrome.
- Make all pages fully responsive.

Plan first, implement, then give me a full end-to-end manual test: register at REG →
send to BUR → receive via QR → forward to ICT → return to BUR → complete. Stop.
```

---

## Phase 6 — Notifications & Email

```
Implement Phase 6 of docs/DTS_SPEC.md: FR-9.

- NotificationService: for every event in the FR-9 table, create in-app notifications
  for the correct users and queue emails into email_queue. Targeting rule: specific
  recipient if set, otherwise all active users of the to-department.
- Email templates: one clean, responsive HTML layout with VVU branding and a
  "View document" button linking to the document; subject lines include the
  tracking number and priority (e.g. "[URGENT] VVU-REG-2026-00042 sent to Bursary").
- MailService using PHPMailer with .env SMTP settings; "Send test email" button on
  the admin settings page.
- cron/send_emails.php: sends pending emails in batches of 50, retries failed up to
  3 times, records last_error, safe to run concurrently (lock with a DB flag or
  SELECT ... FOR UPDATE SKIP LOCKED).
- Topbar bell: unread count via GET /api/notifications/unread-count polled every 60s;
  dropdown with latest 5; /notifications page with pagination, mark one/all as read;
  clicking a notification marks it read and opens the document.

Plan, implement, give test steps (including how to test email locally with
Mailtrap or similar), stop.
```

---

## Phase 7 — Alerts (Cron) & Dashboards

```
Implement Phase 7 of docs/DTS_SPEC.md: FR-10 and FR-11.

- cron/check_alerts.php (CLI only; refuse to run via web):
  1. Unacknowledged: in_transit movements older than unreceived_alert_hours → notify
     to-department users and sender; if older than 2× threshold also notify the
     to-department HOD.
  2. Overdue: documents with status 'received' whose current movement due_date is
     before today → notify holder department users and HOD.
  3. Respect alert_repeat_hours using movements.last_alert_at (no duplicate spam).
  4. Log a summary line to storage/logs/app.log.
- Shared query helpers (e.g. DocumentQuery / scopes) for: incoming, with-us, outgoing,
  overdue, stuck-in-transit, completed-this-month, so lists and dashboards use the
  same definitions.
- Dashboards:
  - Staff/HOD: stat cards (Incoming, With Us, Outgoing, Overdue, Completed this month)
    each linking to the filtered list; "Awaiting your receipt" table; "Recently moved"
    table.
  - HOD extras: average processing time for the department (received_at → next
    movement sent_at or completed_at) over the last 30 days; overdue list with holders.
  - Admin: university-wide totals by status; top 5 departments by overdue count;
    in-transit > 24h list; recent activity feed from audit_logs.
  - Use simple Chart.js (local asset) for one or two charts max; keep it clean.

Add tests for WorkingDayService and the overdue/stuck queries. Plan, implement, stop.
```

---

## Phase 8 — Hardening, Testing & Deployment

```
Implement Phase 8 of docs/DTS_SPEC.md.

1. Security review: go through every controller, service and view against spec §10.1
   and the checklist in §14. Produce a table of findings (file, issue, fix) and fix
   them all.
2. Run the full PHPUnit suite and fix failures. Add any missing tests for policies.
3. Add the admin audit log viewer (FR-13) if not already complete, with filters.
4. Performance: check every list query uses indexes (EXPLAIN the main ones); add
   indexes if needed.
5. Deployment docs in README.md for an Ubuntu VPS with CloudPanel/Nginx + PHP 8.3-FPM:
   - Nginx server block (root = /public, deny access to everything outside public,
     try_files to index.php, upload size limit, security headers)
   - File permissions for storage/
   - Production .env settings (APP_DEBUG=false, secure cookies)
   - Crontab entries: check_alerts every 30 min, send_emails every 2 min, daily
     mysqldump backup (keep 14 days) + uploads backup
   - HTTPS via Cloudflare or Let's Encrypt
   - Steps to create the first admin user on a fresh server (CLI script
     database/create_admin.php)
6. Final summary: feature checklist vs spec §6 (done/not done), known limitations,
   and suggested Phase 2 items.
```

---

## Handy follow-up prompts

**Fix a bug**
```
Bug: [describe what you did, what you expected, what happened, and paste any error
from storage/logs/app.log or the browser console].
Find the root cause (don't just patch the symptom), explain it in 2–3 sentences, fix
it, and tell me how to verify. Check if the same bug exists elsewhere.
```

**Review before moving on**
```
Review everything built in Phase [X] against docs/DTS_SPEC.md and CLAUDE.md.
List anything missing, inconsistent with the spec, insecure, or duplicated, then fix it.
```

**Change a feature**
```
I want to change [feature]. Update docs/DTS_SPEC.md first to reflect the new behaviour,
show me the diff of the spec, then update the code to match. Don't touch unrelated code.
```

**UI polish**
```
Polish the UI of [page] to look professional and institutional for a university:
consistent spacing, clear hierarchy, Bootstrap 5 components only, responsive on mobile,
accessible (labels, contrast, focus states). Don't change any business logic.
```

**Resume after a break**
```
Read CLAUDE.md and docs/DTS_SPEC.md, then inspect the codebase and git log. Tell me
which phases (spec §13) are complete, partially done, or not started, and what you
recommend doing next.
```
