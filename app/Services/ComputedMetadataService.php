<?php

namespace App\Services;

use App\Models\Asset;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computed Metadata Service
 *
 * Phase 5: Automatically populates technical system metadata fields
 * from asset files and thumbnails, including EXIF and file analysis.
 *
 * Rules:
 * - Only populates existing system fields (orientation, color_space, resolution_class)
 * - Never overwrites user-entered values
 * - Always creates new rows (never updates)
 * - System-sourced (source = 'system')
 * - Deterministic and repeatable
 */
class ComputedMetadataService
{
    /**
     * Compute and persist metadata for an asset.
     *
     * @param Asset $asset
     * @return void
     */
    public function computeMetadata(Asset $asset): void
    {
        Log::info('[ComputedMetadataService] computeMetadata called', [
            'asset_id' => $asset->id,
            'original_filename' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
        ]);
        
        // Process image assets (original dimensions) or thumbnail-derived metadata (fallback)
        if (!$this->isImageAsset($asset) && !$asset->visualMetadataReady()) {
            return;
        }

        // Get image dimensions and EXIF data (original first, thumbnail fallback for SVG/PDF/video)
        $imageData = $this->extractImageData($asset);
        if (!$imageData) {
            Log::warning('[ComputedMetadataService] Could not extract image data', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Compute values for each field
        // CRITICAL: Orientation is computed from original file dimensions (after EXIF normalization)
        $orientation = $this->computeOrientation($imageData['width'], $imageData['height']);
        
        // TEMPORARY DEBUG LOGGING (dev only - can be removed later)
        if (config('app.debug', false) && $orientation) {
            Log::debug('[ComputedMetadataService] Final orientation computed', [
                'asset_id' => $asset->id,
                'width' => $imageData['width'],
                'height' => $imageData['height'],
                'orientation' => $orientation,
                'ratio' => $imageData['height'] > 0 ? round($imageData['width'] / $imageData['height'], 4) : 0,
            ]);
        }
        
        $colorSpace = $this->computeColorSpace($imageData['exif'] ?? []);
        $resolutionClass = $this->computeResolutionClass($imageData['width'], $imageData['height']);
        $dimensions = $this->computeDimensions($imageData['width'], $imageData['height']);
        
        $computedValues = [
            'orientation' => $orientation,
            'color_space' => $colorSpace,
            'resolution_class' => $resolutionClass,
            'dimensions' => $dimensions,
        ];

        // Persist computed metadata
        $this->persistComputedMetadata($asset, $computedValues);
    }

    /**
     * Check if asset is an image.
     *
     * Uses FileTypeService to determine if asset is an image type.
     *
     * @param Asset $asset
     * @return bool
     */
    protected function isImageAsset(Asset $asset): bool
    {
        $fileTypeService = app(\App\Services\FileTypeService::class);
        $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
        
        // Check if it's an image type (image, tiff, avif)
        return in_array($fileType, ['image', 'tiff', 'avif']);
    }

    /**
     * Extract image dimensions and EXIF data.
     *
     * Tries original file first (image/tiff/avif). Falls back to thumbnail-derived
     * dimensions for SVG/PDF/video when original getimagesize fails.
     *
     * @param Asset $asset
     * @return array|null Array with 'width', 'height', 'exif' keys, or null on failure
     */
    protected function extractImageData(Asset $asset): ?array
    {
        $data = $this->extractImageDataFromOriginal($asset);
        if (!$data && $asset->visualMetadataReady()) {
            $data = $this->extractImageDataFromThumbnail($asset);
        }

        return $data;
    }

    /**
     * Extract image data from original file (image/tiff/avif only).
     *
     * Uses getimagesize() on the original file. Returns null for PDF/video/SVG.
     *
     * @param Asset $asset
     * @return array|null Array with 'width', 'height', 'exif' keys, or null on failure
     */
    protected function extractImageDataFromOriginal(Asset $asset): ?array
    {
        if (!$this->isImageAsset($asset)) {
            return null;
        }

        try {
            $bucket = $asset->storageBucket;
            if (!$bucket || !$asset->storage_root_path) {
                Log::warning('[ComputedMetadataService] Missing storage info', [
                    'asset_id' => $asset->id,
                ]);
                return null;
            }

            $originalPath = $asset->storage_root_path;
            if (stripos($originalPath, 'thumbnail') !== false || stripos($originalPath, 'thumb') !== false) {
                Log::error('[ComputedMetadataService] CRITICAL: storage_root_path appears to be a thumbnail, not original', [
                    'asset_id' => $asset->id,
                    'storage_path' => $originalPath,
                ]);
            }

            $tempPath = $this->downloadFromS3($bucket, $originalPath);
            if (!file_exists($tempPath)) {
                Log::warning('[ComputedMetadataService] Could not download original file', [
                    'asset_id' => $asset->id,
                    'storage_path' => $originalPath,
                ]);
                return null;
            }

            $imageInfo = @getimagesize($tempPath);
            if (!$imageInfo || !isset($imageInfo[0], $imageInfo[1])) {
                Log::warning('[ComputedMetadataService] Could not read image dimensions from original file', [
                    'asset_id' => $asset->id,
                    'temp_path' => $tempPath,
                ]);
                @unlink($tempPath);
                return null;
            }

            $storedWidth = (int) $imageInfo[0];
            $storedHeight = (int) $imageInfo[1];

            $exif = [];
            $exifOrientation = null;
            if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($tempPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'tiff', 'tif'])) {
                $exifData = @exif_read_data($tempPath);
                if ($exifData !== false) {
                    $exif = $exifData;
                    if (isset($exif['Orientation'])) {
                        $exifOrientation = (int) $exif['Orientation'];
                    } elseif (isset($exif['IFD0']['Orientation'])) {
                        $exifOrientation = (int) $exif['IFD0']['Orientation'];
                    }
                }
            }

            [$width, $height] = $this->normalizeDimensionsFromExif($storedWidth, $storedHeight, $exif);

            if (config('app.debug', false)) {
                Log::debug('[ComputedMetadataService] Orientation computation debug', [
                    'asset_id' => $asset->id,
                    'original_path' => $originalPath,
                    'stored_width' => $storedWidth,
                    'stored_height' => $storedHeight,
                    'exif_orientation' => $exifOrientation,
                    'normalized_width' => $width,
                    'normalized_height' => $height,
                    'aspect_ratio' => $height > 0 ? round($width / $height, 4) : 0,
                ]);
            }

            @unlink($tempPath);

            return [
                'width' => $width,
                'height' => $height,
                'exif' => $exif,
            ];
        } catch (\Exception $e) {
            Log::error('[ComputedMetadataService] Error extracting image data from original', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Extract image data from persisted thumbnail dimensions (no S3 download).
     *
     * Used for SVG/PDF/video when original getimagesize fails. Requires
     * thumbnail_status === completed and thumbnail_dimensions.medium.
     *
     * @param Asset $asset
     * @return array|null Array with 'width', 'height', 'exif' keys, or null on failure
     */
    protected function extractImageDataFromThumbnail(Asset $asset): ?array
    {
        if (!$asset->visualMetadataReady()) {
            return null;
        }

        $dimensions = $asset->thumbnailDimensions('medium');
        if (!$dimensions || !isset($dimensions['width'], $dimensions['height'])) {
            return null;
        }

        $width = (int) $dimensions['width'];
        $height = (int) $dimensions['height'];

        // Sanity guard: reject corrupted/edge-case thumbnails (empty frame, zero SVG, Imagick glitch)
        if ($width < 5 || $height < 5) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
            'exif' => [],
        ];
    }

    /**
     * Download file from S3 to temporary location.
     *
     * @param object $bucket
     * @param string $s3Path
     * @return string Temporary file path
     * @throws \RuntimeException If download fails
     */
    protected function downloadFromS3($bucket, string $s3Path): string
    {
        // Use same S3 client creation pattern as ThumbnailGenerationService
        // This ensures consistency and handles MinIO/local development
        if (!class_exists(\Aws\S3\S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }
        
        $region = $bucket->region ?? config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1'));

        $config = [
            'version' => 'latest',
            'region' => $region,
        ];
        if (!empty($bucket->endpoint)) {
            $config['endpoint'] = $bucket->endpoint;
            $config['use_path_style_endpoint'] = $bucket->use_path_style_endpoint ?? true;
        } elseif (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        try {
            $s3Client = new \Aws\S3\S3Client($config);
        } catch (\Exception $e) {
            Log::error('[ComputedMetadataService] Failed to create S3 client', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        try {
            $result = $s3Client->getObject([
                'Bucket' => $bucket->name,
                'Key' => $s3Path,
            ]);

            $body = $result['Body'];
            $bodyContents = (string) $body;
            $contentLength = strlen($bodyContents);

            if ($contentLength === 0) {
                throw new \RuntimeException("Downloaded file from S3 is empty (size: 0 bytes)");
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'computed_metadata_');
            file_put_contents($tempPath, $bodyContents);

            // Verify file was written correctly
            if (!file_exists($tempPath) || filesize($tempPath) !== $contentLength) {
                @unlink($tempPath);
                throw new \RuntimeException("Failed to write downloaded file to temp location");
            }

            return $tempPath;
        } catch (S3Exception $e) {
            Log::error('[ComputedMetadataService] Failed to download file from S3', [
                'bucket' => $bucket->name,
                'key' => $s3Path,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to download file from S3: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Normalize dimensions based on EXIF orientation.
     *
     * CRITICAL: EXIF orientation is used ONLY to swap width/height when the image
     * is stored rotated (orientations 6 and 8). We NEVER use EXIF width/height directly.
     *
     * EXIF orientation values:
     * 1 = Normal (0°) - no swap
     * 2 = Horizontal flip - no swap
     * 3 = 180° rotation - no swap
     * 4 = Vertical flip - no swap
     * 5 = 90° CW + horizontal flip - no swap (flip doesn't affect dimensions)
     * 6 = 90° CW rotation - SWAP width/height
     * 7 = 90° CCW + horizontal flip - no swap (flip doesn't affect dimensions)
     * 8 = 90° CCW rotation - SWAP width/height
     *
     * @param int $storedWidth Width from getimagesize() on original file
     * @param int $storedHeight Height from getimagesize() on original file
     * @param array $exif EXIF data array
     * @return array{0: int, 1: int} [width, height] after orientation normalization
     */
    protected function normalizeDimensionsFromExif(int $storedWidth, int $storedHeight, array $exif): array
    {
        // Get EXIF orientation (if available)
        // EXIF orientation is SECONDARY - we use it only to correct for stored rotation
        $orientation = null;
        if (isset($exif['Orientation'])) {
            $orientation = (int) $exif['Orientation'];
        } elseif (isset($exif['IFD0']['Orientation'])) {
            $orientation = (int) $exif['IFD0']['Orientation'];
        }

        // If no EXIF orientation or orientation is 1 (normal), return stored dimensions as-is
        // These are the authoritative pixel dimensions from getimagesize()
        if ($orientation === null || $orientation === 1) {
            return [$storedWidth, $storedHeight];
        }

        // ONLY orientations 6 and 8 require swapping width/height
        // These represent 90° rotations where the image is stored rotated in the file
        // The pixel data is rotated, so we swap dimensions to get the visual dimensions
        if ($orientation === 6 || $orientation === 8) {
            return [$storedHeight, $storedWidth];
        }

        // All other orientations (2, 3, 4, 5, 7) don't require dimension swap
        // They're flips or 180° rotations that don't change the aspect ratio
        return [$storedWidth, $storedHeight];
    }

    /**
     * Compute orientation from dimensions using ratio-based classification.
     *
     * CRITICAL: This uses the normalized dimensions (after EXIF correction) from the
     * original image file. The ratio-based approach avoids floating-point precision
     * issues and handles near-square images correctly.
     *
     * Classification rules:
     * - ratio >= 0.95 AND ratio <= 1.05 → square (allows for near-square images)
     * - ratio > 1.05 → landscape (wider than tall)
     * - ratio < 0.95 → portrait (taller than wide)
     *
     * @param int $width Visual width after EXIF normalization (from original file)
     * @param int $height Visual height after EXIF normalization (from original file)
     * @return string|null 'landscape', 'portrait', 'square', or null if cannot determine
     */
    protected function computeOrientation(int $width, int $height): ?string
    {
        if ($width === 0 || $height === 0) {
            Log::warning('[ComputedMetadataService] Cannot compute orientation - zero dimension', [
                'width' => $width,
                'height' => $height,
            ]);
            return null;
        }

        // Calculate aspect ratio (width / height)
        // Use float for precision, but ratio-based thresholds avoid floating-point equality issues
        $ratio = (float) $width / (float) $height;

        // Ratio-based classification (allows for near-square images)
        // 0.95-1.05 range accounts for rounding, slight aspect variations, and resize artifacts
        // This prevents false "square" classifications for clearly non-square images
        if ($ratio >= 0.95 && $ratio <= 1.05) {
            return 'square';
        } elseif ($ratio > 1.05) {
            return 'landscape';
        } else {
            // ratio < 0.95
            return 'portrait';
        }
    }

    /**
     * Compute color space from EXIF data.
     *
     * @param array $exif
     * @return string|null 'srgb', 'adobe_rgb', 'display_p3', or null if unknown
     */
    protected function computeColorSpace(array $exif): ?string
    {
        // Check EXIF ColorSpace tag
        if (isset($exif['ColorSpace'])) {
            $colorSpace = $exif['ColorSpace'];
            // EXIF ColorSpace: 1 = sRGB, 65535 = Uncalibrated
            if ($colorSpace == 1) {
                return 'srgb';
            }
        }

        // Check ICC profile description if available
        if (isset($exif['ICC_Profile']['Description'])) {
            $description = strtolower($exif['ICC_Profile']['Description']);
            if (strpos($description, 'srgb') !== false || strpos($description, 's rgb') !== false) {
                return 'srgb';
            }
            if (strpos($description, 'adobe rgb') !== false) {
                return 'adobe_rgb';
            }
            if (strpos($description, 'display p3') !== false || strpos($description, 'display-p3') !== false) {
                return 'display_p3';
            }
        }

        // Check EXIF InteropIndex (some cameras use this)
        if (isset($exif['InteropIndex'])) {
            $interop = strtolower($exif['InteropIndex']);
            if (strpos($interop, 'r98') !== false) {
                return 'srgb'; // R98 = sRGB
            }
        }

        // Fallback: Default to sRGB if color space cannot be determined
        // Most images are sRGB by default, and this ensures color_space is always populated
        // This is a reasonable default for automatic metadata
        Log::debug('[ComputedMetadataService] Color space not found in EXIF, defaulting to sRGB', [
            'exif_keys' => array_keys($exif),
        ]);
        return 'srgb';
    }

    /**
     * Compute resolution class from pixel dimensions.
     *
     * @param int $width
     * @param int $height
     * @return string|null 'low', 'medium', 'high', 'ultra', or null if cannot determine
     */
    protected function computeResolutionClass(int $width, int $height): ?string
    {
        if ($width === 0 || $height === 0) {
            return null;
        }

        $megapixels = ($width * $height) / 1000000; // Convert to megapixels

        if ($megapixels < 1) {
            return 'low';
        } elseif ($megapixels < 4) {
            return 'medium';
        } elseif ($megapixels < 12) {
            return 'high';
        } else {
            return 'ultra';
        }
    }

    /**
     * Compute dimensions string from pixel dimensions.
     *
     * @param int $width
     * @param int $height
     * @return string|null Format: "widthxheight" (e.g., "800x534"), or null if cannot determine
     */
    protected function computeDimensions(int $width, int $height): ?string
    {
        if ($width === 0 || $height === 0) {
            return null;
        }

        return "{$width}x{$height}";
    }

    /**
     * Persist computed metadata values.
     *
     * @param Asset $asset
     * @param array $computedValues Keyed by field key
     * @return void
     */
    protected function persistComputedMetadata(Asset $asset, array $computedValues): void
    {
        DB::transaction(function () use ($asset, $computedValues) {
            foreach ($computedValues as $fieldKey => $value) {
                // Skip null values (unknown/unable to compute)
                if ($value === null) {
                    continue;
                }

                // Get field ID
                $field = DB::table('metadata_fields')
                    ->where('key', $fieldKey)
                    ->where('scope', 'system')
                    ->first();

                if (!$field) {
                    Log::warning('[ComputedMetadataService] Field not found', [
                        'asset_id' => $asset->id,
                        'field_key' => $fieldKey,
                    ]);
                    continue;
                }

                // Check if user-approved value already exists (never overwrite)
                $existingUserValue = DB::table('asset_metadata')
                    ->where('asset_id', $asset->id)
                    ->where('metadata_field_id', $field->id)
                    ->where('source', 'user')
                    ->whereNotNull('approved_at')
                    ->exists();

                if ($existingUserValue) {
                    continue;
                }

                // Check if system value already exists (idempotency)
                // If same value exists, skip to avoid duplicates
                $existingSystemValue = DB::table('asset_metadata')
                    ->where('asset_id', $asset->id)
                    ->where('metadata_field_id', $field->id)
                    ->where('source', 'system')
                    ->first();

                if ($existingSystemValue) {
                    // Compare values - only create new if different
                    $existingValue = json_decode($existingSystemValue->value_json, true);
                    if ($existingValue === $value) {
                        // Same value, skip (idempotency)
                        continue;
                    }
                    // Different value - create new row (never update existing)
                }

                // Validate value against field options (for select fields)
                if ($field->type === 'select') {
                    $isValid = DB::table('metadata_options')
                        ->where('metadata_field_id', $field->id)
                        ->where('value', $value)
                        ->exists();

                    if (!$isValid) {
                        Log::warning('[ComputedMetadataService] Invalid option value', [
                            'asset_id' => $asset->id,
                            'field_key' => $fieldKey,
                            'value' => $value,
                        ]);
                        continue;
                    }
                }

                // Create new asset_metadata row
                // CRITICAL: Check if this is an automatic field - automatic fields do NOT require approval
                $fieldDef = DB::table('metadata_fields')->where('id', $field->id)->first();
                $isAutomaticField = $fieldDef && ($fieldDef->population_mode === 'automatic');
                
                Log::debug('[ComputedMetadataService] Writing metadata field', [
                    'asset_id' => $asset->id,
                    'field_key' => $fieldKey,
                    'field_id' => $field->id,
                    'population_mode' => $fieldDef->population_mode ?? 'manual',
                    'is_automatic' => $isAutomaticField,
                    'value' => $value,
                ]);
                
                // Phase M-1: System and automatic metadata always auto-approve
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $field->id,
                    'value_json' => json_encode($value),
                    'source' => 'system',
                    'confidence' => 0.95, // System-computed values are highly confident
                    'producer' => 'system', // System-computed values are from system
                    'approved_at' => now(), // Phase M-1: System metadata always auto-approves
                    'approved_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Create audit history entry
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => null,
                    'new_value_json' => json_encode($value),
                    'source' => 'system',
                    'changed_by' => null,
                    'created_at' => now(),
                ]);

                Log::info('[ComputedMetadataService] Computed metadata persisted', [
                    'asset_id' => $asset->id,
                    'field_id' => $field->id,
                    'field_key' => $fieldKey,
                    'value' => $value,
                    'asset_metadata_id' => $assetMetadataId,
                ]);
            }
        });
    }
}
