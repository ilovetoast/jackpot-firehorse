# TIFF Image Preview Support

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