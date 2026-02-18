# AVIF and WebP Thumbnail Support

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
- `TIFF_PREVIEW_SUPPORT.md` - Similar implementation for TIFF

## Future Enhancements

- **Progressive WebP**: Support for progressive WebP encoding
- **AVIF Output**: Option to output thumbnails as AVIF (even better compression)
- **Format Detection**: Automatic format selection based on source image
- **Quality Optimization**: Per-style quality settings for WebP vs JPEG