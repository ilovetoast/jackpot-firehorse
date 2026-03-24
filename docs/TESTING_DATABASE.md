# Testing database isolation

## What went wrong

`RefreshDatabase` (used across Feature/Unit tests) runs **`migrate:fresh`** on whatever database Laravel connects to. PHPUnit is supposed to set `DB_DATABASE=testing` via `phpunit.xml`, but:

- Running `phpunit` with a bootstrap that only loads `vendor/autoload.php` can let `.env` win if environment merge order differs.
- Running tests **without** Sail while your `.env` points MySQL at `laravel` can wipe **`laravel`** if `DB_DATABASE` is not forced to `testing`.
- **`php artisan config:cache`** bakes the current `DB_DATABASE` into `bootstrap/cache/config.php`. If that was your dev database, **PHPUnit env vars no longer override it** — Laravel loads the cached config and tests run `migrate:fresh` against **the wrong database**. This repo deletes that cache file at the start of `tests/bootstrap.php` so tests always resolve DB from env + `config/*.php`.
- **Order bug (fixed):** `Tests\TestCase` used to call `assertTestDatabaseIsIsolated()` **after** `parent::setUp()`, which runs `RefreshDatabase` first — the check ran **after** the wipe. The assertion now runs in `setUpTraits()` **before** `RefreshDatabase` executes.
- **Fail-open bug (fixed):** The guard used to `return` when `APP_ENV !== 'testing'`, which **skipped** the database-name check while `RefreshDatabase` still ran. That could wipe your dev DB if `.env` had `DB_DATABASE=laravel` (or similar). It now **throws** instead of returning quietly.

## Protections in this repo

1. **`tests/bootstrap.php`** — Sets `DB_DATABASE=testing` before `vendor/autoload.php`, and **removes `bootstrap/cache/config.php`** if present so a stale config cache cannot point tests at your dev DB.
2. **`phpunit.xml`** — Uses `tests/bootstrap.php` and sets `APP_ENV=testing` and `DB_DATABASE=testing`.
3. **`.env.testing`** — Documents `DB_DATABASE=testing` for `php artisan` with `--env=testing`.
4. **`Tests\TestCase`** — Before `RefreshDatabase` runs, throws if the resolved database name is not an allowed test database.
5. **`composer test`** — Runs `php artisan config:clear` before `php artisan test` (extra safety when not using PHPUnit bootstrap alone).

## Safe commands

| Goal | Command |
|------|---------|
| Refresh **only** the test DB | `./scripts/test-db-fresh.sh` |
| Run tests (Sail) | `./vendor/bin/sail test` or `./vendor/bin/sail exec laravel.test php artisan test` |
| Wipe **dev** DB and seed (explicit) | `./vendor/bin/sail artisan migrate:fresh --seed` — **only when you intend to destroy dev data** |

## For agents / CI

- Do **not** run `php vendor/bin/phpunit` on the host with system PHP if Sail is the intended environment — use `./vendor/bin/sail test`.
- Do **not** run PHPUnit from a directory where `phpunit.xml` is not found (PHPUnit will not load `tests/bootstrap.php` or `phpunit.xml` env vars). Prefer `cd jackpot` first, or pass `-c /path/to/phpunit.xml`.
- When using `docker compose exec`, set the app working directory if needed: `docker compose exec -w /var/www/html laravel.test ./vendor/bin/phpunit …` so configuration is discovered.
- Do **not** run `migrate:fresh` without confirming which `DB_DATABASE` is active.

### What typically caused a “wiped” dev database

Feature tests that `use RefreshDatabase` (for example `EditorGenerativeImageTest`) call **`migrate:fresh`**. That is safe only when Laravel connects to an isolated DB name (`testing` or `*_testing`). If PHPUnit was started without the project’s `phpunit.xml` / `tests/bootstrap.php`, `.env` could supply `APP_ENV=local` and a real `DB_DATABASE`, and the old guard could skip the name check — resulting in a full wipe of that database.
