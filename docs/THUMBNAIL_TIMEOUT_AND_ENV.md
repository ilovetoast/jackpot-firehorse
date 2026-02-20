# Thumbnail Timeout & Environment Configuration

Use this guide when deploying to **staging** and **production** worker servers.

## Running Sail (Laravel root)

From the Laravel project root (e.g. `jackpot/`):

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan horizon
```

## Environment Variables to Update

Add or update these in `.env` on **staging** and **production** worker machines:

### Timeout Hierarchy (Required)

**Rule: Guard ≥ Job ≥ Worker** — prevents false timeouts.

| Variable | Staging/Production | Local (optional) | Description |
|----------|--------------------|------------------|-------------|
| `QUEUE_WORKER_TIMEOUT` | `1800` | `600` | Horizon worker timeout (seconds). Worker kills jobs after this. |
| `THUMBNAIL_JOB_TIMEOUT_SECONDS` | `1800` | `600` | GenerateThumbnailsJob, GeneratePreviewJob, GenerateVideoPreviewJob timeout. |
| `THUMBNAIL_TIMEOUT_MINUTES` | `35` | `5` | ThumbnailTimeoutGuard: mark PROCESSING as FAILED after this many minutes. Must be **greater than** job timeout (35 min > 30 min). |

### TIFF / Large Image Safety

| Variable | Value | Description |
|----------|-------|-------------|
| `THUMBNAIL_MAX_PIXELS` | `200000000` | Max pixel area (width × height). Files exceeding this get degraded mode: preview + thumb only; medium/large skipped. Prevents OOM on 700MB+ TIFFs. |

### Example `.env` (staging worker)

```env
QUEUE_WORKER_TIMEOUT=1800
THUMBNAIL_JOB_TIMEOUT_SECONDS=1800
THUMBNAIL_TIMEOUT_MINUTES=35
THUMBNAIL_MAX_PIXELS=200000000
```

---

## PHP Variables to Update

### memory_limit

Large TIFFs (e.g. 700MB) can cause OOM if `memory_limit` is too low.

| Environment | Recommended | Notes |
|-------------|-------------|-------|
| **Staging worker** | `2G` | Handles large TIFFs without crashing. |
| **Production worker** | `2G` | Same as staging. |
| **Local** | `1G` | Minimum for development. |

### Where to set

- **Sail / Docker**: Override in `docker/8.5/php.ini` or via `php.ini` in the PHP container.
- **System PHP**: In `php.ini` (e.g. `/etc/php/8.x/cli/php.ini` for CLI workers).

```ini
memory_limit = 2G
```

- **Horizon**: Horizon can also set per-worker memory via `config/horizon.php` → `defaults` → `memory`. Default is 128MB; increase if needed for thumbnail workers.

---

## After Updating

1. **Restart Horizon** so workers pick up new env and PHP settings:
   ```bash
   ./vendor/bin/sail artisan horizon:terminate
   # Horizon will restart if supervised (e.g. systemd, supervisor)
   # Or: ./vendor/bin/sail artisan horizon
   ```

2. **Clear config cache** (if using `config:cache` in production):
   ```bash
   ./vendor/bin/sail artisan config:clear
   ./vendor/bin/sail artisan config:cache
   ```

---

## Degraded Thumbnail Mode

When an image exceeds `THUMBNAIL_MAX_PIXELS` (default 200M pixels ≈ 14k×14k):

- **Generated**: `preview` (32px) + `thumb` (320px)
- **Skipped**: `medium` (1024px), `large` (4096px)
- **Metadata**: `thumbnail_quality` = `degraded_large_skipped`

This avoids OOM, Imagick pixel cache overflow, and swap thrashing on very large files.
