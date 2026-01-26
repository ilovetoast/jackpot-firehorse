# Video Thumbnail Generation Inspection

## Summary
FFmpeg is confirmed to be installed and working at `/usr/bin/ffmpeg`. The code flow for video thumbnail generation appears correct, but thumbnails are not being created.

## Code Flow Analysis

### 1. File Type Detection ✅
- **Location**: `FileTypeService::detectFileTypeFromAsset()`
- **Config**: `config/file_types.php` defines 'video' type with correct MIME types and extensions
- **Status**: Should work correctly

### 2. Capability Check ✅
- **Location**: `FileTypeService::supportsCapability('video', 'thumbnail')`
- **Config**: `file_types.types.video.capabilities.thumbnail = true`
- **Status**: Should return true

### 3. Requirements Check ✅
- **Location**: `FileTypeService::checkRequirements('video')`
- **Check**: Verifies FFmpeg is available via `findFFmpegPath()`
- **Status**: Should pass (FFmpeg confirmed available)

### 4. Handler Registration ✅
- **Location**: `FileTypeService::getHandler('video', 'thumbnail')`
- **Config**: `file_types.types.video.handlers.thumbnail = 'generateVideoThumbnail'`
- **Status**: Should return 'generateVideoThumbnail'

### 5. Method Existence ✅
- **Location**: `ThumbnailGenerationService::generateVideoThumbnail()`
- **Visibility**: Protected method (line 1681)
- **Status**: Method exists and should be callable

### 6. Thumbnail Generation Flow ✅
- **Location**: `ThumbnailGenerationService::generateThumbnails()`
- **Flow**: 
  1. Downloads video from S3
  2. Detects file type as 'video'
  3. Loops through styles (thumb, medium, large)
  4. Calls `generateThumbnail()` which routes to `generateVideoThumbnail()`
- **Status**: Flow appears correct

## Potential Issues

### Issue 1: Method Call via Variable
**Location**: `ThumbnailGenerationService::generateThumbnail()` line 714
```php
return $this->$handler($sourcePath, $styleConfig);
```
**Concern**: Calling protected method via variable. Should work, but verify.

### Issue 2: FFprobe Path Detection
**Location**: `ThumbnailGenerationService::getVideoInfo()` line 1928
```php
$ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);
```
**Status**: Should work if ffprobe is at `/usr/bin/ffprobe` (confirmed available)

### Issue 3: Error Handling
**Location**: Multiple try-catch blocks
**Concern**: Errors are logged but may be swallowed. Check Laravel logs for:
- `[ThumbnailGenerationService] Video thumbnail generation failed`
- `[ThumbnailGenerationService] FFmpeg frame extraction failed`
- `[ThumbnailGenerationService] FFprobe failed`

### Issue 4: Queue Worker
**Concern**: Thumbnail generation runs in background queue. Verify:
- Queue worker is running: `php artisan queue:work` or `php artisan queue:listen`
- Failed jobs table for errors
- Queue connection is configured correctly

## Diagnostic Steps

### Step 1: Run Diagnostic Script
```bash
docker compose exec laravel.test php test_video_thumbnail.php {asset_id}
```
This will verify:
- File type detection
- Capability support
- Requirements check
- Handler registration
- Method existence

### Step 2: Check Laravel Logs
Look for errors related to video thumbnail generation:
```bash
docker compose exec laravel.test tail -f storage/logs/laravel.log | grep -i "video\|thumbnail\|ffmpeg"
```

### Step 3: Check Queue Status
```bash
docker compose exec laravel.test php artisan queue:work --once
```
Or check failed jobs:
```bash
docker compose exec laravel.test php artisan queue:failed
```

### Step 4: Test Direct Generation
Create a test script to directly call the thumbnail generation service for a specific video asset.

## Next Steps

1. **Run the diagnostic script** on a video asset that's failing
2. **Check Laravel logs** for specific error messages
3. **Verify queue worker** is running and processing jobs
4. **Test with a new video upload** to see if it's an issue with existing assets vs new ones
5. **Check asset metadata** to see if thumbnail_status is being set correctly

## Files to Check

- `app/Jobs/GenerateThumbnailsJob.php` - Main job entry point
- `app/Services/ThumbnailGenerationService.php` - Thumbnail generation logic
- `app/Services/FileTypeService.php` - File type detection and requirements
- `config/file_types.php` - File type configuration
- `storage/logs/laravel.log` - Application logs
