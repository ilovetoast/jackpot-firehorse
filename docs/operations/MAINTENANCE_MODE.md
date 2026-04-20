# Maintenance mode — operator runbook

When something goes wrong, or a deploy needs a quiet window, put the site behind a branded splash page. Visitors see "We'll be right back" with the Jackpot wordmark; you keep access via a bypass URL.

## Quick reference

From the app root on any server:

```bash
./bin/maintenance.sh on        # enable, print bypass URL
./bin/maintenance.sh off       # disable
./bin/maintenance.sh status    # check current state
```

Or via artisan directly (identical behavior):

```bash
php artisan jackpot:maintenance on
php artisan jackpot:maintenance off
php artisan jackpot:maintenance status
```

## What visitors see

- **HTTP 503** with `Retry-After: 60`
- Dark Jackpot-branded splash at `resources/views/errors/maintenance.blade.php`
- Page auto-refreshes every 60s so visitors don't need to manually reload
- Static assets (logo, favicon, OG image) continue to load — they're served by nginx/apache, not PHP
- Social unfurls (Teams, Slack, LinkedIn) still show the OG preview image

## Bypassing the wall yourself

When you run `on`, the command prints a bypass URL like:

```
https://app.jackpot.local/Xk9qPz3mVnL7rF2aHcY8tE5uWdJ4bQsN
```

Visit that URL once in any browser — Laravel sets a signed cookie named `laravel_maintenance` and you get full access to the site for as long as maintenance is active. Share this with anyone who needs to verify the deploy (QA, support team).

**Security note:** the secret is regenerated every time you run `on`. An old URL stops working the moment you run `off`. If you lose the URL mid-maintenance, run `off && on` to generate a new one.

## How it works under the hood

- Laravel stores a flag file at `storage/framework/down` while maintenance is active. The presence of that file is what flips the app into maintenance mode.
- The branded view is **pre-rendered** at `down` time and cached to `storage/framework/maintenance.php`. The full framework is NOT booted for each visitor request — Laravel bails out early and serves the cached HTML. This is why the splash is bulletproof: it keeps working even if the database, Redis, or queue workers are completely offline.
- Because the view is pre-rendered, do **not** add runtime Blade helpers (`auth()`, `route()`, session, etc.) to `resources/views/errors/maintenance.blade.php`. Static assets referenced by absolute path (`/jp-wordmark-inverted.svg`, `/favicon.ico`) load fine.

## Advanced

```bash
# Custom bypass secret (you pick it) — useful for scripted deploys where
# you want a stable value instead of a generated one.
php artisan jackpot:maintenance on --secret=deploy-2026-04-20

# Longer Retry-After (e.g. 5-minute window)
php artisan jackpot:maintenance on --retry=300
```

See `app/Console/Commands/MaintenanceCommand.php` for the full flag list.

## Common gotchas

1. **Config cache** — if you change `resources/views/errors/maintenance.blade.php`, clear the pre-rendered cache by running `php artisan up && php artisan jackpot:maintenance on` again. Editing the Blade file while down doesn't refresh the cached HTML.
2. **Queue workers** — `php artisan down` *does* tell queue workers to stop picking up new jobs (they check `isDownForMaintenance()` on each loop by default). If you want them to keep running during maintenance, pass `--force` to `queue:work`.
3. **Scheduled tasks** — cron-triggered `schedule:run` respects maintenance mode unless tasks are explicitly marked `->evenInMaintenanceMode()`. Review `app/Console/Kernel.php` if you need a specific task to keep firing.
4. **Load balancers / health checks** — 503 may cause a load balancer to mark the node unhealthy and pull it from rotation. If you run behind a LB with strict health checks, configure the health endpoint to be `except`ed in `app/Http/Middleware/PreventRequestsDuringMaintenance.php` before you go down.

## Related

- `app/Console/Commands/MaintenanceCommand.php` — the wrapper command
- `resources/views/errors/maintenance.blade.php` — the splash view
- `bin/maintenance.sh` — the shell wrapper
- Laravel upstream docs: <https://laravel.com/docs/12.x/configuration#maintenance-mode>
