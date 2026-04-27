# Horizon worker emergency runbook

Use this when a worker host is overloaded, unresponsive, or the queue is backing up. Operations are **destructive** where noted—run with care on production.

## Before you act

- Prefer **reversible** steps first: pause or stop workers, then trim queues, then config changes.
- **Config shape:** `config/horizon.php` registers a supervisor only if its `HORIZON_*_PROCESSES` value is **> 0** (see env keys in that file). `QUEUE_WORKERS_ENABLED=false` registers **no** workers for `staging` / `production` (Horizon can stay installed but runs nothing).
- **Downloads queue:** If `QUEUE_DOWNLOADS_QUEUE=downloads` in `.env`, you need either `HORIZON_DOWNLOADS_PROCESSES>0` and a `supervisor-downloads` worker, or point downloads back to the `default` queue until workers are available.

## Soft stop (in-process)

**Pause processing without killing the process** (jobs stay in Redis; no new work starts for this Horizon instance):

```bash
php artisan horizon:pause
```

Resume:

```bash
php artisan horizon:continue
```

## Stop OS supervisor / program

On Forge, systemd, or `supervisord`, stop the program that runs Horizon (name varies, e.g. `horizon`, `laravel-horizon`):

```bash
sudo supervisorctl stop <horizon-program-name>
# or: sudo systemctl stop horizon
```

Start again after the host is stable:

```bash
sudo supervisorctl start <horizon-program-name>
```

## Clear a specific Redis queue (drops pending jobs)

**Warning:** This removes jobs that have not been processed yet. Use when the backlog is known-bad (poison message, runaway job type) or you accept losing pending work on that queue.

```bash
php artisan queue:clear redis --queue=default
# repeat per queue: images, images-heavy, pdf-processing, ai, video-light, video-heavy, downloads, etc.
```

Connection name `redis` must match `config/queue.php` for your Redis connection.

## Failed jobs

Inspect failed jobs in Horizon UI or the `failed_jobs` table. To **discard the failed-job records** (does not re-run completed work):

```bash
php artisan queue:flush
```

Use when the failed set is large and no longer needed for triage. This does not clear *pending* queues; use `queue:clear` for that.

## Nuclear: disable all Horizon workers via env

1. On the server, set in `.env`:

   ```env
   QUEUE_WORKERS_ENABLED=false
   ```

2. Refresh cached config and restart the Horizon process (or the whole app container):

   ```bash
   php artisan optimize:clear
   php artisan config:cache
   ```

3. Restart Horizon (or the supervisor) so the new config loads.

With `QUEUE_WORKERS_ENABLED=false`, **staging** and **production** resolve to an **empty** supervisor list in `config/horizon.php` (no active workers). Re-enable by setting to `true` and redeploying / restarting.

## Safe restart after config changes

After changing `HORIZON_*_PROCESSES` or any Horizon/queue env:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan horizon:terminate
```

`horizon:terminate` asks Horizon to exit gracefully; the process manager (systemd / supervisor) should start a fresh instance with the new worker counts. If you do not use a manager that restarts Horizon, start it manually: `php artisan horizon`.

## Instance sizing (reminder)

**Do not** enable `HORIZON_IMAGES_PSD_PROCESSES` or `HORIZON_VIDEO_HEAVY_PROCESSES` on **t3.small** / **t3.medium** without dedicated headroom. PSD workers default to very high per-process RAM; video-heavy uses Playwright/FFmpeg. Keep those at `0` on small staging workers unless you explicitly need them.

See also: `config/horizon.php` header comments, `docs/MEDIA_PIPELINE.md`, and `docs/UPLOAD_AND_QUEUE.md`.
