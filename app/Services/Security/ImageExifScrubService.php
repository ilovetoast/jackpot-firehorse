<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Log;

/**
 * Phase 6: Strip privacy-leaking EXIF / IPTC / XMP metadata from images
 * before they go to the brand asset library.
 *
 * Why this matters for a brand asset manager:
 *   - JPEGs from phones embed GPS coordinates, camera serial numbers,
 *     and even the photographer's iCloud account in some cases. Brand
 *     assets get distributed externally; we should not leak any of that.
 *   - Some EXIF is benign and *useful* downstream (Orientation tag —
 *     without it, thumbnails render rotated wrong). We selectively keep
 *     those tags via Imagick's profile preservation API.
 *
 * Strategy:
 *   - JPEG and TIFF: open in Imagick, strip all profiles, then re-attach
 *     just the orientation + ICC color profile so renderers behave.
 *   - PNG: strip iTXt/tEXt/zTXt chunks via Imagick.
 *   - WebP: Imagick's webp loader supports profile stripping the same way.
 *   - Anything else: no-op (we don't want to mutate vector / raw / design
 *     formats here; SVG has its own SvgSanitizationService).
 *
 * Returns the cleaned bytes when modifications were made, null otherwise.
 * If Imagick isn't installed we log + no-op (the upload still goes through;
 * the brand library has worked without scrubbing for a long time, so we
 * fail open rather than fail closed on tooling absence).
 */
class ImageExifScrubService
{
    public function scrub(string $bytes, string $canonicalMime): ?string
    {
        $cfg = (array) config('assets.security.exif_scrub', []);
        if (! ($cfg['enabled'] ?? true)) {
            return null;
        }

        if (! extension_loaded('imagick') || ! class_exists(\Imagick::class)) {
            // Without Imagick we can't safely re-encode JPEGs without loss.
            // Skip silently — the upload still completes; ops can install
            // ext-imagick to enable scrubbing.
            return null;
        }

        if (! $this->isScrubbableMime($canonicalMime)) {
            return null;
        }

        try {
            $img = new \Imagick();
            $img->readImageBlob($bytes);

            // Imagick stores 'orientation', 'exif:*', 'iptc:*', 'xmp:*'
            // properties; profiles include 'exif', 'iptc', 'xmp', 'icc',
            // '8bim'. We strip everything, then optionally restore orientation
            // and ICC.
            $orientation = $img->getImageOrientation();
            $iccProfile = null;
            try {
                $iccProfile = $img->getImageProfile('icc');
            } catch (\Throwable $e) {
                $iccProfile = null;
            }

            // Wipe all metadata blocks.
            $img->stripImage();

            if ($orientation && $orientation !== \Imagick::ORIENTATION_UNDEFINED) {
                // Re-stamp orientation so renderers don't render upside down.
                $img->setImageOrientation($orientation);
            }
            if ($iccProfile) {
                // Color profile is required for accurate brand colors —
                // restore it explicitly. NB: ICC is not "EXIF metadata"
                // in the privacy sense; it's a color management tag.
                try {
                    $img->profileImage('icc', $iccProfile);
                } catch (\Throwable $e) {
                    // Old Imagick builds choke on some ICC blobs — accept
                    // the loss and move on.
                }
            }

            $clean = $img->getImageBlob();
            $img->clear();

            if ($clean === '' || $clean === false) {
                return null;
            }

            return $clean;
        } catch (\Throwable $e) {
            Log::warning('[ImageExifScrubService] scrub failed — leaving bytes unchanged', [
                'mime' => $canonicalMime,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function isScrubbableMime(string $mime): bool
    {
        return match ($mime) {
            'image/jpeg', 'image/png', 'image/webp', 'image/tiff' => true,
            default => false,
        };
    }
}
