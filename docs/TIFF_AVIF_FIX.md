# TIFF/AVIF "Unsupported" Fix

## Problem

TIFF files were still showing as "unsupported" even after adding TIFF support via Imagick. This was because:

1. **Old skip reasons**: Assets uploaded before TIFF support was added had `thumbnail_skip_reason: 'unsupported_format:tiff'` in their metadata
2. **UI showing skip reason**: The asset drawer was displaying the skip reason message
3. **Retry not allowed**: The retry service didn't recognize TIFF/AVIF as supported formats
4. **Status blocking**: SKIPPED status prevented automatic regeneration

## Solution Implemented

### 1. Automatic Skip Reason Clearing
**File**: `app/Jobs/GenerateThumbnailsJob.php`
- Added logic to automatically clear old skip reasons when formats are now supported
- Checks if TIFF/AVIF has Imagick available and clears skip reason
- Resets status from SKIPPED to PENDING to allow regeneration

### 2. Retry Service Updates
**File**: `app/Services/ThumbnailRetryService.php`
- Added TIFF support check (with Imagick verification)
- Added AVIF support check (with Imagick verification)
- Clears old skip reasons when retrying
- Resets SKIPPED status to PENDING when retrying

### 3. Frontend Updates
**File**: `resources/js/Components/AssetDrawer.jsx`
- Updated retry eligibility to allow SKIPPED status
- Added TIFF/AVIF to supported formats list
- Added helpful message for TIFF/AVIF assets showing regeneration is possible

### 4. Command Line Tool
**File**: `app/Console/Commands/ClearOldThumbnailSkipReasons.php`
- New command to bulk-clear old skip reasons
- Can target specific formats (tiff, avif) or all
- Option to force regeneration by resetting status

## Usage

### Automatic Fix (Recommended)
The system will automatically clear skip reasons when:
- A TIFF/AVIF asset is processed by `GenerateThumbnailsJob`
- A user manually retries thumbnail generation
- The format is now supported (Imagick available)

### Manual Fix via Command
```bash
# Dry run to see what would be cleared
php artisan thumbnails:clear-skip-reasons --dry-run

# Clear skip reasons for TIFF only
php artisan thumbnails:clear-skip-reasons --format=tiff

# Clear skip reasons for all formats and force regeneration
php artisan thumbnails:clear-skip-reasons --format=all --force
```

### Manual Fix via UI
1. Open the asset drawer for a TIFF file
2. If it shows "Unsupported file type (TIFF)", look for the retry button
3. Click "Retry Thumbnail Generation"
4. The system will automatically clear the skip reason and regenerate

## Verification

### Check if Imagick is Available
```bash
php -m | grep imagick
```

### Check Asset Status
```php
// In tinker
$asset = Asset::find('your-asset-id');
echo "Status: " . $asset->thumbnail_status->value . PHP_EOL;
echo "Skip reason: " . ($asset->metadata['thumbnail_skip_reason'] ?? 'none') . PHP_EOL;
```

### Test TIFF Upload
1. Upload a new TIFF file
2. Should automatically generate thumbnails (if Imagick is available)
3. Should NOT show "unsupported" message

## What Changed

### Backend
- ✅ `GenerateThumbnailsJob`: Auto-clears old skip reasons
- ✅ `ThumbnailRetryService`: Supports TIFF/AVIF, clears skip reasons on retry
- ✅ New command: `thumbnails:clear-skip-reasons`

### Frontend
- ✅ `AssetDrawer`: Allows retrying SKIPPED assets
- ✅ `AssetDrawer`: Shows helpful message for TIFF/AVIF
- ✅ `AssetDrawer`: Includes TIFF/AVIF in supported formats

## Expected Behavior

### New TIFF/AVIF Uploads
- ✅ Automatically detected as supported (if Imagick available)
- ✅ Thumbnails generated successfully
- ✅ No "unsupported" message

### Old TIFF/AVIF Assets (Previously Skipped)
- ✅ Skip reason automatically cleared on next job run
- ✅ Can be manually retried from UI
- ✅ Status reset to PENDING when retried
- ✅ Thumbnails generated successfully

### If Imagick Not Available
- ⚠️ TIFF/AVIF will still show as unsupported
- ⚠️ Skip reason will remain
- ⚠️ Need to install Imagick extension first

## Troubleshooting

### TIFF Still Shows as Unsupported

1. **Check Imagick Installation**:
   ```bash
   php -m | grep imagick
   ```

2. **Clear Skip Reason Manually**:
   ```bash
   php artisan thumbnails:clear-skip-reasons --format=tiff --force
   ```

3. **Manually Retry from UI**:
   - Open asset drawer
   - Click "Retry Thumbnail Generation"
   - System will clear skip reason and regenerate

4. **Check Asset Metadata**:
   ```php
   // In tinker
   $asset = Asset::find('asset-id');
   print_r($asset->metadata);
   ```

### Retry Button Not Showing

1. **Check Permissions**: User needs `assets.retry_thumbnails` permission
2. **Check Retry Limit**: Asset may have exceeded max retries (3)
3. **Check Status**: Asset must be FAILED, PENDING, or SKIPPED

## Related Files

- `app/Jobs/GenerateThumbnailsJob.php` - Auto-clear skip reasons
- `app/Services/ThumbnailRetryService.php` - Retry logic with TIFF/AVIF support
- `app/Console/Commands/ClearOldThumbnailSkipReasons.php` - Bulk clear command
- `resources/js/Components/AssetDrawer.jsx` - UI updates for retry