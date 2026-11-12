# VVU Document Tracking System (DTS)

Web application for Valley View University that tracks physical and digital documents as they move
between departments. Every document gets a unique tracking number and QR code, every movement is
logged, and the receiving department must acknowledge receipt.

- Full specification: [`docs/DTS_SPEC.md`](docs/DTS_SPEC.md)
- Project rules for contributors and Claude Code: [`CLAUDE.md`](CLAUDE.md)

## Contents

- [Requirements](#requirements)
- [Local setup](#local-setup)
- [Commands](#commands)
- [Deploying to an Ubuntu VPS (CloudPanel / Nginx + PHP 8.3-FPM)](#deploying-to-an-ubuntu-vps-cloudpanel--nginx--php-83-fpm)
- [Project layout](#project-layout)
- [Security](#security)

## Requirements

- PHP 8.3 (8.2 works for local development) with extensions `pdo_mysql`, `mbstring`, `fileinfo`,
  `openssl` and `zip` (DOCX/XLSX upload checks); `gd` is optional
- MySQL 8 (MariaDB 10.4+ from XAMPP also works locally)
- Composer 2

## Local setup

```bash
composer install
cp .env.example .env                      # edit DB credentials if needed
php database/migrate.php --fresh --seed   # creates the database, tables and sample data
php -S localhost:8000 -t public
```

Open <http://localhost:8000> and sign in with one of the test accounts below.

### Test accounts (development only)

There are no passwords. Enter a **username** on the sign-in page and a one-time code is emailed
to the account's address; paste that code to sign in. With `MAIL_TRANSPORT=log` (the default for
local development) the email, including the code, is written to `storage/logs/mail.log`.
Seeded accounts use the reserved `.test` email domain and are **not** created when `APP_ENV=production`.

| Role | Username | Staff ID | Department |
|---|---|---|---|
| Admin | admin | VVU-0001 | ICT |
| HOD | reg.hod | VVU-1001 | Registry (REG) |
| Staff | reg.staff1, reg.staff2 | VVU-1002, VVU-1003 | Registry (REG) |
| HOD | bur.hod | VVU-2001 | Bursary (BUR) |
| Staff | bur.staff1, bur.staff2 | VVU-2002, VVU-2003 | Bursary (BUR) |
| HOD | ict.hod | VVU-3001 | ICT |
| Staff | ict.staff1, ict.staff2 | VVU-3002, VVU-3003 | ICT |

Seeded Ghana holidays include provisional Eid dates; confirm all holiday dates against the official
gazette (Admin → Holidays) before relying on due-date calculations.

### Windows / XAMPP notes

- Use XAMPP's PHP: `C:\xampp\php\php.exe` (add `C:\xampp\php` to `PATH` so `php` works).
- Enable `extension=zip` (and optionally `extension=gd`) in `C:\xampp\php\php.ini`.
- Serving through Apache at `http://localhost/docs-track/` also works: set
  `APP_URL=http://localhost/docs-track` in `.env`. The root `.htaccess` routes every request into
  `public/`, so `.env`, `storage/` and `vendor/` are never served.
- QR codes encode `APP_URL`. To scan labels with a phone, run `php -S 0.0.0.0:8000 -t public`, set
  `APP_URL` to your PC's network address, and use "scan a photo of the label" (the live camera
  needs HTTPS).

### Email

Emails are queued in `email_queue` and delivered by `cron/send_emails.php`.

- **Local, no SMTP:** `MAIL_TRANSPORT=log` writes emails to `storage/logs/mail.log`.
- **Local with a test inbox (Mailtrap, Mailpit…):** `MAIL_TRANSPORT=smtp` plus the sandbox's
  `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`.
- Admin → Settings → **Send test email** sends immediately and shows any SMTP error.

## Commands

| Task | Command |
|---|---|
| Install dependencies | `composer install` (production: `composer install --no-dev --optimize-autoloader`) |
| Dev server | `php -S localhost:8000 -t public` |
| Run tests | `vendor/bin/phpunit` (integration tests rebuild a separate `vvu_dts_test` database; override with `DB_TEST_*` env vars; skipped if MySQL is down) |
| Run pending migrations | `php database/migrate.php` |
| Rebuild DB + seed data | `php database/migrate.php --fresh --seed` (refused in production without `--force`) |
| Seed reference data (safe to repeat) | `php database/migrate.php --seed` |
| Create the first administrator | `php database/create_admin.php --email=… --name="…" --staff-id=… --department=ICT` |
| Send queued emails (every 2 min) | `php cron/send_emails.php` |
| Unreceived / overdue alerts (every 30 min) | `php cron/check_alerts.php` |
| Daily backup | `deploy/backup.sh` |

## Deploying to an Ubuntu VPS (CloudPanel / Nginx + PHP 8.3-FPM)

Ready-made files are in [`deploy/`](deploy/):

| File | Purpose |
|---|---|
| [`deploy/nginx-dts.conf`](deploy/nginx-dts.conf) | Nginx server block (root = `public/`, front controller, upload limit, Cloudflare real IP) |
| [`deploy/php-production.ini`](deploy/php-production.ini) | PHP-FPM settings (upload sizes, no displayed errors, OPcache) |
| [`deploy/env.production.example`](deploy/env.production.example) | Production `.env` template |
| [`deploy/crontab.txt`](deploy/crontab.txt) | Cron entries (emails, alerts, backup) |
| [`deploy/backup.sh`](deploy/backup.sh) | Daily database dump + uploads archive, 14-day retention |

### 1. Server and site

1. Ubuntu 22.04/24.04 with CloudPanel, or Nginx + `php8.3-fpm` + MySQL 8. PHP extensions:
   `php8.3-mysql php8.3-mbstring php8.3-zip php8.3-xml php8.3-curl php8.3-gd` (fileinfo/openssl are built in).
2. Set the server clock to Ghana time: `sudo timedatectl set-timezone Africa/Accra`.
3. **CloudPanel:** *Add Site → Create a PHP Site*, PHP 8.3, domain e.g. `dts.vvu.edu.gh`. In
   *Settings*, set **Root Directory** to `htdocs/dts.vvu.edu.gh/public`. In *Vhost*, replace the PHP
   location block with the `location` blocks from `deploy/nginx-dts.conf` (keep CloudPanel's
   `fastcgi_pass` port). In *PHP Settings*, apply the values in `deploy/php-production.ini`.
   **Plain Nginx:** copy `deploy/nginx-dts.conf` to `/etc/nginx/sites-available/`, fix paths and
   `server_name`, enable it, `sudo nginx -t && sudo systemctl reload nginx`.
4. Create a MySQL database and a dedicated user (CloudPanel: *Databases*):
   ```sql
   CREATE DATABASE vvu_dts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'vvu_dts'@'localhost' IDENTIFIED BY '<long random password>';
   GRANT ALL PRIVILEGES ON vvu_dts.* TO 'vvu_dts'@'localhost';
   ```
   For good performance give InnoDB enough memory (`innodb_buffer_pool_size` of 256M or more);
   with 50,000 documents the main pages then respond in well under half a second.

### 2. Code, configuration and permissions

Run as the site user (CloudPanel: `su - <site-user>`):

```bash
cd ~/htdocs/dts.vvu.edu.gh
git clone https://github.com/eben2468/docs-track.git .
composer install --no-dev --optimize-autoloader

cp deploy/env.production.example .env
nano .env                                 # APP_URL=https://…, DB_*, MAIL_* (APP_DEBUG=false, APP_ENV=production)

chmod 640 .env
chmod -R u=rwX,g=rX,o= storage            # app writes uploads, logs and cache here
chmod 750 deploy/backup.sh
```

The PHP-FPM pool must run as the user that owns `storage/` (CloudPanel does this by default).
Nothing outside `public/` is reachable from the web.

**Production `.env` essentials:** `APP_ENV=production` and `APP_DEBUG=false` (errors are logged to
`storage/logs/app.log`, visitors see a friendly page); `APP_URL` with `https://` (used in QR codes
and email links, and makes the session cookie `Secure` with the `__Host-` prefix); real SMTP
credentials with `MAIL_TRANSPORT=smtp`.

### 3. Database and first administrator

```bash
php database/migrate.php --seed           # tables + departments, document types, settings, holidays (no test users in production)
php database/create_admin.php --username=it.admin --email=it.admin@vvu.edu.gh --name="ICT Administrator" \
    --staff-id=VVU-0001 --department=ICT --department-name="ICT Directorate"
```

Email must already work (step 2): sign-in codes are emailed. Then sign in with the username, review **Admin → Departments** (the seeded
list is a sample), set heads of department, import staff with **Admin → Users → Import CSV**, and
check **Admin → Holidays** and **Admin → Settings** (logo, colour, test email).

### 4. HTTPS

- **Let's Encrypt:** CloudPanel *SSL/TLS → New Let's Encrypt Certificate*, or
  `sudo certbot --nginx -d dts.vvu.edu.gh`. Renewal is automatic.
- **Cloudflare:** proxy the DNS record, set SSL mode to **Full (strict)** with a Cloudflare Origin
  Certificate (or Let's Encrypt) on the server, and enable the `set_real_ip_from` /
  `real_ip_header CF-Connecting-IP` lines in the Nginx config, otherwise every visitor appears to
  come from a Cloudflare address and the login/tracking rate limits would be shared.
- `fastcgi_param HTTPS on` in the Nginx config tells PHP the site is HTTPS (HSTS header, secure cookie).

### 5. Scheduled jobs and backups

Add the entries from [`deploy/crontab.txt`](deploy/crontab.txt) (CloudPanel: *Cron Jobs*):

```
*/2 * * * *  cd /home/vvu-dts/htdocs/dts.vvu.edu.gh && php8.3 cron/send_emails.php  >> storage/logs/cron.log 2>&1
*/30 * * * * cd /home/vvu-dts/htdocs/dts.vvu.edu.gh && php8.3 cron/check_alerts.php >> storage/logs/cron.log 2>&1
15 2 * * *   /home/vvu-dts/htdocs/dts.vvu.edu.gh/deploy/backup.sh >> /home/vvu-dts/htdocs/dts.vvu.edu.gh/storage/logs/backup.log 2>&1
```

`backup.sh` writes `~/backups/db/vvu_dts-YYYYmmdd-HHMM.sql.gz` and `~/backups/uploads/…tar.gz`,
deleting files older than 14 days. Copy `~/backups` off the server as well (rclone/rsync to another
machine, or provider snapshots). Restore: `gunzip -c file.sql.gz | mysql vvu_dts` and
`tar -xzf uploads-….tar.gz -C storage/`.

### 6. Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
php database/migrate.php                  # applies new migrations only
```

### Go-live checklist

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`; `.env` is `640`
- [ ] `https://…/.env`, `/storage/…`, `/vendor/…` and `/cron/…` all return 404
- [ ] Sign-in works; the session cookie is named `__Host-vvu_dts_session` and marked Secure
- [ ] Test email from Admin → Settings arrives; `cron.log` shows "Sent N" every 2 minutes
- [ ] `check_alerts.php` runs every 30 minutes; a backup file appears the next morning
- [ ] A QR label scanned with a phone opens the document over HTTPS (live camera works)
- [ ] The default seeded departments/holidays have been reviewed by the University

## Project layout

```
public/        Web root: front controller (index.php) and static assets
app/           Controllers, Middleware, Models, Services, Policies, Views, Helpers, routes.php
core/          Framework: App, Router, Request, Response, Database, Session, Csrf, Validator, View, ...
config/        app.php, database.php, mail.php (values read from .env)
database/      SQL migrations, seeds, migrate.php and create_admin.php
cron/          Scheduled jobs (send_emails.php, check_alerts.php)
deploy/        Nginx, PHP, cron and backup files for the production server
storage/       uploads/ (private), logs/, cache/
tests/         PHPUnit unit and integration tests
```

`public/index.php` → `Core\App` loads `.env` + config, installs error handling, starts the session
→ `Core\Router` matches the route → middleware (`csrf`, `auth`, `role:admin`) → controller →
service (rules, transaction, policy, audit, notifications) → model (PDO) → `Core\View`.

## Security

- PDO prepared statements only, emulated prepares off; SQL identifiers and sort columns whitelisted
- All view output escaped with `e()`; strict Content-Security-Policy (no inline script or style)
- CSRF token on every POST (419 page on mismatch); oversized uploads get a clear 413 page
- Sessions: `HttpOnly`, `SameSite=Lax`, `Secure` + `__Host-` prefix over HTTPS, new ID on sign-in,
  30-minute idle timeout;
  deactivated users are signed out immediately; signed-in pages are sent `Cache-Control: no-store`
- Passwordless sign-in: accounts are created by an administrator; users enter their username and a
  one-time code (6 characters: upper- and lowercase letters, digits and sometimes a symbol; case-sensitive) emailed to them.
  Codes expire after 10 minutes, work once, only the newest is valid, and only a SHA-256 hash is stored.
  The username step never reveals whether an account exists. 5 wrong codes lock an account for 15
  minutes; at most 5 codes per account per 15 minutes; per-IP limits on sign-in, code requests and
  public tracking
- Every document action re-checks `DocumentPolicy` on the server inside a locked transaction
- Uploads: extension whitelist + `finfo` content check (+ DOCX/XLSX structure check), random names
  in `storage/uploads` outside the web root, downloads only through a permission-checked controller
- Audit log of sign-ins, document actions, attachment access and all admin changes; no edit or
  delete anywhere (Admin → Audit logs)
- `APP_DEBUG=false` in production: errors are logged, visitors see a friendly page
