# Media pipeline (thumbnails, previews, PDFs, formats)

## Overview

The thumbnail generation pipeline is a **production-critical, locked system** that processes uploaded assets and generates preview and final thumbnails. This system prioritizes **stability over real-time updates**.

## System Design Philosophy

### 🔒 Intentionally Non-Realtime

**The thumbnail system does NOT auto-update thumbnails in the grid.**

- Users must **refresh the page** to see final thumbnails after processing completes
- Grid thumbnails are **snapshot-only** - locked on first render, never update after mount
- This design prevents UI flicker, re-render thrash, and race conditions

### Why This Design?

1. **Stability**: Prevents visual glitches and layout shifts
2. **Performance**: Avoids constant polling and unnecessary re-renders
3. **Predictability**: Users have a clear mental model (refresh to see updates)
4. **Simplicity**: Reduces complexity and potential bugs

## Pipeline Flow

```
Upload
  → ProcessAssetJob (FileInspectionService writes mime/width/height inline)
  → GenerateThumbnailsJob   ← fast path: first job in the main chain
  → GeneratePreviewJob
  → [GenerateVideoPreviewJob if video]
  → ExtractMetadataJob → ExtractEmbeddedMetadataJob → EmbeddedUsageRightsSuggestionJob
  → ComputedMetadataJob → PopulateAutomaticMetadataJob → ResolveMetadataCandidatesJob
  → [AITaggingJob unless _skip_ai_tagging]
  → FinalizeAssetJob → PromoteAssetJob

In parallel on the dedicated `ai` queue (when tenant policy allows):
  AiMetadataGenerationJob → [AiTagAutoApplyJob] → [AiMetadataSuggestionJob]
```

`GenerateThumbnailsJob` runs first because thumbnail generation does not depend on `ExtractMetadataJob` — `FileInspectionService` writes width/height/mime onto the asset before the chain dispatches, and `ThumbnailGenerationService` handles EXIF orientation while decoding. Full metadata, embedded metadata, usage-rights suggestions and AI work are scheduled as follow-up enrichment to keep time-to-first-thumbnail short. See `docs/UPLOAD_AND_QUEUE.md` for the full job order, queue routing, and `[asset_pipeline_timing]` log keys.

## Supported File Types

The thumbnail pipeline supports the following file types:

The thumbnail pipeline supports the following file types:

### Images (via GD library)
- **JPEG/JPG** (`image/jpeg`, `image/jpg`) - Full support
- **PNG** (`image/png`) - Full support with transparency
- **GIF** (`image/gif`) - Full support with transparency
- **WEBP** (`image/webp`) - Full support

**Other image types:**
- **TIFF/TIF** - Via Imagick ✓
- **AVIF** - Via Imagick ✓
- **PSD/PSB** - Via Imagick ✓
- **SVG** - Passthrough (original served as thumbnail) ✓

**Excluded image types:**
- BMP - GD library has limited BMP support, not reliable

### PDFs (via spatie/pdf-to-image)
- **PDF** (`application/pdf`) - **Page 1 only** ✓ IMPLEMENTED
  - Uses ImageMagick/Ghostscript backend
  - Only the first page is processed for thumbnail generation
  - Safety guards: max file size (100MB default), timeout (60s default)
  - Thumbnails are resized using GD library (same as image thumbnails)

**PDF Processing Details:**
- **Page Limit**: Only page 1 is processed (enforced by config)
- **File Size Limit**: Configurable via `config('assets.thumbnail.pdf.max_size_bytes')` (default: 100MB)
- **Timeout**: Configurable via `config('assets.thumbnail.pdf.timeout_seconds')` (default: 60s)
- **Large/Malformed PDFs**: Will fail gracefully with clear error messages

**⚠️ Warning**: Large or malformed PDFs may cause processing timeouts or memory issues. The system enforces size limits and timeouts to prevent resource exhaustion.

### Thumbnail Styles

- **preview**: LQIP (blurred, small)
- **thumb**, **medium**, **large**: Standard styles. Medium preserves transparency for logos (no gray block on public pages).

### LQIP (blur preview) storage and API

- **Not blurhash**: the placeholder is a **tiny blurred WebP/JPEG** on S3, path stored at `metadata.preview_thumbnails.preview.path`.
- **Delivery**: `AssetVariant::THUMB_PREVIEW` → `preview_thumbnail_url` / `thumbnail_preview` on asset JSON (same CDN URL rules as other variants).
- **Early persist (2026-03)**: `ThumbnailGenerationService` uploads the preview style **first**, then **merges `preview_thumbnails` into asset metadata immediately** (and into the current `AssetVersion` when versioning is used). Previously, metadata was only written when the job finished, so the grid had no LQIP for the entire `PROCESSING` window. Final job completion still writes the full `thumbnails` + `preview_thumbnails` blob as before.

### Key Components

1. **GenerateThumbnailsJob** (`app/Jobs/GenerateThumbnailsJob.php`)
   - Generates all thumbnail styles atomically
   - Updates `thumbnail_status` to terminal states
   - Records start time for timeout detection

2. **ThumbnailTimeoutGuard** (`app/Services/ThumbnailTimeoutGuard.php`)
   - Enforces 5-minute timeout on processing
   - Automatically marks stuck assets as FAILED
   - Prevents infinite PROCESSING states

3. **ThumbnailPreview** (`resources/js/Components/ThumbnailPreview.jsx`)
   - Renders thumbnails with strict priority: final > preview > icon
   - Grid context: snapshot-only (no live updates)
   - Drawer context: live updates via `useDrawerThumbnailPoll`

## Terminal State Guarantees

Every asset **MUST** reach exactly one terminal state:

- **COMPLETED**: Thumbnails generated successfully
- **FAILED**: Thumbnails failed to generate (error recorded)
- **SKIPPED**: Thumbnail generation not attempted (unsupported format)

### Enforcement

1. **All execution paths** in `GenerateThumbnailsJob` explicitly set terminal state
2. **ThumbnailTimeoutGuard** automatically fails assets stuck > 5 minutes
3. **ProcessAssetJob** prevents re-dispatching if already in terminal state
4. **UI spinners** only show when `thumbnail_status === PROCESSING`

## Timeout Protection

The `ThumbnailTimeoutGuard` service:

- Checks assets in `PROCESSING` state
- Uses `thumbnail_started_at` timestamp (or `created_at` as fallback)
- Marks assets as `FAILED` if processing > 5 minutes
- Logs timeout events for debugging

### Repairing Stuck Assets

All reconciliation happens automatically via:

- **AssetStateReconciliationService** — invoked at end of pipeline, in getIncidents, in retry-processing, and in assets:watchdog
- **assets:watchdog** — detects stuck assets and records incidents; auto-creates SupportTickets when needed
- **Admin Support Ticket resolve-and-reconcile** — POST /admin/support-tickets/{id}/resolve-and-reconcile

Do NOT manually update asset state in the database. Use the reconciliation flow or ticket resolution endpoint.

## UI Behavior

### Grid Context

- Thumbnails are **locked on first render**
- No polling, no live updates, no auto-refresh
- User must refresh page to see final thumbnails
- Spinner only shows when actively processing

### Drawer Context

- Live thumbnail polling enabled via `useDrawerThumbnailPoll`
- Preview → final swap happens automatically
- Isolated from grid state (drawer updates never affect grid)

### Spinner Rules

Spinner may ONLY render when ALL of these are true:

1. `thumbnail_status === 'PROCESSING'`
2. No `final_thumbnail_url` exists
3. No `thumbnail_error` exists
4. Not in terminal state (COMPLETED, FAILED, SKIPPED)

**Terminal states NEVER show spinners.**

## Queue Workers

Queue workers must be running for thumbnail processing:

```bash
# Laravel Sail
./vendor/bin/sail artisan queue:work --tries=3 --timeout=90

# Or via Docker Compose (see UPLOAD_AND_QUEUE.md)
```

Workers automatically restart on crash and respect timeout limits.

## Future Enhancements (Deferred)

The following features are **explicitly deferred** and marked with TODO comments:

1. **Manual Thumbnail Regeneration**
   - Per-asset "Regenerate Thumbnails" action
   - Allows users to trigger re-processing on demand

2. **Websocket-Based Updates**
   - Broadcast thumbnail completion events
   - Real-time UI updates without polling

3. **Thumbnail Versioning**
   - `thumbnail_version` field for cache busting
   - Enables live UI refresh without full page reload

These features are **not currently implemented** and should not be added without explicit approval.

## Troubleshooting

### Assets Stuck in Processing

1. Check queue workers are running: `ps aux | grep queue:work`
2. Check logs: `tail -f storage/logs/laravel.log`
3. Run repair command: `php artisan thumbnails:repair-stuck`
4. Verify timeout guard is active (runs on asset queries)

### Thumbnails Not Appearing

1. **Grid**: User must refresh page (by design)
2. **Drawer**: Check polling is active (should auto-update)
3. **Backend**: Verify `thumbnail_status` is COMPLETED
4. **Storage**: Check S3 bucket for thumbnail files

### storage_error (S3 Download Failure)

When `GenerateThumbnailsJob` fails at `downloadFromS3()` with `storage_error`:

1. **Diagnose**: Run `php artisan assets:check-storage {asset_id}` to verify the source file exists in S3 and report any access issues.
2. **Common causes**:
   - **NoSuchKey**: File missing at `storage_root_path` (temp cleanup before promotion, wrong path)
   - **AccessDenied**: Worker IAM lacks `s3:GetObject` for the bucket
   - **NoSuchBucket**: Per-tenant bucket not provisioned
3. **Fix**: Ensure worker uses credentials with S3 access; run `tenants:ensure-buckets` if buckets are missing.
4. **Prevention**: `UploadCleanupService` and `AssetsCleanupStaging` NEVER delete temp files that any asset references. Multiple guards: (a) asset with matching upload_session_id + storage_root_path, (b) ANY asset with storage_root_path = temp path, (c) path prefix match for subpaths. This must never be relaxed.
5. **DEAD state**: When NoSuchKey is detected, the asset is marked `metadata.storage_missing = true` (DEAD). A critical incident "Source file missing (DEAD asset)" is created. Admin UI shows "Dead" prominently. These assets cannot be recovered — delete and re-upload.

### White Logos on Transparent Background

Transparent logos (PNG, WebP, GIF) with white content get a gray-400 background so they remain visible. If existing assets still show white-on-white:

1. **Retry** (any user with `assets.retry_thumbnails`): Open asset drawer → "Retry Thumbnail Generation"
2. **Regenerate** (admin with `assets.regenerate_thumbnails_admin`): Asset Details → Thumbnail Management → Regenerate Thumbnails

### Infinite Spinners

1. Verify `thumbnail_status` is not stuck in PROCESSING
2. Check terminal state guards are working
3. Ensure timeout guard is running
4. Verify spinner condition logic (see Spinner Rules above)

## Code Locations

- **Job**: `app/Jobs/GenerateThumbnailsJob.php`
- **Service**: `app/Services/ThumbnailGenerationService.php`
  - Image thumbnails: `generateImageThumbnail()` (GD library)
  - PDF thumbnails: `generatePdfThumbnail()` (spatie/pdf-to-image)
- **Guard**: `app/Services/ThumbnailTimeoutGuard.php`
- **Component**: `resources/js/Components/ThumbnailPreview.jsx`
- **Command**: `app/Console/Commands/RepairStuckThumbnails.php`
- **Controller**: `app/Http/Controllers/AssetController.php` (timeout guard integration)
- **Retry Service**: `app/Services/ThumbnailRetryService.php` (supports PDFs)

## PDF Thumbnail Generation

PDF thumbnails are generated using the same pipeline as images, with PDF-specific handling:

1. **File Type Detection**: PDFs are detected by MIME type (`application/pdf`) or extension
2. **Page Extraction**: Only page 1 is extracted using `spatie/pdf-to-image`
3. **Image Conversion**: PDF page is converted to PNG, then resized using GD library
4. **Thumbnail Styles**: Same styles apply (preview, thumb, medium, large)
5. **Storage**: Thumbnails stored in same S3 structure as image thumbnails
6. **Retry Support**: PDF thumbnails are compatible with the retry mechanism

**Retry Compatibility:**
- PDF thumbnails can be retried like image thumbnails
- Retry limits apply equally (max 3 attempts per asset)
- Each retry re-extracts page 1 only (consistent behavior)

**Failure Modes:**
- File size exceeds limit → FAILED with clear error message
- PDF is corrupted → FAILED with validation error
- Processing timeout → FAILED with timeout error
- Missing dependencies → FAILED with package error

All failures produce terminal metadata (`failed_at`, `error_reason`) and respect existing guarantees.

## Important Notes

⚠️ **DO NOT** refactor thumbnail logic without explicit approval  
⚠️ **DO NOT** add polling/websockets without explicit approval  
⚠️ **DO NOT** reintroduce preview/final swap logic in grid  
⚠️ **DO NOT** touch queue orchestration unless explicitly requested  
⚠️ **PDF support is additive** - does not modify existing image processing

**Stability over features.**


---

## Timeouts, environment variables, and PHP memory

Use this guide when deploying to **staging** and **production** worker servers.

## Running Sail (Laravel root)

From the Laravel project root (e.g. `jackpot/`):

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan horizon
```

## Environment Variables to Update

Add or update these in `.env` on **staging** and **production** worker machines:

### Timeout Hierarchy (Required)

**Rule: Guard ≥ Job ≥ Worker** — prevents false timeouts.

| Variable | Staging/Production | Local (optional) | Description |
|----------|--------------------|------------------|-------------|
| `QUEUE_WORKER_TIMEOUT` | `1800` | `600` | Horizon worker timeout (seconds). Worker kills jobs after this. |
| `THUMBNAIL_JOB_TIMEOUT_SECONDS` | `1800` | `600` | GenerateThumbnailsJob, GeneratePreviewJob, GenerateVideoPreviewJob timeout. |
| `THUMBNAIL_TIMEOUT_MINUTES` | `35` | `5` | ThumbnailTimeoutGuard: mark PROCESSING as FAILED after this many minutes. Must be **greater than** job timeout (35 min > 30 min). |

### TIFF / Large Image Safety

| Variable | Value | Description |
|----------|-------|-------------|
| `THUMBNAIL_MAX_PIXELS` | `200000000` | Max pixel area (width × height). Files exceeding this get degraded mode: preview + thumb only; medium/large skipped. Prevents OOM on 700MB+ TIFFs. |

### Example `.env` (staging worker)

```env
QUEUE_WORKER_TIMEOUT=1800
THUMBNAIL_JOB_TIMEOUT_SECONDS=1800
THUMBNAIL_TIMEOUT_MINUTES=35
THUMBNAIL_MAX_PIXELS=200000000
```

---

## PHP Variables to Update

### memory_limit

Large TIFFs (e.g. 700MB) can cause OOM if `memory_limit` is too low.

| Environment | Recommended | Notes |
|-------------|-------------|-------|
| **Staging worker** | `2G` | Handles large TIFFs without crashing. |
| **Production worker** | `2G` | Same as staging. |
| **Local** | `1G` | Minimum for development. |

### Where to set

- **Sail / Docker**: Override in `docker/8.5/php.ini` or via `php.ini` in the PHP container.
- **System PHP**: In `php.ini` (e.g. `/etc/php/8.x/cli/php.ini` for CLI workers).

```ini
memory_limit = 2G
```

- **Horizon**: Horizon can also set per-worker memory via `config/horizon.php` → `defaults` → `memory`. Default is 128MB; increase if needed for thumbnail workers.
- **Process counts / small instances:** Use `HORIZON_*_PROCESSES` in `.env` so staging and small workers do not register heavy supervisors (see `config/horizon.php`). Do not run `images-psd` or `video-heavy` pools on t3.small/t3.medium without extra RAM. Emergency steps: [HORIZON_EMERGENCY_RUNBOOK.md](operations/HORIZON_EMERGENCY_RUNBOOK.md).

---

## After Updating

1. **Restart Horizon** so workers pick up new env and PHP settings:
   ```bash
   ./vendor/bin/sail artisan horizon:terminate
   # Horizon will restart if supervised (e.g. systemd, supervisor)
   # Or: ./vendor/bin/sail artisan horizon
   ```

2. **Clear config cache** (if using `config:cache` in production):
   ```bash
   ./vendor/bin/sail artisan config:clear
   ./vendor/bin/sail artisan config:cache
   ```

---

## Degraded Thumbnail Mode

When an image exceeds `THUMBNAIL_MAX_PIXELS` (default 200M pixels ≈ 14k×14k):

- **Generated**: `preview` (32px) + `thumb` (320px)
- **Skipped**: `medium` (1024px), `large` (4096px)
- **Metadata**: `thumbnail_quality` = `degraded_large_skipped`

This avoids OOM, Imagick pixel cache overflow, and swap thrashing on very large files.


---

## AVIF and WebP

## Overview

Added support for AVIF image previews using ImageMagick, and implemented WebP as the default thumbnail output format for better compression and performance.

install librsvg
sudo apt update
sudo apt install librsvg2-bin librsvg2-dev

## AVIF Support

### Requirements
- **Imagick PHP Extension**: Required for AVIF processing (GD library does not support AVIF)
- **ImageMagick**: System-level ImageMagick library with AVIF support (usually comes with Imagick extension)

### Implementation

#### 1. GenerateThumbnailsJob.php
- **Updated `supportsThumbnailGeneration()`**: Added AVIF support check that verifies Imagick extension is available
- **Updated `determineSkipReason()`**: Modified to only mark AVIF as unsupported if Imagick is not available

#### 2. ThumbnailGenerationService.php
- **Updated `detectFileType()`**: Added AVIF detection as a separate file type
- **Updated `generateThumbnail()`**: Added `case 'avif'` to route AVIF files to dedicated handler
- **Added `generateAvifThumbnail()`**: New method that uses Imagick to process AVIF files
- **Updated dimension capture**: Added AVIF handling in file validation section to extract dimensions using Imagick
- **Updated metadata storage**: AVIF files now store pixel dimensions like regular images

### AVIF Thumbnail Generation Process
1. **File Detection**: AVIF files are detected by MIME type (`image/avif`) or extension (`avif`)
2. **Imagick Verification**: System checks if Imagick extension is loaded
3. **File Reading**: Imagick reads the AVIF file (handles multi-image AVIFs by using first image)
4. **Dimension Extraction**: Source image dimensions are captured for metadata
5. **Thumbnail Generation**: 
   - Resize using high-quality Lanczos filter
   - Apply blur for preview thumbnails (if configured)
   - Convert to WebP or JPEG format for output (based on config)
   - Save to temporary file
6. **Upload**: Generated thumbnail is uploaded to S3

## WebP Thumbnail Output

### Benefits
- **25-35% smaller file sizes** compared to JPEG at similar quality
- **Better compression** while maintaining visual quality
- **Excellent browser support**: Chrome, Firefox, Safari, Edge all support WebP
- **Faster page loads**: Smaller files mean faster downloads

### Configuration

**Config File**: `config/assets.php`

```php
'thumbnail' => [
    'output_format' => env('THUMBNAIL_OUTPUT_FORMAT', 'webp'), // 'webp' or 'jpeg'
    // ... other config
],
```

**Environment Variable**: `THUMBNAIL_OUTPUT_FORMAT`
- Set to `webp` (default) for better compression
- Set to `jpeg` for maximum compatibility with older browsers

### Implementation

All thumbnail generation methods now support WebP output:
- **`generateImageThumbnail()`**: GD-based image thumbnails (JPEG, PNG, GIF, WebP sources)
- **`generateTiffThumbnail()`**: Imagick-based TIFF thumbnails
- **`generateAvifThumbnail()`**: Imagick-based AVIF thumbnails
- **`generatePdfThumbnail()`**: PDF page extraction thumbnails
- **`generateImageMagickThumbnail()`**: Admin override for unsupported formats

### Automatic Fallback

If WebP is not available (PHP version < 7.0 or GD without WebP support), the system automatically falls back to JPEG format. This ensures compatibility even if WebP support is missing.

## Usage

### AVIF Files
1. Upload an AVIF file (`.avif` extension or `image/avif` MIME type)
2. System automatically detects and processes using Imagick
3. Thumbnails are generated in WebP format (or JPEG if configured)
4. Proper previews display in the asset grid

### WebP Thumbnails
1. **Default Behavior**: All new thumbnails are generated as WebP (better compression)
2. **Configuration**: Set `THUMBNAIL_OUTPUT_FORMAT=jpeg` in `.env` to use JPEG instead
3. **Existing Thumbnails**: Existing JPEG thumbnails continue to work; new ones use configured format
4. **Browser Support**: Modern browsers automatically handle WebP images

## Testing

### Verify AVIF Support
```bash
# Check Imagick extension
php -m | grep imagick

# Check ImageMagick AVIF support
convert -list format | grep -i avif
```

### Verify WebP Support
```bash
# Check GD WebP support
php -r "var_dump(function_exists('imagewebp'));"
```

### Test Upload
1. Upload an AVIF file
2. Check that thumbnails are generated
3. Verify thumbnails are in WebP format (check file extension in S3)
4. Confirm proper display in asset grid

## Performance Impact

### WebP Benefits
- **Bandwidth Savings**: 25-35% reduction in thumbnail file sizes
- **Faster Load Times**: Smaller files download faster
- **Better User Experience**: Faster grid loading, especially on slower connections
- **Storage Savings**: Reduced S3 storage costs for thumbnails

### Example Savings
For a typical 1024x1024 thumbnail:
- **JPEG**: ~150-200 KB
- **WebP**: ~100-130 KB
- **Savings**: ~50-70 KB per thumbnail (33% reduction)

## Browser Compatibility

### WebP Support
- ✅ Chrome 23+ (2012)
- ✅ Firefox 65+ (2019)
- ✅ Safari 14+ (2020)
- ✅ Edge 18+ (2018)
- ✅ Opera 12.1+ (2012)
- ❌ Internet Explorer (not supported, but IE usage is <1%)

**Recommendation**: WebP is safe to use as default. The <1% of users on unsupported browsers can be handled with fallback if needed.

## Configuration Examples

### Use WebP (Recommended)
```env
THUMBNAIL_OUTPUT_FORMAT=webp
```

### Use JPEG (Maximum Compatibility)
```env
THUMBNAIL_OUTPUT_FORMAT=jpeg
```

## Troubleshooting

### AVIF Files Not Generating Thumbnails

1. **Verify Imagick Extension**:
   ```bash
   docker compose exec laravel.test php -m | grep imagick
   ```

2. **Check ImageMagick AVIF Support**:
   ```bash
   docker compose exec laravel.test convert -list format | grep -i avif
   ```

3. **Check Logs**:
   ```bash
   tail -f storage/logs/laravel.log | grep -i avif
   ```

### WebP Thumbnails Not Generating

1. **Check GD WebP Support**:
   ```bash
   docker compose exec laravel.test php -r "var_dump(function_exists('imagewebp'));"
   ```

2. **Verify Config**:
   ```bash
   docker compose exec laravel.test php artisan tinker --execute="echo config('assets.thumbnail.output_format');"
   ```

3. **Fallback**: System automatically falls back to JPEG if WebP is not available

## Related Files

- `app/Jobs/GenerateThumbnailsJob.php` - Thumbnail generation job
- `app/Services/ThumbnailGenerationService.php` - Core thumbnail service
- `config/assets.php` - Thumbnail configuration
- `MEDIA_PIPELINE.md` - Similar implementation for TIFF

## Future Enhancements

- **Progressive WebP**: Support for progressive WebP encoding
- **AVIF Output**: Option to output thumbnails as AVIF (even better compression)
- **Format Detection**: Automatic format selection based on source image
- **Quality Optimization**: Per-style quality settings for WebP vs JPEG

---

## TIFF preview support

## Overview

TIFF (Tagged Image File Format) image preview support has been added to the thumbnail generation system using Imagick. This allows TIFF files to generate proper thumbnails instead of being skipped.

## Implementation Details

### Requirements
- **Imagick PHP Extension**: Required for TIFF processing (GD library does not support TIFF)
- **ImageMagick**: System-level ImageMagick library (usually installed with Imagick extension)

### Changes Made

#### 1. GenerateThumbnailsJob.php
- **Updated `supportsThumbnailGeneration()`**: Added TIFF support check that verifies Imagick extension is available
- **Updated `determineSkipReason()`**: Modified to only mark TIFF as unsupported if Imagick is not available

#### 2. ThumbnailGenerationService.php
- **Updated `detectFileType()`**: Added TIFF detection as a separate file type (before general image detection)
- **Updated `generateThumbnail()`**: Added `case 'tiff'` to route TIFF files to dedicated handler
- **Added `generateTiffThumbnail()`**: New method that uses Imagick to process TIFF files
- **Updated dimension capture**: Added TIFF handling in file validation section to extract dimensions using Imagick
- **Updated metadata storage**: TIFF files now store pixel dimensions like regular images

### Technical Implementation

#### TIFF Thumbnail Generation Process
1. **File Detection**: TIFF files are detected by MIME type (`image/tiff`, `image/tif`) or extension (`tiff`, `tif`)
2. **Imagick Verification**: System checks if Imagick extension is loaded
3. **File Reading**: Imagick reads the TIFF file (handles multi-page TIFFs by using first page)
4. **Dimension Extraction**: Source image dimensions are captured for metadata
5. **Thumbnail Generation**: 
   - Resize using high-quality Lanczos filter
   - Apply blur for preview thumbnails (if configured)
   - Convert to JPEG format for output
   - Save to temporary file
6. **Upload**: Generated thumbnail is uploaded to S3

#### Error Handling
- **Missing Imagick**: Clear error message if Imagick extension is not available
- **Invalid TIFF**: Proper error handling for corrupted or invalid TIFF files
- **Multi-page Support**: Automatically uses first page of multi-page TIFF files
- **Logging**: Comprehensive logging for debugging and monitoring

## Usage

### Automatic Processing
TIFF files uploaded to the system will automatically:
1. Be detected as TIFF format
2. Generate thumbnails using Imagick (if extension is available)
3. Store source dimensions in metadata
4. Display proper previews in the asset grid

### Manual Regeneration
Admins can manually regenerate TIFF thumbnails using the admin thumbnail regeneration interface:
- Navigate to asset details modal
- Use "Regenerate Thumbnails" option
- TIFF files will use Imagick automatically

## Configuration

No additional configuration is required. The system automatically:
- Detects TIFF files
- Verifies Imagick availability
- Falls back gracefully if Imagick is not available (marks as unsupported)

## Testing

To verify TIFF support is working:

1. **Check Imagick Extension**:
   ```bash
   php -m | grep imagick
   ```

2. **Upload a TIFF File**:
   - Upload a `.tiff` or `.tif` file
   - System should generate thumbnails automatically

3. **Verify Thumbnails**:
   - Check asset grid for proper preview
   - Verify thumbnail metadata includes dimensions
   - Check S3 for generated thumbnail files

## Troubleshooting

### TIFF Files Still Not Generating Thumbnails

1. **Verify Imagick Extension**:
   ```bash
   docker compose exec laravel.test php -m | grep imagick
   ```

2. **Check Logs**:
   ```bash
   tail -f storage/logs/laravel.log | grep -i tiff
   ```

3. **Verify ImageMagick System Library**:
   ```bash
   docker compose exec laravel.test convert --version
   ```

### Common Issues

**"TIFF thumbnail generation requires Imagick PHP extension"**
- Solution: Install Imagick extension in PHP container

**"TIFF file has invalid dimensions"**
- Solution: File may be corrupted, try a different TIFF file

**"TIFF thumbnail generation failed"**
- Solution: Check ImageMagick system library is installed and configured correctly

## Related Files

- `app/Jobs/GenerateThumbnailsJob.php` - Job that processes thumbnail generation
- `app/Services/ThumbnailGenerationService.php` - Core thumbnail generation service
- `app/Http/Controllers/AssetThumbnailController.php` - Admin thumbnail management

## Future Enhancements

- **Multi-page TIFF Support**: Currently uses first page only, could support page selection
- **TIFF Compression**: Support for different TIFF compression formats
- **Color Space Handling**: Better handling of different TIFF color spaces (CMYK, etc.)

## Notes

- TIFF support is **additive** - does not affect existing image processing (JPEG, PNG, etc.)
- GD library continues to handle standard image formats
- Imagick is only used for TIFF files (and PDFs via spatie/pdf-to-image)
- Multi-page TIFF files automatically use the first page for thumbnails

---

## PDF thumbnail setup and verification

## Summary

PDF thumbnail generation support has been added to the Laravel Sail Docker environment. This is an **additive change only** - no existing thumbnail jobs or pipeline have been modified.

## Files Changed/Created

1. **`docker/8.5/Dockerfile`** - Custom Dockerfile extending Sail with PDF support
2. **`compose.yaml`** - Updated to use custom Dockerfile
3. **`composer.json`** - Added `spatie/pdf-to-image` dependency
4. **`app/Console/Commands/VerifyPdfThumbnailSupport.php`** - Verification command
5. **`docs/MEDIA_PIPELINE.md`** - Full documentation

## Quick Setup Steps

### 1. Install Composer Package

```bash
./vendor/bin/sail composer require spatie/pdf-to-image
```

### 2. Rebuild Sail Containers

```bash
./vendor/bin/sail down
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

### 3. Verify Installation

```bash
./vendor/bin/sail artisan pdf:verify
```

If all checks pass, PDF thumbnail generation is ready!

## What Was Installed

- **imagemagick**: Image manipulation library
- **ghostscript**: Required for ImageMagick PDF processing
- **poppler-utils**: Optional fallback PDF tools
- **PHP imagick extension**: Already in Sail (verified)
- **spatie/pdf-to-image**: Laravel package for PDF conversion

## Verification Command Output

The `pdf:verify` command checks:
- ✅ PHP Imagick extension loaded
- ✅ ImageMagick command-line tool available
- ✅ Ghostscript installed
- ✅ ImageMagick PDF policy configured
- ✅ Spatie package available
- ✅ Actual PDF conversion test

## Next Steps

Once verified, PDF support can be integrated into the thumbnail pipeline. The existing `GenerateThumbnailsJob` can be extended (additively) to handle PDF files.

## Troubleshooting

See `docs/MEDIA_PIPELINE.md` for detailed troubleshooting steps.


### Extended PDF setup (troubleshooting)

This document describes how PDF thumbnail generation support is configured in the Laravel Sail Docker environment.

## Overview

PDF thumbnail generation requires:
- **ImageMagick**: Core image manipulation library
- **Ghostscript**: Required for ImageMagick to process PDF files
- **Poppler-utils**: Optional fallback tools (pdftoppm, pdfinfo)
- **PHP Imagick extension**: Already included in Sail PHP 8.5 image
- **Spatie PDF-to-Image**: Laravel-friendly Composer package

## Docker Configuration

### Location

The custom Docker configuration is located in:
```
docker/8.5/Dockerfile
```

This extends the base Laravel Sail PHP 8.5 runtime with PDF processing capabilities.

### What's Installed

1. **imagemagick**: System package for image manipulation
2. **ghostscript**: Required delegate for ImageMagick PDF processing
3. **poppler-utils**: Optional fallback tools for PDF processing

### ImageMagick PDF Policy

The Dockerfile configures ImageMagick to allow PDF read/write operations by modifying `/etc/ImageMagick-6/policy.xml` (or ImageMagick-7 equivalent).

**Security Note**: PDFs can contain embedded scripts and should be processed with caution. The policy is enabled here because PDF thumbnail generation is a core feature requirement.

## Rebuilding Sail

After making changes to the Dockerfile, rebuild the Sail containers:

```bash
# Stop existing containers
./vendor/bin/sail down

# Rebuild containers (no cache to ensure fresh build)
./vendor/bin/sail build --no-cache

# Start containers
./vendor/bin/sail up -d
```

Or in one command:
```bash
./vendor/bin/sail down && ./vendor/bin/sail build --no-cache && ./vendor/bin/sail up -d
```

## Installing Composer Package

Install the Spatie PDF-to-Image package:

```bash
./vendor/bin/sail composer require spatie/pdf-to-image
```

Or if running composer on host:
```bash
composer require spatie/pdf-to-image
```

## Verification

### Quick Verification Command

Run the built-in verification command:

```bash
./vendor/bin/sail artisan pdf:verify
```

This command checks:
1. PHP Imagick extension is loaded
2. ImageMagick command-line tool is available
3. Ghostscript is installed and working
4. ImageMagick PDF policy is configured
5. Spatie PDF-to-Image package is available
6. Actual PDF to image conversion works

### Manual Verification

You can also verify components manually:

#### Check PHP Imagick Extension
```bash
./vendor/bin/sail php -m | grep imagick
./vendor/bin/sail php -r "echo (new Imagick())->getVersion()['versionString'];"
```

#### Check ImageMagick
```bash
./vendor/bin/sail exec laravel.test convert -version
```

#### Check Ghostscript
```bash
./vendor/bin/sail exec laravel.test gs --version
```

#### Check ImageMagick PDF Policy
```bash
./vendor/bin/sail exec laravel.test cat /etc/ImageMagick-6/policy.xml | grep PDF
```

#### Test PDF Conversion (Tinker)
```bash
./vendor/bin/sail artisan tinker
```

Then in tinker:
```php
use Spatie\PdfToImage\Pdf;

// Replace with path to a test PDF
$pdf = new Pdf('/path/to/test.pdf');
$pdf->setPage(1)
    ->setOutputFormat('png')
    ->saveImage('/tmp/test-output.png');

// Check if file was created
file_exists('/tmp/test-output.png'); // Should return true
```

## Troubleshooting

### ImageMagick PDF Policy Issues

If PDF conversion fails with "not authorized" errors:

1. Check the policy file:
   ```bash
   ./vendor/bin/sail exec laravel.test cat /etc/ImageMagick-6/policy.xml
   ```

2. Verify PDF policy allows read|write:
   ```bash
   ./vendor/bin/sail exec laravel.test grep -i pdf /etc/ImageMagick-6/policy.xml
   ```

3. If policy is incorrect, rebuild the container (the Dockerfile should fix it automatically)

### Ghostscript Not Found

If Ghostscript is missing:

1. Verify it's installed:
   ```bash
   ./vendor/bin/sail exec laravel.test which gs
   ```

2. If missing, rebuild the container to ensure the Dockerfile runs

### PHP Imagick Extension Not Loaded

The extension should be included in the Sail image. If it's not:

1. Check if it's installed:
   ```bash
   ./vendor/bin/sail exec laravel.test php -m | grep imagick
   ```

2. If missing, verify the Dockerfile includes `php8.5-imagick` in the package list

### Spatie Package Not Found

If the package is not found:

1. Install it:
   ```bash
   ./vendor/bin/sail composer require spatie/pdf-to-image
   ```

2. Verify autoload:
   ```bash
   ./vendor/bin/sail composer dump-autoload
   ```

## Next Steps

Once verification passes, PDF thumbnail generation can be integrated into the thumbnail pipeline. The existing `GenerateThumbnailsJob` can be extended (additively) to support PDF files without modifying the core job logic.

## Security Considerations

- PDFs can contain embedded JavaScript and scripts
- Always validate PDFs before processing
- Consider resource limits (memory, CPU) for PDF processing
- In production, consider sandboxing PDF processing
- Scan PDFs for malicious content if processing untrusted files

## References

- [Spatie PDF-to-Image Documentation](https://github.com/spatie/pdf-to-image)
- [ImageMagick Security Policy](https://imagemagick.org/script/security-policy.php)
- [Ghostscript Documentation](https://www.ghostscript.com/documentation.html)


### PDF thumbnail implementation notes

## Summary

PDF thumbnail generation has been added to the locked thumbnail pipeline as an **additive extension**. PDFs are now a first-class supported file type alongside images.

## Changes Made

### 1. Configuration (`config/assets.php`)

Added PDF-specific limits and safety guards:

```php
'thumbnail' => [
    'pdf' => [
        'max_size_bytes' => env('THUMBNAIL_PDF_MAX_SIZE_BYTES', 100 * 1024 * 1024), // 100MB
        'max_page' => env('THUMBNAIL_PDF_MAX_PAGE', 1), // Always page 1
        'timeout_seconds' => env('THUMBNAIL_PDF_TIMEOUT_SECONDS', 60), // 60s timeout
    ],
],
```

### 2. File Type Support

**Updated Files:**
- `app/Jobs/GenerateThumbnailsJob.php::supportsThumbnailGeneration()`
- `app/Services/ThumbnailRetryService.php::isFileTypeSupported()`

**Changes:**
- Added PDF detection (`application/pdf` MIME type or `.pdf` extension)
- Verifies `spatie/pdf-to-image` package availability
- Centralized logic (no UI-only checks)

### 3. Thumbnail Generation Service

**File:** `app/Services/ThumbnailGenerationService.php`

**Implemented Method:** `generatePdfThumbnail()`

**Features:**
- Extracts page 1 only using `spatie/pdf-to-image`
- Converts PDF page to PNG image
- Resizes using GD library (same as image thumbnails for consistency)
- Applies same thumbnail styles (preview, thumb, medium, large)
- Safety guards: file size check, timeout protection, page limit enforcement

**Safety Guards:**
- Maximum file size: 100MB (configurable)
- Page limit: Always page 1 (hard requirement)
- Timeout: 60 seconds (configurable)
- Graceful failures with clear error messages

### 4. Pipeline Integration

**Updated:** `generateThumbnails()` method

**Changes:**
- File type detection happens before validation
- PDFs skip image validation (use PDF-specific validation)
- Images continue using existing `getimagesize()` validation
- Both file types flow through same job entry point
- Branch internally based on detected file type

### 5. Retry Compatibility

**Updated:** `ThumbnailRetryService`

**Features:**
- PDFs are retryable like images
- Same retry limits apply (max 3 attempts)
- Each retry re-extracts page 1 only
- Error handling matches image retry behavior

### 6. Documentation

**Updated:** `docs/MEDIA_PIPELINE.md`

**Added:**
- Supported file types section (Images + PDFs)
- PDF processing details
- Safety guard documentation
- Retry compatibility notes
- Warning about large/malformed PDFs

## Implementation Details

### PDF Processing Flow

```
1. Asset uploaded (PDF file)
   ↓
2. GenerateThumbnailsJob dispatched
   ↓
3. File type detected as 'pdf'
   ↓
4. PDF downloaded from S3 to temp location
   ↓
5. File size validated (max 100MB)
   ↓
6. Page 1 extracted using spatie/pdf-to-image
   ↓
7. PDF page converted to PNG image
   ↓
8. PNG resized to thumbnail dimensions (GD library)
   ↓
9. Thumbnails uploaded to S3 (same structure as images)
   ↓
10. Terminal state set (COMPLETED or FAILED)
```

### Branching Logic

The service branches internally based on file type:

```php
// In generateThumbnail()
switch ($fileType) {
    case 'image':
        return $this->generateImageThumbnail(...); // Existing logic
    case 'pdf':
        return $this->generatePdfThumbnail(...); // New PDF logic
    // ... other types
}
```

This ensures:
- Image processing remains unchanged
- PDF processing is additive
- No refactoring of existing logic

### Storage Structure

PDF thumbnails use the same storage structure as images:

```
{asset_path_base}/thumbnails/{style}/{filename}
```

Example:
```
temp/uploads/{session_id}/thumbnails/thumb/thumb.jpg
temp/uploads/{session_id}/thumbnails/medium/medium.jpg
temp/uploads/{session_id}/thumbnails/large/large.jpg
```

## Safety Guarantees

### Terminal State Guarantees (Maintained)

- ✅ Every asset reaches exactly one terminal state (COMPLETED, FAILED, SKIPPED)
- ✅ PDF failures produce terminal metadata (`failed_at`, `error_reason`)
- ✅ Timeout protection applies to PDFs (5-minute guard)
- ✅ Asset.status is never mutated (visibility only)

### Retry Guarantees (Maintained)

- ✅ PDF thumbnails are retryable
- ✅ Retry limits apply equally (max 3 attempts)
- ✅ Each retry re-extracts page 1 only (consistent behavior)
- ✅ Retry attempts are logged and tracked

### Pipeline Guarantees (Maintained)

- ✅ No refactoring of existing jobs
- ✅ No changes to queue orchestration
- ✅ No realtime updates introduced
- ✅ Grid thumbnails update only on page refresh

## Error Handling

PDF processing failures produce clear error messages:

- **File size exceeded**: "PDF file size ({size} bytes) exceeds maximum allowed size ({max} bytes)"
- **Corrupted PDF**: "Invalid PDF format or corrupted file"
- **Missing package**: "PDF thumbnail generation requires spatie/pdf-to-image package"
- **Processing timeout**: Handled by existing timeout guard (5 minutes)
- **Memory issues**: Caught and logged with exception details

All errors result in `thumbnail_status = FAILED` with `thumbnail_error` populated.

## Configuration

### Environment Variables

```bash
# PDF file size limit (bytes, default: 100MB)
THUMBNAIL_PDF_MAX_SIZE_BYTES=104857600

# Maximum page to process (default: 1, always enforced)
THUMBNAIL_PDF_MAX_PAGE=1

# Processing timeout (seconds, default: 60)
THUMBNAIL_PDF_TIMEOUT_SECONDS=60
```

### Production Recommendations

For production environments, consider:

1. **Lower file size limits** for high-volume systems
2. **Shorter timeouts** to prevent resource exhaustion
3. **Monitoring** for PDF processing failures
4. **Validation** of PDFs before upload (client-side or pre-upload)

## Testing

### Manual Testing

1. Upload a PDF asset
2. Verify thumbnail generation job is dispatched
3. Check logs for PDF processing
4. Verify thumbnails appear in drawer (after polling)
5. Verify thumbnails appear in grid (after page refresh)

### Verification Command

```bash
./vendor/bin/sail artisan pdf:verify
```

This verifies:
- PHP Imagick extension
- ImageMagick command-line tool
- Ghostscript installation
- ImageMagick PDF policy
- Spatie package availability
- Actual PDF conversion test

## Dependencies

### Required

- `spatie/pdf-to-image` (Composer package)
- ImageMagick (system package)
- Ghostscript (system package)
- PHP Imagick extension (already in Sail)

### Optional

- Poppler-utils (fallback tools, not required)

## Future Enhancements (Deferred)

The following are explicitly **NOT** implemented:

1. **Multi-page PDF previews** - Currently page 1 only
2. **PDF text extraction** - Thumbnail generation only
3. **PDF metadata extraction** - Separate feature
4. **Admin overrides** - No bypass for PDF limits yet

## Code Locations

- **PDF Generation**: `app/Services/ThumbnailGenerationService.php::generatePdfThumbnail()`
- **File Type Detection**: `app/Services/ThumbnailGenerationService.php::detectFileType()`
- **Support Check**: `app/Jobs/GenerateThumbnailsJob.php::supportsThumbnailGeneration()`
- **Retry Support**: `app/Services/ThumbnailRetryService.php::isFileTypeSupported()`
- **Config**: `config/assets.php` (PDF limits)

## Important Notes

✅ **PDF support is additive** - does not modify existing image processing  
✅ **All guarantees maintained** - terminal states, retry compatibility, pipeline structure  
✅ **Safety guards enforced** - file size, timeout, page limit  
✅ **Documentation updated** - PDF support explicitly documented  

**Stability over features.**


---

## Thumbnail retry (design)

## Overview

This document outlines the design for a safe, additive feature that allows users to manually retry thumbnail generation from the asset drawer UI. The design prioritizes safety, auditability, and respect for existing system guarantees.

## Design Principles

1. **Additive Only**: No refactoring of existing thumbnail jobs or pipeline
2. **Status Immutability**: Asset.status must never be mutated by retry operations
3. **Non-Realtime**: Grid thumbnails only update on page refresh (existing behavior)
4. **Auditable**: All retry attempts are logged and tracked
5. **Safe Limits**: Retry limits prevent abuse and infinite loops
6. **File Type Validation**: Only supported file types can be retried

---

## 1. High-Level Architecture

### Flow Diagram

```
User clicks "Retry Thumbnails" in AssetDrawer
    ↓
Frontend validates: file type supported, retry limit not exceeded
    ↓
POST /app/assets/{id}/thumbnails/retry
    ↓
ThumbnailRetryService validates request
    ↓
ThumbnailRetryService checks retry limits
    ↓
ThumbnailRetryService dispatches GenerateThumbnailsJob
    ↓
GenerateThumbnailsJob processes (existing job, unchanged)
    ↓
Drawer polling detects completion (existing mechanism)
    ↓
User sees updated thumbnail on next poll (or page refresh)
```

### Key Components

1. **Frontend**: AssetDrawer component with retry button
2. **API Endpoint**: `POST /app/assets/{id}/thumbnails/retry`
3. **Service Layer**: `ThumbnailRetryService` (new)
4. **Job**: `GenerateThumbnailsJob` (existing, unchanged)
5. **Database**: New fields on `assets` table for retry tracking

---

## 2. New Services, Jobs, and Actions

### Services

- **`ThumbnailRetryService`** (`app/Services/ThumbnailRetryService.php`)
  - Validates retry eligibility
  - Enforces retry limits
  - Validates file type support
  - Dispatches `GenerateThumbnailsJob`
  - Records retry attempts in metadata
  - Logs retry events for audit trail

### Controllers

- **`AssetThumbnailController::retry()`** (new method)
  - Handles `POST /app/assets/{id}/thumbnails/retry`
  - Validates user permissions
  - Calls `ThumbnailRetryService`
  - Returns JSON response with retry status

### Actions (No new actions needed)

- Uses existing `GenerateThumbnailsJob` (no changes)
- Uses existing `ThumbnailGenerationService` (no changes)

---

## 3. Retry Limit Enforcement

### Limit Strategy

**Per-Asset Retry Limit**: Maximum 3 manual retries per asset (configurable via config)

**Enforcement Points**:

1. **Frontend (First Line of Defense)**
   - Button disabled if `thumbnail_retry_count >= 3`
   - Button disabled if `thumbnail_status === 'skipped'` (unsupported file type)
   - Button disabled if `thumbnail_status === 'processing'` (already in progress)

2. **Backend Service (Authoritative)**
   - `ThumbnailRetryService::canRetry()` checks:
     - `thumbnail_retry_count < max_retries` (default: 3)
     - `thumbnail_status !== 'skipped'` (unsupported file type)
     - `thumbnail_status !== 'processing'` (already in progress)
     - File type is supported (uses same logic as `GenerateThumbnailsJob::supportsThumbnailGeneration()`)

3. **Database Constraint (Safety Net)**
   - Check constraint or application-level validation prevents exceeding limit
   - Metadata field `thumbnail_retry_count` tracks attempts

### Retry Count Tracking

- **Field**: `assets.thumbnail_retry_count` (integer, default: 0)
- **Increment**: Only on successful retry dispatch (not on job failure)
- **Reset**: Never (permanent audit trail)
- **Metadata**: Also stored in `metadata['thumbnail_retries']` array with timestamps

### Rate Limiting (Optional Future Enhancement)

- Consider per-user rate limiting (e.g., max 10 retries per hour)
- Consider per-tenant rate limiting (e.g., max 100 retries per hour)
- **Not in initial implementation** - can be added later if abuse occurs

---

## 4. Supported File Type Validation

### Validation Logic

**Reuse Existing Logic**: Use the same validation as `GenerateThumbnailsJob::supportsThumbnailGeneration()`

**Supported Types**:
- JPEG/JPG (`image/jpeg`, `image/jpg`)
- PNG (`image/png`)
- GIF (`image/gif`)
- WEBP (`image/webp`)

**Excluded Types** (will be rejected):
- TIFF/TIF (`image/tiff`, `image/tif`) - GD library limitation
- AVIF (`image/avif`) - Backend pipeline limitation
- BMP (`image/bmp`) - GD library limitation
- SVG (`image/svg+xml`) - GD library limitation

### Validation Points

1. **Frontend**: Button hidden/disabled for unsupported file types
2. **Backend Service**: `ThumbnailRetryService::isFileTypeSupported()` validates before dispatch
3. **Job**: Existing `GenerateThumbnailsJob` already validates (defensive check)

### Error Response

If file type is unsupported:
- HTTP 422 Unprocessable Entity
- Error message: "Thumbnail generation is not supported for this file type"
- Frontend shows error toast/alert

---

## 5. Metadata to Store on Asset

### New Database Fields

**Migration**: `add_thumbnail_retry_tracking_to_assets_table`

```php
Schema::table('assets', function (Blueprint $table) {
    $table->unsignedInteger('thumbnail_retry_count')->default(0)->after('thumbnail_started_at');
    $table->timestamp('thumbnail_last_retry_at')->nullable()->after('thumbnail_retry_count');
});
```

**Fields**:
- `thumbnail_retry_count` (integer, default: 0)
  - Tracks total number of manual retry attempts
  - Incremented only when retry is successfully dispatched
  - Never reset (permanent audit trail)

- `thumbnail_last_retry_at` (timestamp, nullable)
  - Timestamp of most recent retry attempt
  - Updated on each retry dispatch
  - Used for rate limiting (future) and audit queries

### Metadata JSON Structure

**Existing metadata field** (`assets.metadata` JSON column):

```json
{
  "thumbnail_retries": [
    {
      "attempted_at": "2024-01-15T10:30:00Z",
      "triggered_by_user_id": "uuid-here",
      "previous_status": "failed",
      "job_dispatched": true,
      "job_id": "job-uuid-here"
    },
    {
      "attempted_at": "2024-01-15T11:00:00Z",
      "triggered_by_user_id": "uuid-here",
      "previous_status": "failed",
      "job_dispatched": true,
      "job_id": "job-uuid-here"
    }
  ],
  "thumbnail_retry_limit_reached": false
}
```

**Metadata Updates**:
- Append to `thumbnail_retries` array on each retry
- Set `thumbnail_retry_limit_reached` to `true` when limit exceeded
- Never remove entries (append-only audit log)

---

## 6. Failure Modes and UI Surface

### Failure Scenarios

#### 1. Retry Limit Exceeded
- **HTTP Status**: 429 Too Many Requests (or 422 Unprocessable Entity)
- **Response**: `{ "error": "Maximum retry attempts (3) exceeded for this asset" }`
- **UI**: Button disabled, tooltip shows "Retry limit reached (3/3 attempts used)"
- **User Action**: None (limit is permanent per asset)

#### 2. Unsupported File Type
- **HTTP Status**: 422 Unprocessable Entity
- **Response**: `{ "error": "Thumbnail generation is not supported for this file type" }`
- **UI**: Button hidden or disabled with tooltip "Unsupported file type"
- **User Action**: None (file type cannot be changed)

#### 3. Already Processing
- **HTTP Status**: 409 Conflict
- **Response**: `{ "error": "Thumbnail generation is already in progress" }`
- **UI**: Button disabled, shows "Processing..." state
- **User Action**: Wait for current job to complete

#### 4. Asset Not Found
- **HTTP Status**: 404 Not Found
- **Response**: `{ "error": "Asset not found" }`
- **UI**: Error toast, drawer may close
- **User Action**: Refresh page

#### 5. Permission Denied
- **HTTP Status**: 403 Forbidden
- **Response**: `{ "error": "You do not have permission to retry thumbnails for this asset" }`
- **UI**: Button hidden or disabled
- **User Action**: Contact admin

#### 6. Job Dispatch Failure
- **HTTP Status**: 500 Internal Server Error
- **Response**: `{ "error": "Failed to dispatch thumbnail generation job" }`
- **UI**: Error toast with retry option
- **User Action**: Retry request (does not count toward limit if dispatch fails)

### UI States

#### Button States in AssetDrawer

1. **Available** (enabled, clickable)
   - Condition: `thumbnail_status === 'failed'` AND `thumbnail_retry_count < 3` AND file type supported
   - Label: "Retry Thumbnail Generation"
   - Icon: ArrowPathIcon (refresh icon)

2. **Disabled - Limit Reached**
   - Condition: `thumbnail_retry_count >= 3`
   - Label: "Retry Limit Reached"
   - Tooltip: "Maximum retry attempts (3/3) exceeded for this asset"

3. **Disabled - Unsupported Type**
   - Condition: `thumbnail_status === 'skipped'` OR file type not supported
   - Label: "Unsupported File Type"
   - Tooltip: "Thumbnail generation is not supported for this file type"

4. **Disabled - Processing**
   - Condition: `thumbnail_status === 'processing'`
   - Label: "Processing..."
   - Tooltip: "Thumbnail generation is already in progress"

5. **Hidden**
   - Condition: `thumbnail_status === 'completed'` (thumbnails already exist)
   - No button shown (success state)

### Error Display

- **Toast Notifications**: For transient errors (500, network failures)
- **Inline Error**: For permanent errors (422, 429) shown in drawer
- **Activity Timeline**: Retry attempts logged as events (existing mechanism)

---

## 7. Explicit Callouts: What NOT to Change

### ❌ DO NOT Modify

1. **`GenerateThumbnailsJob`**
   - Do not add retry-specific logic
   - Do not change job behavior based on retry context
   - Job should be agnostic to whether it's initial generation or retry

2. **`ThumbnailGenerationService`**
   - Do not modify thumbnail generation logic
   - Do not add retry-specific parameters

3. **`Asset.status` Mutations**
   - Never mutate `Asset.status` in retry flow
   - `Asset.status` represents visibility only (VISIBLE/HIDDEN/FAILED)
   - Retry operations must not change visibility

4. **Grid Thumbnail Updates**
   - Do not add live updates to grid thumbnails
   - Grid thumbnails only update on page refresh (by design)
   - Drawer polling is isolated and does not affect grid

5. **Queue Orchestration**
   - Do not change how jobs are dispatched in normal upload flow
   - Do not modify `ProcessAssetJob` or job chaining
   - Retry is a separate, independent dispatch

6. **Thumbnail Status Enum**
   - Do not add new status values (PENDING, PROCESSING, COMPLETED, FAILED, SKIPPED are sufficient)
   - Retry uses existing status values

7. **Existing Validation Logic**
   - Do not duplicate file type validation
   - Reuse `GenerateThumbnailsJob::supportsThumbnailGeneration()`

### ✅ Safe to Add

1. New database fields (`thumbnail_retry_count`, `thumbnail_last_retry_at`)
2. New service (`ThumbnailRetryService`)
3. New controller method (`AssetThumbnailController::retry()`)
4. New route (`POST /app/assets/{id}/thumbnails/retry`)
5. Frontend retry button in `AssetDrawer`
6. Metadata tracking in `assets.metadata` JSON field
7. Activity event logging (using existing `ActivityRecorder`)

---

## 8. Audit Trail and Observability

### Activity Events

**New Event Type**: `EventType::ASSET_THUMBNAIL_RETRY_REQUESTED`

**Event Metadata**:
```json
{
  "retry_count": 1,
  "previous_status": "failed",
  "triggered_by_user_id": "uuid-here",
  "file_type": "image/jpeg",
  "job_id": "job-uuid-here"
}
```

**Logging**:
- Log retry request in `ActivityRecorder::logAsset()`
- Log retry dispatch in `ThumbnailRetryService`
- Log retry limit enforcement in service

### Database Queries

**Find assets with retry attempts**:
```sql
SELECT * FROM assets WHERE thumbnail_retry_count > 0;
```

**Find assets at retry limit**:
```sql
SELECT * FROM assets WHERE thumbnail_retry_count >= 3;
```

**Audit retry history**:
```sql
SELECT id, thumbnail_retry_count, thumbnail_last_retry_at, metadata->'$.thumbnail_retries' 
FROM assets 
WHERE JSON_LENGTH(metadata->'$.thumbnail_retries') > 0;
```

---

## 9. Permissions and Authorization

### Permission Check

**Required Permission**: `assets.retry_thumbnails` (new permission)

**Default Assignment**:
- Tenant owners: ✅ Allowed
- Tenant admins: ✅ Allowed
- Brand managers: ✅ Allowed
- Brand editors: ✅ Allowed (if they can view asset)
- Brand viewers: ❌ Not allowed (read-only)

**Policy**: `AssetPolicy::retryThumbnails(Asset $asset)`

**Authorization Flow**:
1. Check user has `assets.retry_thumbnails` permission for tenant
2. Check user can view asset (existing `AssetPolicy::view()`)
3. Check asset belongs to user's active tenant/brand

---

## 10. Configuration

### Config File: `config/assets.php`

**New Settings**:
```php
'thumbnail_retry' => [
    'max_attempts' => env('THUMBNAIL_MAX_RETRIES', 3),
    'rate_limit_per_user' => env('THUMBNAIL_RETRY_RATE_LIMIT_USER', null), // Optional
    'rate_limit_per_tenant' => env('THUMBNAIL_RETRY_RATE_LIMIT_TENANT', null), // Optional
],
```

---

## 11. Testing Considerations

### Unit Tests

- `ThumbnailRetryService::canRetry()` - limit enforcement
- `ThumbnailRetryService::isFileTypeSupported()` - file type validation
- `ThumbnailRetryService::dispatchRetry()` - job dispatch

### Integration Tests

- Retry endpoint returns 422 for unsupported file types
- Retry endpoint returns 429 when limit exceeded
- Retry endpoint dispatches job successfully
- Retry count increments correctly
- Metadata updates correctly

### Manual Testing

- Test retry button appears for failed thumbnails
- Test retry button disabled at limit
- Test retry button hidden for unsupported types
- Test retry works end-to-end
- Test drawer polling detects completion
- Test grid does NOT update until page refresh

---

## 12. Implementation Checklist

### Backend

- [ ] Create migration: `add_thumbnail_retry_tracking_to_assets_table`
- [ ] Create `ThumbnailRetryService`
- [ ] Add `AssetThumbnailController::retry()` method
- [ ] Add route: `POST /app/assets/{id}/thumbnails/retry`
- [ ] Add permission: `assets.retry_thumbnails`
- [ ] Add policy method: `AssetPolicy::retryThumbnails()`
- [ ] Add event type: `EventType::ASSET_THUMBNAIL_RETRY_REQUESTED`
- [ ] Add config: `config/assets.php` retry settings
- [ ] Add tests for service
- [ ] Add tests for controller

### Frontend

- [ ] Add retry button to `AssetDrawer` component
- [ ] Add retry API call in `AssetDrawer`
- [ ] Add button state logic (enabled/disabled/hidden)
- [ ] Add error handling and toast notifications
- [ ] Add loading state during retry dispatch
- [ ] Update `AssetTimeline` to show retry events (if needed)
- [ ] Test all button states
- [ ] Test error scenarios

### Documentation

- [ ] Update `MEDIA_PIPELINE.md` with retry feature
- [ ] Add API documentation for retry endpoint
- [ ] Update user-facing docs (if applicable)

---

## 13. Future Enhancements (Deferred)

These features are explicitly **NOT** in the initial implementation:

1. **Bulk Retry**: Retry multiple assets at once
2. **Admin Override**: Admin can bypass retry limits
3. **Retry Scheduling**: Schedule retries for later
4. **Retry Analytics**: Dashboard showing retry patterns
5. **Auto-Retry**: Automatic retry on failure (with exponential backoff)
6. **Webhook Notifications**: Notify external systems on retry

---

## Summary

This design provides a safe, additive feature that:
- ✅ Respects existing system constraints
- ✅ Does not modify locked thumbnail pipeline
- ✅ Enforces retry limits to prevent abuse
- ✅ Validates file types before retry
- ✅ Provides comprehensive audit trail
- ✅ Handles all failure modes gracefully
- ✅ Maintains non-realtime grid behavior
- ✅ Never mutates `Asset.status`

The implementation is straightforward, testable, and maintains the stability guarantees of the existing system.
