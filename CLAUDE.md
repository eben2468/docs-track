# CLAUDE.md — VVU Document Tracking System

This file gives Claude Code standing instructions for this project. The full specification is in **`docs/DTS_SPEC.md`**. It is the source of truth; read the relevant section before building any feature.

## Project
Web app for Valley View University that tracks physical/digital documents as they move between departments. Each document gets a unique tracking number + QR code, every movement is logged, and the receiving department must acknowledge receipt.

## Stack (do not change without asking)
- PHP 8.3, **no framework**: custom lightweight MVC (see spec §9)
- MySQL 8 (InnoDB, utf8mb4_unicode_ci), PDO only
- Bootstrap 5.3 + Bootstrap Icons + vanilla JS (no jQuery, no Node build step)
- Composer: vlucas/phpdotenv, phpmailer/phpmailer, endroid/qr-code, phpunit (dev)
- Production: Ubuntu VPS, Nginx + PHP-FPM (CloudPanel), HTTPS
- Timezone: Africa/Accra

## Commands
- Install: `composer install`
- Dev server: `php -S localhost:8000 -t public`
- Migrate: `php database/migrate.php` · fresh + seed: `php database/migrate.php --fresh --seed`
- Tests: `vendor/bin/phpunit`
- Cron (manual run): `php cron/check_alerts.php`, `php cron/send_emails.php`

## Coding rules
- `declare(strict_types=1);` in every PHP class file. PSR-4 autoload (`App\` → `app/`, `Core\` → `core/`). PSR-12 style.
- **Controllers stay thin.** Business rules live in `app/Services`, permission checks in `app/Policies`, SQL in `app/Models`.
- **SQL:** prepared statements only, never string-interpolate input. Emulated prepares off.
- **Every document state change** goes through `DocumentService`/`MovementService`: wrap it in a transaction, `SELECT ... FOR UPDATE` the document row, check the policy, validate the state transition (spec §4.2), write an `audit_logs` row, and queue notifications.
- **Output:** escape everything in views with `e()`.
- **Forms:** every POST includes `csrf_field()`; validate with `Core\Validator`; repopulate with `old()`; show errors per field.
- **Uploads:** stored in `storage/uploads/` (outside `public/`) under random names, MIME checked with `finfo`, served only via a controller.
- Never delete movement history or audit logs. Deactivate users and departments instead of deleting them.
- No secrets in code. Use `.env` (keep `.env.example` updated when adding keys).
- Keep views simple PHP templates using layouts and partials; no inline SQL in views.
- Use meaningful names, small functions, and docblocks on service methods.

## Working style
- Build **one phase at a time** (spec §13). At the start of each phase, give a short plan (files to create/change); then implement it.
- After each phase: run migrations/tests if relevant, list what was built, how to test it manually, and any assumptions made.
- If the spec is silent or ambiguous, pick the simplest option consistent with it, note the assumption, and continue.
- Don't add features from the Phase 2 backlog (spec §15) unless asked.
- When modifying existing code, don't rewrite unrelated files.
