# PDF Thumbnail Generation Setup

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
