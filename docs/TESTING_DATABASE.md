# Testing database isolation

## What went wrong

`RefreshDatabase` (used across Feature/Unit tests) runs **`migrate:fresh`** on whatever database Laravel connects to. PHPUnit is supposed to set `DB_DATABASE=testing` via `phpunit.xml`, but:

- Running `phpunit` with a bootstrap that only loads `vendor/autoload.php` can let `.env` win if environment merge order differs.
- Running tests **without** Sail while your `.env` points MySQL at `laravel` can wipe **`laravel`** if `DB_DATABASE` is not forced to `testing`.
- **`php artisan config:cache`** bakes the current `DB_DATABASE` into `bootstrap/cache/config.php`. If that was your dev database, **PHPUnit env vars no longer override it** — Laravel loads the cached config and tests run `migrate:fresh` against **the wrong database**. This repo deletes that cache file at the start of `tests/bootstrap.php` so tests always resolve DB from env + `config/*.php`.
- **Order bug (fixed):** `Tests\TestCase` used to call `assertTestDatabaseIsIsolated()` **after** `parent::setUp()`, which runs `RefreshDatabase` first — the check ran **after** the wipe. The assertion now runs in `setUpTraits()` **before** `RefreshDatabase` executes.
- **Fail-open bug (fixed):** The guard used to `return` when `APP_ENV !== 'testing'`, which **skipped** the database-name check while `RefreshDatabase` still ran. That could wipe your dev DB if `.env` had `DB_DATABASE=laravel` (or similar). It now **throws** instead of returning quietly.

## Protections in this repo

1. **`tests/bootstrap.php`** — Sets `APP_ENV=testing`, `APP_RUNNING_UNIT_TESTS=1`, and `DB_DATABASE=testing` **before** `vendor/autoload.php`. Removes **`bootstrap/cache/config.php`** and **`bootstrap/cache/routes*.php`** so stale caches cannot freeze a primary `DB_DATABASE` or route config during PHPUnit.
2. **`phpunit.xml`** — Uses `tests/bootstrap.php` and sets `APP_ENV=testing` and `DB_DATABASE=testing`.
3. **`.env.testing`** — Documents `DB_DATABASE=testing` for `php artisan` with `--env=testing`.
4. **`Tests\TestCase`** — Before `RefreshDatabase` runs: requires `APP_ENV=testing`; checks the resolved connection against **`App\Support\Database\TestDatabaseSchema`**; verifies `$_SERVER['DB_DATABASE']` matches Laravel’s resolved database name; for MySQL/MariaDB/PostgreSQL runs a **live** `SELECT DATABASE()` / `current_database()` and refuses if it disagrees with config (catches wrong default schema on the server).
5. **`App\Console\Listeners\BlockUnsafeDestructiveDatabaseCommands`** — When **not** `APP_ENV=testing`, blocks **`migrate:fresh`**, **`migrate:refresh`**, and **`db:wipe`** unless the target connection’s database name is a permitted sandbox **or** `ALLOW_DATABASE_DESTRUCTION=true` **or** the name is listed in **`DESTRUCTIVE_ALLOWED_DATABASES`** (comma-separated). This stops accidental `php artisan migrate:fresh` against a primary dev DB.
6. **`composer test`** — Runs `php artisan config:clear` before `php artisan test` (extra safety when not using PHPUnit bootstrap alone).

### Naming rule

If your **real** application data lives in a MySQL database named **`testing`**, PHPUnit’s default allowlist would treat it as safe. **Use a different name for production/dev primary data** (e.g. `jackpot`, `laravel`, `app_prod`) and keep **`testing`** (or `*_testing`) for disposable test schemas only.

## Safe commands

| Goal | Command |
|------|---------|
| Refresh **only** the test DB | `./scripts/test-db-fresh.sh` |
| Run tests (Sail) | `./vendor/bin/sail test` or `./vendor/bin/sail exec laravel.test php artisan test` |
| Wipe **dev** DB and seed (explicit) | Set `ALLOW_DATABASE_DESTRUCTION=true` then `./vendor/bin/sail artisan migrate:fresh --seed` — **only when you intend to destroy dev data** (destructive commands are blocked for non-sandbox DB names by default) |

## For agents / CI

- Do **not** run `php vendor/bin/phpunit` on the host with system PHP if Sail is the intended environment — use `./vendor/bin/sail test`.
- Do **not** run PHPUnit from a directory where `phpunit.xml` is not found (PHPUnit will not load `tests/bootstrap.php` or `phpunit.xml` env vars). Prefer `cd jackpot` first, or pass `-c /path/to/phpunit.xml`.
- When using `docker compose exec`, set the app working directory if needed: `docker compose exec -w /var/www/html laravel.test ./vendor/bin/phpunit …` so configuration is discovered.
- Do **not** run `migrate:fresh` without confirming which `DB_DATABASE` is active.

### What typically caused a “wiped” dev database

Feature tests that `use RefreshDatabase` (for example `EditorGenerativeImageTest`) call **`migrate:fresh`**. That is safe only when Laravel connects to an isolated DB name (`testing` or `*_testing`). If PHPUnit was started without the project’s `phpunit.xml` / `tests/bootstrap.php`, `.env` could supply `APP_ENV=local` and a real `DB_DATABASE`, and the old guard could skip the name check — resulting in a full wipe of that database.
