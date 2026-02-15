# Thumbnail Pipeline Documentation

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
Upload → ProcessAssetJob → GenerateThumbnailsJob → FinalizeAssetJob
```

## Supported File Types

The thumbnail pipeline supports the following file types:

The thumbnail pipeline supports the following file types:

### Images (via GD library)
- **JPEG/JPG** (`image/jpeg`, `image/jpg`) - Full support
- **PNG** (`image/png`) - Full support with transparency
- **GIF** (`image/gif`) - Full support with transparency
- **WEBP** (`image/webp`) - Full support

**Excluded image types:**
- TIFF/TIF - Requires Imagick (not supported)
- AVIF - Backend pipeline does not support AVIF yet
- BMP - GD library has limited BMP support, not reliable
- SVG - GD library does not support SVG (requires Imagick)

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
- **thumb**, **medium**, **large**: Standard styles; white logos get gray background for visibility in pickers
- **medium_display**: Same as medium but preserves transparency (no gray block). Used for public page logo and theme preview. Existing assets need thumbnail regeneration to get this style.

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

Use the Artisan command to manually repair stuck assets:

```bash
php artisan thumbnails:repair-stuck
```

Or with dry-run to preview:

```bash
php artisan thumbnails:repair-stuck --dry-run
```

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

# Or via Docker Compose (see QUEUE_WORKERS.md)
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
