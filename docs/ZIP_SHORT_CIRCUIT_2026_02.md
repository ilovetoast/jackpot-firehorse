# ZIP/Archive Short-Circuit — February 2026

## Problem

ZIP and archive assets (application/zip, .zip, etc.) were showing as "processing" indefinitely. These file types cannot generate thumbnails, previews, or image-derived metadata. Running the full pipeline:

- Wastes queue capacity (each job skips or fails)
- Can cause stuck states if any job fails, retries, or blocks
- Confuses users who see "processing" forever

## Solution

**ProcessAssetJob** now short-circuits for unsupported types (ZIP, archives, BMP, ICO) at the very start:

1. After FileInspectionService runs (so we have accurate MIME type)
2. If `FileTypeService::getUnsupportedReason()` returns a result (ZIP, archive, etc.)
3. Immediately:
   - Set `thumbnail_status` = SKIPPED
   - Set `version.pipeline_status` = 'complete' (when version exists)
   - Set metadata flags (metadata_extracted, preview_skipped, ai_tagging_completed)
   - Dispatch **FinalizeAssetJob** directly (no chain)
4. Return — never run the full pipeline

The asset completes in one job instead of running 10+ chain jobs that all skip or fail.

## Recovery for Already-Stuck ZIPs

Assets that were stuck before this fix can be recovered:

```bash
# List stuck ZIP assets (no changes)
php artisan assets:fix-stuck-zip --dry-run

# Fix stuck ZIP assets
php artisan assets:fix-stuck-zip

# Limit to tenant
php artisan assets:fix-stuck-zip --tenant=1

# Limit number to fix
php artisan assets:fix-stuck-zip --limit=20
```

## Unsupported Types (from config/file_types.php)

- **ZIP**: application/zip, application/x-zip-compressed, .zip
- **Archive**: tar, gz, rar, 7z
- **BMP**: image/bmp
- **ICO**: image/x-icon, image/vnd.microsoft.icon

All of these now short-circuit in ProcessAssetJob.
