# VVU Document Tracking System (DTS)

Web application for Valley View University that tracks physical and digital documents as they move
between departments. Every document gets a unique tracking number and QR code, every movement is
logged, and the receiving department must acknowledge receipt.

- Full specification: [`docs/DTS_SPEC.md`](docs/DTS_SPEC.md)
- Project rules for contributors and Claude Code: [`CLAUDE.md`](CLAUDE.md)

## Requirements

- PHP 8.3 (8.2 works for local development) with extensions: `pdo_mysql`, `mbstring`, `fileinfo`,
  `openssl`, and `gd` (needed later for PNG QR codes)
- MySQL 8 (MariaDB 10.4+ from XAMPP also works locally)
- Composer 2

## Local setup

```bash
# 1. Install PHP dependencies
composer install

# 2. Create your environment file and edit DB credentials if needed
cp .env.example .env

# 3. Create the database (utf8mb4)
mysql -u root -e "CREATE DATABASE IF NOT EXISTS vvu_dts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 4. Start the development server
php -S localhost:8000 -t public
```

Then build the schema and load sample data (creates the database if it does not exist):

```bash
php database/migrate.php --fresh --seed
```

Open <http://localhost:8000>. The temporary home page shows a foundation check: PHP version,
environment, timezone and database connection.

### Test accounts (development only)

All seeded accounts use the password **`ChangeMe@123`** and the reserved `.test` email domain.

| Role | Email | Staff ID | Department |
|---|---|---|---|
| Admin | admin@vvu.test | VVU-0001 | ICT |
| HOD | reg.hod@vvu.test | VVU-1001 | Registry (REG) |
| Staff | reg.staff1@vvu.test, reg.staff2@vvu.test | VVU-1002, VVU-1003 | Registry (REG) |
| HOD | bur.hod@vvu.test | VVU-2001 | Bursary (BUR) |
| Staff | bur.staff1@vvu.test, bur.staff2@vvu.test | VVU-2002, VVU-2003 | Bursary (BUR) |
| HOD | ict.hod@vvu.test | VVU-3001 | ICT |
| Staff | ict.staff1@vvu.test, ict.staff2@vvu.test | VVU-3002, VVU-3003 | ICT |

Seeded Ghana holidays include provisional Eid dates; confirm all holiday dates against the official
gazette (Admin → Holidays) before relying on due-date calculations.

### Windows / XAMPP notes

- Use XAMPP's PHP: `C:\xampp\php\php.exe` (add `C:\xampp\php` to `PATH` so `php` works).
- Enable `extension=gd` and `extension=zip` in `C:\xampp\php\php.ini` (remove the leading `;`).
- Serving through Apache at `http://localhost/docs-track/` also works: set
  `APP_URL=http://localhost/docs-track` in `.env`. The root `.htaccess` routes every request into
  `public/`, so `.env`, `storage/` and `vendor/` are never served.

### Email

Emails are queued in `email_queue` and delivered by `cron/send_emails.php`.

- **Local, no SMTP:** set `MAIL_TRANSPORT=log` in `.env`; emails are written to `storage/logs/mail.log`.
- **Local with a test inbox (Mailtrap or similar):** set `MAIL_TRANSPORT=smtp` and the sandbox's
  `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION=tls`.
- Run the sender by hand with `php cron/send_emails.php`, or check settings with
  Admin → Settings → **Send test email** (sent immediately, not queued).

## Commands

| Task | Command |
|---|---|
| Install dependencies | `composer install` |
| Dev server | `php -S localhost:8000 -t public` |
| Run tests | `vendor/bin/phpunit` (integration tests create and rebuild a separate `vvu_dts_test` database; override with `DB_TEST_*` env vars; skipped if MySQL is down) |
| Run pending migrations | `php database/migrate.php` |
| Rebuild DB + seed data | `php database/migrate.php --fresh --seed` (refused in production without `--force`) |
| Seed only (safe to repeat) | `php database/migrate.php --seed` |
| Send queued emails (every 2 min) | `php cron/send_emails.php` |
| Alerts (Phase 7) | `php cron/check_alerts.php` |

## Project layout

```
public/        Web root: front controller (index.php) and static assets
app/           Controllers, Middleware, Models, Services, Policies, Views, Helpers, routes.php
core/          Framework: App, Router, Request, Response, Database, Session, Csrf, Validator, View, ...
config/        app.php, database.php, mail.php (values read from .env)
database/      SQL migrations, seeds and the migrate.php runner
cron/          Scheduled jobs
storage/       uploads/ (private), logs/app.log, cache/
tests/         PHPUnit tests
```

### Request flow

`public/index.php` → `Core\App` loads `.env` + config, installs error handling, starts the session
→ `Core\Router` matches the route → middleware chain (e.g. `csrf`) → controller → service → model
→ `Core\View` renders the template inside a layout.

### Adding a route

Routes live in `app/routes.php`:

```php
$router->group(['middleware' => ['web']], function (Router $router) {
    $router->get('/documents/{id:\d+}', [DocumentController::class, 'show']);
    $router->post('/documents/{id:\d+}/send', [DocumentController::class, 'send']);
});
```

Handlers receive the `Request` followed by route parameters and return a `Response` (or an HTML string).
The `web` middleware group verifies the CSRF token on every POST, so every form must include
`<?= csrf_field() ?>`.

## Security defaults

- PDO prepared statements only, emulated prepares off
- All view output escaped with `e()`
- CSRF token on every POST (419 page on mismatch)
- Session cookies are `HttpOnly` and `SameSite=Lax`, `Secure` in production, with an idle timeout
- Security headers: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
  `Content-Security-Policy` (scripts and styles from `'self'` only, so no inline JS or CSS)
- `APP_DEBUG=false` in production: errors go to `storage/logs/app.log` and users see a friendly page
