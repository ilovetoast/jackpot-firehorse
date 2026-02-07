# Upload → job dispatch flow (staging debug)

## Pipeline (no env guards)

1. **UploadController** (finalize) → `UploadCompletionService::complete()`
2. **UploadCompletionService::complete()** (inside `DB::transaction`) → `event(new AssetUploaded($asset))` (after commit via `DB::afterCommit`)
3. **ProcessAssetOnUpload** (listener, `ShouldQueue`) → queued to default connection (Redis in staging)
4. Worker runs **ProcessAssetOnUpload::handle()** → `ProcessAssetJob::dispatch($asset->id)`
5. **ProcessAssetJob** (chain) → ExtractMetadataJob → GenerateThumbnailsJob → … (no env conditionals)

## Dispatch points (upload path)

| Site | Job / event | Env conditionals | Reaches staging? |
|------|-------------|------------------|------------------|
| UploadCompletionService::complete() | `event(AssetUploaded)` → queues ProcessAssetOnUpload | None | Yes (after fix: after commit) |
| ProcessAssetOnUpload::handle() | `ProcessAssetJob::dispatch()` | None | Yes (when listener runs) |
| ProcessAssetJob | Bus::chain(…) | None | Yes |

## Notes

- No `app()->environment('production')` or `app()->isLocal()` around upload-related dispatch.
- Event is fired inside `DB::transaction`; deferred to **after commit** so the queued listener runs only when the asset is committed (avoids worker racing transaction in Redis/staging).
- Local: `QUEUE_CONNECTION=sync` → listener and ProcessAssetJob run synchronously in the same request; `DB::afterCommit` still fires after commit, behavior unchanged.
- Queue: listener and ProcessAssetJob use default queue; worker must run `queue:work` (or Horizon) on default.

## TEMPORARY: QUEUE_DEBUG logs

- `UploadCompletionService::complete()`: logs "Entered upload processing" and "About to fire AssetUploaded" inside `DB::afterCommit`.
- `ProcessAssetOnUpload::handle()`: logs "About to dispatch job" (ProcessAssetJob) before dispatch.
- **Remove** these `[QUEUE_DEBUG]` log statements after confirming staging dispatch and worker processing.

## Validation checklist (after deploy)

- [ ] Local uploads still work (sync: listener and ProcessAssetJob run in same request after commit).
- [ ] Staging logs show: Entered upload processing, About to fire AssetUploaded, About to dispatch job.
- [ ] Redis has jobs (e.g. `redis-cli LLEN queues:default` or Horizon).
- [ ] Worker processes jobs (thumbnails/metadata complete).
- [ ] No new env flags; remove QUEUE_DEBUG logs once confirmed.
