# Deploy Interruption Behavior

When Horizon is terminated during deployment, workers restart cleanly. This document describes expected behavior.

## Deploy Hook Sequence

1. `php artisan queue:restart` — Signals workers to finish current job, then exit.
2. `php artisan horizon:terminate` — Gracefully stops Horizon; workers drain and restart under Supervisor.

## Chain Interruption

- Jobs in `Bus::chain()` (e.g. ProcessAssetJob → ExtractMetadataJob → GenerateThumbnailsJob → …) may be interrupted mid-chain when workers are terminated.
- Interrupted jobs are recorded in:
  - `failed_jobs` (Laravel)
  - `system_incidents` (Unified Operations layer)
- Admin can see true system state via **Operations Center** (`/app/admin/operations-center`).
- Client-facing Asset Details shows "Processing Issue Detected" when unresolved incident exists, with **Retry Processing** and **Submit Support Ticket** actions.

## Expected Outcome

- Deploy interruptions are no longer silent.
- Stuck assets are detected within 5 minutes by `assets:watchdog`.
- Timeout failures are recorded properly via JobFailed listener and GenerateThumbnailsJob catch.
- Scheduler health visible correctly (Redis heartbeat).
- Workers restart cleanly after deploy.
