# Queue Workers Setup

## Overview

Queue workers process background jobs (thumbnail generation, metadata extraction, etc.). **Queue workers must be running for thumbnail processing to work.**

## Quick Start

### Start Queue Worker (Docker Compose)

The queue worker runs as a dedicated Docker service:

```bash
# Start all services (including queue worker)
./vendor/bin/sail up -d

# Start only the queue worker
./vendor/bin/sail up -d queue

# Verify it's running
./vendor/bin/sail ps | grep queue
```

### Verify Workers Are Running

```bash
# Check Docker services
./vendor/bin/sail ps | grep queue

# Check for stuck jobs
./vendor/bin/sail artisan queue:health-check

# View worker logs
./vendor/bin/sail logs -f queue
```

## Configuration

**Queue Connection:** `database` (configured in `config/queue.php`)

**Worker Settings** (in `compose.yaml`):
- `--tries=3` - Maximum retry attempts per job
- `--timeout=300` - Default job timeout (thumbnail jobs override to 600s)
- `--sleep=3` - Seconds to sleep when no jobs available
- `--max-jobs=1000` - Restart worker after N jobs (prevents memory leaks)
- `--max-time=3600` - Restart worker after 1 hour (prevents memory leaks)

**Auto-restart:** `restart: unless-stopped` - Worker restarts automatically if it crashes

## Common Commands

### Start/Stop

```bash
# Start queue worker
./vendor/bin/sail up -d queue

# Stop queue worker
./vendor/bin/sail stop queue

# Restart queue worker
./vendor/bin/sail restart queue
```

### Manual Job Processing

```bash
# Process one job (for testing/debugging)
./vendor/bin/sail artisan queue:work --once

# Process one job with specific retry count
./vendor/bin/sail artisan queue:work --once --tries=1
```

### Health Check

```bash
# Check for stuck jobs
./vendor/bin/sail artisan queue:health-check

# Check with custom stale threshold (10 minutes)
./vendor/bin/sail artisan queue:health-check --stale-minutes=10

# Warning only (doesn't exit with error)
./vendor/bin/sail artisan queue:health-check --warn-only
```

### View Logs

```bash
# Follow queue worker logs
./vendor/bin/sail logs -f queue

# View last 100 lines
./vendor/bin/sail logs --tail=100 queue
```

## Troubleshooting

### Problem: Thumbnails Never Complete

**Symptoms:**
- AssetProcessingTray shows "Processing (1)" forever
- Thumbnails never appear
- Jobs stuck in queue

**Solution:**

1. **Check if worker is running:**
   ```bash
   ./vendor/bin/sail ps | grep queue
   ```
   If no queue service appears, start it:
   ```bash
   ./vendor/bin/sail up -d queue
   ```

2. **Check for stuck jobs:**
   ```bash
   ./vendor/bin/sail artisan queue:health-check
   ```

3. **If jobs are stuck, manually process them:**
   ```bash
   # Process stuck jobs one at a time
   ./vendor/bin/sail artisan queue:work --once --tries=1
   # Repeat until queue is empty
   ```

4. **Restart the worker:**
   ```bash
   ./vendor/bin/sail restart queue
   ```

### Problem: Worker Keeps Crashing

**Check logs:**
```bash
./vendor/bin/sail logs queue
```

**Common causes:**
- Database connection errors → Ensure MySQL is running
- Memory limits → Check PHP memory_limit in Dockerfile
- Timeout errors → Jobs exceeding timeout (thumbnail jobs: 600s; default: 300s)

### Problem: Jobs Process But Thumbnails Don't Appear

This is a different issue (likely frontend or file storage). Check:
1. S3/storage configuration
2. Thumbnail file paths
3. Frontend polling (if enabled)

## Development Workflow

### Starting Development Session

1. Start Sail services:
   ```bash
   ./vendor/bin/sail up -d
   ```

2. Verify queue worker is running:
   ```bash
   ./vendor/bin/sail ps | grep queue
   ```

3. Check queue health:
   ```bash
   ./vendor/bin/sail artisan queue:health-check
   ```

### During Development

- Queue worker runs continuously in background
- Jobs process automatically
- View logs: `./vendor/bin/sail logs -f queue`
- Health check: `./vendor/bin/sail artisan queue:health-check`

### Stopping Development Session

```bash
# Stop all services (including queue worker)
./vendor/bin/sail down

# Stop only queue worker (keep other services running)
./vendor/bin/sail stop queue
```

## Download ZIP Jobs (Phase D-3)

By default, `BuildDownloadZipJob` runs on the **default** queue so a single `php artisan queue:work` processes download ZIP builds. If downloads stay "Preparing" forever, ensure a queue worker is running (and not only a worker that listens to a different queue).

To use a dedicated queue for download jobs (e.g. for higher memory/timeout), set in `.env`:

```env
QUEUE_DOWNLOADS_QUEUE=downloads
```

Then run a separate worker for the downloads queue with:

- **memory** >= 2048MB
- **concurrency** <= 2
- **timeout** >= 900s (15 min)

Example (Supervisor or similar):

```bash
php artisan queue:work database --queue=downloads --timeout=900 --memory=2048 --tries=3
```

For Docker/Sail, define a dedicated `queue-downloads` service with these flags. (No auto-deploy logic; configure manually.)

## Production Notes

⚠️ **This setup is for local development only.**

For production:
- Use Supervisor or systemd to manage queue workers
- Configure proper logging and monitoring
- Set up queue worker health checks
- Use Redis or SQS for better performance
- Configure multiple workers for high throughput

## Related Files

- `compose.yaml` - Queue worker service definition
- `config/queue.php` - Queue connection configuration
- `app/Console/Commands/QueueHealthCheck.php` - Health check command
- `storage/logs/laravel.log` - Application logs (includes job logs)
