# Testing guide

This project uses **PHPUnit** (not Pest). Tests live under `tests/Unit` and `tests/Feature`; both boot Laravel when extending `Tests\TestCase`. A small number of **pure unit** tests extend `PHPUnit\Framework\TestCase` only (no application container).

See also **[TESTING_DATABASE.md](./TESTING_DATABASE.md)** for `RefreshDatabase` safety and `DB_DATABASE=testing`.

---

## Test types

| Location | Purpose |
|----------|---------|
| `tests/Feature` | HTTP, full stack, queues, policies, DB persistence, job `handle()` integration |
| `tests/Unit` | **Mostly** Laravel-backed: services with `RefreshDatabase`, mocks, or reflection smoke tests |
| `tests/Unit/...` + `PHPUnit\Framework\TestCase` | **True unit**: pure functions / enums with no DB and no container (e.g. `VideoInsightsJobPreflightTest`) |

**Convention:** If a test uses `RefreshDatabase`, touches Eloquent, or asserts side effects on the database, treat it as **integration** and prefer `tests/Feature` (subfolders like `Feature/Jobs/` are fine). Use `@group integration` and `@group database` on those classes.

**Anti-patterns**

- Calling `$job->handle(...)` with real models in `tests/Unit` without moving the test to Feature (or extracting pure logic first).
- Using full factories when three `Model::create([...])` columns suffice.
- Sleeping or time-based assertions (prefer `Bus::fake()`, `Queue::fake()`, deterministic callbacks).

---

## Audit summary (high level)

- **~40+ files under `tests/Unit` use `RefreshDatabase`.** They are integration-style even if historically named “Unit”. Migrating them all is out of scope; prefer **new** tests in Feature when adding coverage.
- **Slow / environment-heavy:** `VideoPreviewGenerationServiceTest` runs FFmpeg and temp files — tagged `@group ffmpeg`. Exclude during quick loops: `--exclude-group=ffmpeg`.
- **GenerateVideoInsightsJob:** Early exit rules are implemented in `App\Support\VideoInsights\VideoInsightsJobPreflight` (fast, no Laravel). Persistence and `handle()` wiring are covered in `tests/Feature/Jobs/GenerateVideoInsightsJobTest.php`.
- **True unit examples:** `VideoDisplayProbeTest`, `ThumbnailMetadataTest`, many `Support/` tests without DB — still boot Laravel via `Tests\TestCase` unless switched to `PHPUnit\Framework\TestCase` (optional future win).

---

## Running tests

Always run from the Laravel app root (`jackpot/`) so `phpunit.xml` and `tests/bootstrap.php` apply.

### Host (PHP 8.2+)

```bash
composer test
composer test:unit
composer test:unit:fast    # excludes @group ffmpeg
composer test:feature
composer test:preflight    # fastest: pure preflight tests only
./vendor/bin/phpunit tests/Feature/Jobs/GenerateVideoInsightsJobTest.php
./vendor/bin/phpunit --filter test_evaluate tests/Unit/Support/VideoInsights/VideoInsightsJobPreflightTest.php
```

### Laravel Sail (recommended when the app runs in Docker)

```bash
./vendor/bin/sail test
./vendor/bin/sail composer test:unit:fast
./vendor/bin/sail exec laravel.test php vendor/bin/phpunit --testsuite=Unit --exclude-group=ffmpeg
./vendor/bin/sail exec laravel.test php vendor/bin/phpunit --testsuite=Feature
./vendor/bin/sail exec laravel.test php vendor/bin/phpunit tests/Feature/Jobs/GenerateVideoInsightsJobTest.php
```

Use `-w /var/www/html` (or your `APP_SERVICE` workdir) if `exec` defaults to the wrong directory.

### Xdebug and speed

Xdebug can slow PHPUnit by a large factor. For a quick run inside Sail:

```bash
XDEBUG_MODE=off ./vendor/bin/sail exec laravel.test php vendor/bin/phpunit --testsuite=Unit --exclude-group=ffmpeg
```

Or disable the debug extension in the container for that shell if your image enables it by default.

---

## Fakes commonly used in this codebase

- `Storage::fake('s3')` or `Storage::fake()` for uploads and derivatives.
- `Queue::fake()`, `Bus::fake()`, `Event::fake()` to avoid accidental real dispatch.
- `Http::fake()` for outbound API clients.
- `$this->mock(SomeService::class, ...)` / Mockery for isolating jobs from AI or FFmpeg.

Prefer **integration** assertions on real DB state when the behavior under test *is* persistence (e.g. job metadata updates).

---

## PHPUnit groups

| Group | Meaning |
|-------|---------|
| `ffmpeg` | Needs FFmpeg / encoders (skip locally if not installed). |
| `integration` | Full stack / job + DB (documentary; use with filters if you adopt CI matrices). |
| `database` | Uses `RefreshDatabase` / DB. |
| `pure-unit` | Extends `PHPUnit\Framework\TestCase`; no Laravel app. |
| `jobs` | Job-focused integration tests. |

Example:

```bash
php vendor/bin/phpunit --testsuite=Unit --exclude-group=ffmpeg
php vendor/bin/phpunit --group pure-unit
```
