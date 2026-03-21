# Testing database isolation

## What went wrong

`RefreshDatabase` (used across Feature/Unit tests) runs **`migrate:fresh`** on whatever database Laravel connects to. PHPUnit is supposed to set `DB_DATABASE=testing` via `phpunit.xml`, but:

- Running `phpunit` with a bootstrap that only loads `vendor/autoload.php` can let `.env` win if environment merge order differs.
- Running tests **without** Sail while your `.env` points MySQL at `laravel` can wipe **`laravel`** if `DB_DATABASE` is not forced to `testing`.

## Protections in this repo

1. **`tests/bootstrap.php`** — PHPUnit bootstrap only; **always** sets `DB_DATABASE=testing` before `vendor/autoload.php`, so `.env` cannot point `RefreshDatabase` at your dev database.
2. **`phpunit.xml`** — Uses `tests/bootstrap.php` and sets `APP_ENV=testing` and `DB_DATABASE=testing`.
3. **`.env.testing`** — Documents `DB_DATABASE=testing` for `php artisan` with `--env=testing`.
4. **`Tests\TestCase`** — After the app boots, throws if the resolved database name is not an allowed test database (catches misconfiguration; `tests/bootstrap.php` is the main prevention).

## Safe commands

| Goal | Command |
|------|---------|
| Refresh **only** the test DB | `./scripts/test-db-fresh.sh` |
| Run tests (Sail) | `./vendor/bin/sail test` or `./vendor/bin/sail exec laravel.test php artisan test` |
| Wipe **dev** DB and seed (explicit) | `./vendor/bin/sail artisan migrate:fresh --seed` — **only when you intend to destroy dev data** |

## For agents / CI

- Do **not** run `php vendor/bin/phpunit` on the host with system PHP if Sail is the intended environment — use `./vendor/bin/sail test`.
- Do **not** run `migrate:fresh` without confirming which `DB_DATABASE` is active.
