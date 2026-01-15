# PDF Thumbnail Generation Implementation

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

**Updated:** `docs/THUMBNAIL_PIPELINE.md`

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
