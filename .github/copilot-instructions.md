# Copilot instructions for Qarota

## Project overview

Qarota is a PHP social networking application built as a server-rendered monolith rather than a framework app. The public entry points are top-level PHP files such as `index.php`, `feed.php`, `messages.php`, `profile.php`, and `topics.php`. Shared layout and navigation are centralized in `includes/header.php` and `includes/footer.php`, while database connectivity is centralized in `config/db.php`.

The project is intentionally multi-language and privacy-focused: translations live in `lang/*.php`, media uploads live under `uploads/`, and the app exposes public moderation/privacy/export flows rather than a standard CRUD-only backend.

## Build, test, and lint commands

There is no framework-specific build pipeline, npm script, Composer script, PHPUnit setup, or ESLint/PHPCS configuration in this repository.

Use the following commands when validating changes:

- Single-file PHP syntax check (use `php` if it is on PATH; otherwise use the XAMPP binary):
  `php -l path/to/file.php`
  `C:\xampp\php\php.exe -l path/to/file.php`
- Syntax-check all PHP files in the repo (PowerShell):
  `Get-ChildItem -Recurse -Filter *.php | ForEach-Object { php -l $_.FullName }`
  `Get-ChildItem -Recurse -Filter *.php | ForEach-Object { & 'C:\xampp\php\php.exe' -l $_.FullName }`
- Run the app locally with PHP's built-in server (if you are not using Apache/XAMPP):
  `php -S 127.0.0.1:8000`
- For full local browsing, serve the repo root via Apache/XAMPP and open the app in a browser using the project URL.

Do not assume there is a project test suite to run. If you need verification beyond syntax checks, prefer a targeted manual browser check of the affected page or feature.

## High-level architecture

- `index.php` is the landing page for non-authenticated visitors; logged-in users are redirected to `feed.php`.
- `feed.php`, `discover.php`, `profile.php`, `messages.php`, `notifications.php`, `topics.php`, and similar files are page-level controllers that render HTML directly.
- Shared application helpers live under `includes/`:
  - `session.php` defines `start_secure_session()` and session hardening; use this instead of raw `session_start()`.
  - `csrf.php` provides `csrf_field()` and `csrf_verify()` for state-changing requests.
  - `mailer.php`, `messages.php`, `rate_limit.php`, `security_log.php`, and related helpers hold the app-specific logic that is shared across page scripts.
- `config/db.php` creates the PDO connection to MySQL. Most pages require it before running queries.
- `lang/init.php` loads the active locale (`en`, `tw`, `fr`, `dg`) and exposes the `__()` translation helper. Prefer using translation keys over raw English strings when adding new UI text.
- Static frontend assets live under `assets/` and are included via the shared header/footer. User-generated media is stored under `uploads/` and is intentionally allowed via `.htaccess` while executable file types are blocked.
- `.htaccess` is part of the application security model. It blocks access to sensitive directories such as `config/`, `includes/`, `lang/`, `.git/`, and other developer-only paths, while allowing public media files.

## Key conventions

- The project uses direct PHP files and includes rather than a framework router or component library. When fixing or extending a feature, look for the matching page file plus any shared helper it calls.
- Most pages follow the same bootstrap pattern:
  1. `require_once __DIR__ . '/includes/session.php';`
  2. `start_secure_session();`
  3. `require 'config/db.php';`
  4. `require 'lang/init.php';`
  5. `require 'includes/csrf.php';` for POST handlers
  6. Render `$page_title` and include `includes/header.php` / `includes/footer.php`
- Use prepared statements for database writes and reads. The codebase is explicitly using PDO with bound parameters rather than interpolated SQL.
- Preserve the app's auth pattern: access-control checks typically rely on `$_SESSION['user_id']`, and redirecting logged-out users away from protected screens is expected.
- State-changing form submissions should use `csrf_field()` in the form and `csrf_verify()` at the top of the handler before any mutation happens.
- Respect existing naming/URL conventions. Pages are named after user-facing features (for example, `feed.php`, `profile.php`, `topics.php`, `notifications.php`, `messages.php`), and new work should fit that style rather than introducing a new architecture.
- Keep changes localized to the app's page-based structure. Do not introduce framework code or a new app structure when a direct page/helper extension will fit the project.
- Preserve the product's primary identity: multilingual, privacy-forward, and moderation-transparent. The project is not a generic social app template; it has explicit Ghana/Africa positioning and public moderation/export flows.

## Working style for this repo

- Prefer matching the existing SQLite-less/PDO MySQL style and not introducing new dependency management or build tooling.
- Keep form handling and session logic consistent with existing code paths; do not bypass `start_secure_session()` or `csrf_verify()`.
- When adding user-visible strings, prefer the existing `lang/*.php` translation files and the `__()` helper rather than hard-coded English in new code.
- When touching file uploads or public media, keep the existing security assumptions in place: validate MIME, store uploads under the existing public paths, and avoid broadening executable file access.
