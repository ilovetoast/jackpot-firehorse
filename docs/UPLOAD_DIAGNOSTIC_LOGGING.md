# Upload Diagnostic Logging

**Temporary** diagnostic logging for debugging upload pipeline issues. Remove after tests complete.

## How to Use

1. Upload your 6 assets (different file types).
2. After uploads complete, grep the Laravel log:

```bash
# From Laravel root (jackpot/)
grep "UPLOAD_DIAG" storage/logs/laravel.log
```

Or with Sail:

```bash
./vendor/bin/sail exec laravel.test grep "UPLOAD_DIAG" storage/logs/laravel.log
```

## What Gets Logged

Each log line is prefixed with `[UPLOAD_DIAG]` and includes:

- **asset_id** – Asset ID
- **filename** – Original filename
- **status** – Asset visibility (visible, hidden, failed)
- **thumbnail_status** – pending, processing, completed, failed, skipped
- **analysis_status** – uploading, generating_thumbnails, extracting_metadata, etc.
- **published_at** – When published (null = unpublished, may not appear in default grid)
- **approval_status** – not_required, pending, approved, rejected
- **category_id** – Category for the asset
- **metadata_extracted** – Whether ExtractMetadataJob completed
- **thumbnails_generated** – Whether thumbnails were generated
- **preview_generated** – Whether preview was generated
- **preview_skipped** / **preview_skipped_reason** – If preview was skipped (e.g. thumbnails_not_completed)
- **version_id** – Asset version ID (version-aware pipeline)
- **version_pipeline_status** – pending, processing, complete, failed
- **thumbnail_styles** – Which thumbnail styles exist (thumb, medium, large, preview)

## Pipeline Flow (Logged Points)

1. **UploadCompletionService ASSET_CREATED** – Asset and version created
2. **ProcessAssetOnUpload DISPATCHING** – Listener dispatching ProcessAssetJob
3. **ProcessAssetJob START** – Chain about to run
4. **ExtractMetadataJob** – START / COMPLETE / SKIP
5. **GenerateThumbnailsJob** – START / COMPLETE / SKIP / FAIL
6. **GeneratePreviewJob** – START / COMPLETE / SKIP
7. **GenerateVideoPreviewJob** – START / COMPLETE / SKIP (video only)
8. **ComputedMetadataJob** – START / COMPLETE / SKIP
9. **PopulateAutomaticMetadataJob** – START / COMPLETE
10. **ResolveMetadataCandidatesJob** – START / COMPLETE / SKIP
11. **FinalizeAssetJob** – START / COMPLETE / SKIP
12. **PromoteAssetJob** – START / COMPLETE / SKIP

## Diagnosing Common Issues

| Issue | What to check in logs |
|-------|------------------------|
| **No previews** | `GeneratePreviewJob SKIP` with `thumbnails_not_completed` → thumbnails failed or skipped |
| **No metadata** | `ExtractMetadataJob` / `PopulateAutomaticMetadataJob` SKIP or missing COMPLETE |
| **Disappearing from grid** | `status`=hidden, `published_at`=null, or `approval_status`=pending |
| **Wrong versioning** | `version_id` and `version_pipeline_status` at each step |
| **Thumbnail failures** | `GenerateThumbnailsJob FAIL` or SKIP with reason |

## Removing After Tests

Search for `UploadDiagnosticLogger` and `UPLOAD_DIAG` and remove all usages. Delete `app/Services/UploadDiagnosticLogger.php` and this doc.
