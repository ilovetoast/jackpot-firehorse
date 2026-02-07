# Upload → job dispatch flow

## Pipeline (no env guards)

1. **UploadController** (finalize) → `UploadCompletionService::complete()`
2. **UploadCompletionService::complete()** (inside `DB::transaction`) → `event(new AssetUploaded($asset))` (after commit via `DB::afterCommit`)
3. **ProcessAssetOnUpload** (listener, `ShouldQueue`) → queued to default connection (Redis in staging)
4. Worker runs **ProcessAssetOnUpload::handle()** → `ProcessAssetJob::dispatch($asset->id)`
5. **ProcessAssetJob** (chain) → ExtractMetadataJob → GenerateThumbnailsJob → … (no env conditionals)

## Dispatch points (upload path)

| Site | Job / event | Env conditionals | Reaches staging? |
|------|-------------|------------------|------------------|
| UploadCompletionService::complete() | `event(AssetUploaded)` → queues ProcessAssetOnUpload | None | Yes (after commit) |
| ProcessAssetOnUpload::handle() | `ProcessAssetJob::dispatch()` | None | Yes (when listener runs) |
| ProcessAssetJob | Bus::chain(…) | None | Yes |

## Notes

- No `app()->environment('production')` or `app()->isLocal()` around upload-related dispatch.
- Event is fired inside `DB::transaction`; deferred to **after commit** so the queued listener runs only when the asset is committed (avoids worker racing transaction in Redis/staging).
- Local: `QUEUE_CONNECTION=sync` → listener and ProcessAssetJob run synchronously in the same request; `DB::afterCommit` still fires after commit, behavior unchanged.
- Queue: listener and ProcessAssetJob use default queue; worker must run `queue:work` (or Horizon) on default.
