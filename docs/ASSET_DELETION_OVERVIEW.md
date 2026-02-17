# Asset Deletion Overview

## Summary

Asset deletion uses a **soft delete first, hard delete after grace period** model. This allows accidental deletions to be recovered and gives a consistent policy across local, staging, and production.

## Flow

1. **User deletes** → Soft delete (sets `deleted_at`)
2. **Grace period** → Asset remains in DB but hidden from normal views (default: 30 days)
3. **Restore** → User can restore before grace period expires
4. **After grace period** → `DeleteAssetJob` runs, permanently removes files from S3 and DB record

## Resilience (Deploys, Worker Restarts)

**Primary path:** Soft delete dispatches `DeleteAssetJob` with a delay (e.g. 30 days). The job stays in the queue until the delay expires.

**Risk:** If the queue store (Redis) is flushed or lost, delayed jobs are gone. Worker/Horizon restarts do *not* affect queued jobs—they persist in Redis.

**Backup:** `ProcessExpiredAssetDeletionsJob` runs daily (03:00) via the scheduler. It finds soft-deleted assets past the grace period and dispatches `DeleteAssetJob` for each. This catches any assets whose delayed job was lost (Redis restart, deploy mishap, etc.). `DeleteAssetJob` is idempotent: if an asset was already hard-deleted, it skips.

## Configuration

| Setting | Env | Default | Description |
|---------|-----|---------|-------------|
| Grace period | `ASSET_DELETION_GRACE_PERIOD_DAYS` | 30 | Days before permanent deletion |

**Local / Staging:** Can use a shorter grace period (e.g. 7 days) via `.env`:

```
ASSET_DELETION_GRACE_PERIOD_DAYS=7
```

## Backend

- **AssetDeletionService** — `softDelete()`, `restoreFromTrash()`, `getAssetsReadyForHardDeletion()`
- **DeleteAssetJob** — Runs after grace period; idempotent (skips if asset was restored)
- **ProcessExpiredAssetDeletionsJob** — Scheduled daily; backup that processes assets past grace period (catches lost delayed jobs)
- **AssetController** — `destroy()` (soft delete), `restoreFromTrash()`
- **AssetPolicy** — `delete` checks `assets.delete` permission

## API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| DELETE | `/app/assets/{asset}` | Soft delete asset |
| POST | `/app/assets/{asset}/restore-from-trash` | Restore soft-deleted asset |

## UI

- **Delete button** — In asset details (Actions dropdown → Danger zone → Delete asset)
- **Permissions:**
  - `assets.delete` — Admins and brand managers can delete any asset
  - `assets.delete_own` — Managers can delete only their own files
  - Contributors and viewers cannot delete
- **Confirmation** — Modal explains grace period before confirming

## Error Handling

See `DELETION_ERROR_TRACKING.md` — deletion failures (S3, permissions, etc.) are recorded in `deletion_errors` and surfaced in Admin → Deletion Errors.

## Related

- `config/assets.php` — `deletion_grace_period_days`
- `app/Jobs/DeleteAssetJob.php` — Hard delete logic
- `app/Services/AssetDeletionService.php` — Soft delete + restore
- `docs/DELETION_ERROR_TRACKING.md` — Error recording and admin UI
