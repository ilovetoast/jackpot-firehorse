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
        
        // Only process image assets
        if (!$this->isImageAsset($asset)) {
            return;
        }

        // Get image dimensions and EXIF data
        $imageData = $this->extractImageData($asset);
        if (!$imageData) {
            Log::warning('[ComputedMetadataService] Could not extract image data', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Compute values for each field
        $orientation = $this->computeOrientation($imageData['width'], $imageData['height']);
        $colorSpace = $this->computeColorSpace($imageData['exif'] ?? []);
        $resolutionClass = $this->computeResolutionClass($imageData['width'], $imageData['height']);
        
        $computedValues = [
            'orientation' => $orientation,
            'color_space' => $colorSpace,
            'resolution_class' => $resolutionClass,
        ];

        // Persist computed metadata
        $this->persistComputedMetadata($asset, $computedValues);
    }

    /**
     * Check if asset is an image.
     *
     * @param Asset $asset
     * @return bool
     */
    protected function isImageAsset(Asset $asset): bool
    {
        $mimeType = $asset->mime_type ?? '';
        if (str_starts_with($mimeType, 'image/')) {
            return true;
        }

        // Check by extension as fallback
        $filename = $asset->original_filename ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];

        return in_array($extension, $imageExtensions);
    }

    /**
     * Extract image dimensions and EXIF data.
     *
     * @param Asset $asset
     * @return array|null Array with 'width', 'height', 'exif' keys, or null on failure
     */
    protected function extractImageData(Asset $asset): ?array
    {
        try {
            // Get file path from S3
            $bucket = $asset->storageBucket;
            if (!$bucket || !$asset->storage_root_path) {
                Log::warning('[ComputedMetadataService] Missing storage info', [
                    'asset_id' => $asset->id,
                ]);
                return null;
            }

            // Download file to temporary location
            $tempPath = $this->downloadFromS3($bucket, $asset->storage_root_path);
            if (!file_exists($tempPath)) {
                Log::warning('[ComputedMetadataService] Could not download file', [
                    'asset_id' => $asset->id,
                    'storage_path' => $asset->storage_root_path,
                ]);
                return null;
            }

            // Get image dimensions
            $imageInfo = @getimagesize($tempPath);
            if (!$imageInfo || !isset($imageInfo[0], $imageInfo[1])) {
                Log::warning('[ComputedMetadataService] Could not read image dimensions', [
                    'asset_id' => $asset->id,
                ]);
                @unlink($tempPath);
                return null;
            }

            $storedWidth = $imageInfo[0];
            $storedHeight = $imageInfo[1];

            // Extract EXIF data (if available)
            $exif = [];
            if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($tempPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'tiff', 'tif'])) {
                $exifData = @exif_read_data($tempPath);
                if ($exifData !== false) {
                    $exif = $exifData;
                }
            }

            // Normalize dimensions based on EXIF orientation
            // EXIF orientation tells us how the image should be displayed, not how it's stored
            [$width, $height] = $this->normalizeDimensionsFromExif($storedWidth, $storedHeight, $exif);

            // Clean up temp file
            @unlink($tempPath);

            return [
                'width' => $width,
                'height' => $height,
                'exif' => $exif,
            ];
        } catch (\Exception $e) {
            Log::error('[ComputedMetadataService] Error extracting image data', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
        
        // Get credentials - prefer bucket, fall back to env
        $accessKey = !empty($bucket->access_key_id) ? $bucket->access_key_id : env('AWS_ACCESS_KEY_ID');
        $secretKey = !empty($bucket->secret_access_key) ? $bucket->secret_access_key : env('AWS_SECRET_ACCESS_KEY');
        $region = $bucket->region ?? env('AWS_DEFAULT_REGION', 'us-east-1');
        
        // Validate credentials are present
        if (empty($accessKey) || empty($secretKey)) {
            Log::error('[ComputedMetadataService] S3 credentials missing', [
                'bucket_id' => $bucket->id ?? 'unknown',
                'bucket_name' => $bucket->name ?? 'unknown',
                'has_bucket_key' => !empty($bucket->access_key_id),
                'has_bucket_secret' => !empty($bucket->secret_access_key),
                'has_env_key' => !empty(env('AWS_ACCESS_KEY_ID')),
                'has_env_secret' => !empty(env('AWS_SECRET_ACCESS_KEY')),
                'access_key_value' => $accessKey ? 'present' : 'missing',
                'secret_key_value' => $secretKey ? 'present' : 'missing',
            ]);
            throw new \RuntimeException('S3 credentials not available - bucket credentials and AWS env vars are both missing');
        }
        
        $config = [
            'version' => 'latest',
            'region' => $region,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ];
        
        // Support MinIO for local development
        if ($bucket->endpoint) {
            $config['endpoint'] = $bucket->endpoint;
            $config['use_path_style_endpoint'] = $bucket->use_path_style_endpoint ?? true;
        } elseif (env('AWS_ENDPOINT')) {
            $config['endpoint'] = env('AWS_ENDPOINT');
            $config['use_path_style_endpoint'] = env('AWS_USE_PATH_STYLE_ENDPOINT', true);
        }
        
        try {
            $s3Client = new \Aws\S3\S3Client($config);
        } catch (\Exception $e) {
            Log::error('[ComputedMetadataService] Failed to create S3 client', [
                'error' => $e->getMessage(),
                'config_keys' => array_keys($config),
                'credentials_keys' => array_keys($config['credentials']),
                'access_key_present' => !empty($accessKey),
                'secret_key_present' => !empty($secretKey),
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
     * EXIF orientation values:
     * 1 = Normal (0°)
     * 3 = 180° rotation
     * 6 = 90° CW rotation (swap width/height)
     * 8 = 90° CCW rotation (swap width/height)
     *
     * @param int $storedWidth Width from getimagesize()
     * @param int $storedHeight Height from getimagesize()
     * @param array $exif EXIF data array
     * @return array{0: int, 1: int} [width, height] after orientation normalization
     */
    protected function normalizeDimensionsFromExif(int $storedWidth, int $storedHeight, array $exif): array
    {
        // Get EXIF orientation (if available)
        $orientation = null;
        if (isset($exif['Orientation'])) {
            $orientation = (int) $exif['Orientation'];
        } elseif (isset($exif['IFD0']['Orientation'])) {
            $orientation = (int) $exif['IFD0']['Orientation'];
        }

        // If no EXIF orientation or orientation is 1 (normal), return stored dimensions
        if ($orientation === null || $orientation === 1) {
            return [$storedWidth, $storedHeight];
        }

        // Orientations 6 and 8 require swapping width/height
        // These represent 90° rotations where the image is stored rotated
        if ($orientation === 6 || $orientation === 8) {
            return [$storedHeight, $storedWidth];
        }

        // Orientations 2, 3, 4, 5, 7 don't require dimension swap
        // (they're flips or 180° rotations)
        return [$storedWidth, $storedHeight];
    }

    /**
     * Compute orientation from dimensions using ratio-based classification.
     *
     * Uses aspect ratio to determine orientation, allowing for near-square images:
     * - ratio >= 0.95 AND ratio <= 1.05 → square
     * - ratio > 1.05 → landscape
     * - ratio < 0.95 → portrait
     *
     * @param int $width Visual width after EXIF normalization
     * @param int $height Visual height after EXIF normalization
     * @return string|null 'landscape', 'portrait', 'square', or null if cannot determine
     */
    protected function computeOrientation(int $width, int $height): ?string
    {
        if ($width === 0 || $height === 0) {
            return null;
        }

        // Calculate aspect ratio
        $ratio = $width / $height;

        // Ratio-based classification (allows for near-square images)
        // 0.95-1.05 range accounts for rounding and slight aspect variations
        if ($ratio >= 0.95 && $ratio <= 1.05) {
            return 'square';
        } elseif ($ratio > 1.05) {
            return 'landscape';
        } else {
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

        // Unknown color space - return null (do not write)
        return null;
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
                // Phase B7: System-computed metadata has producer = 'system' and high confidence
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $field->id,
                    'value_json' => json_encode($value),
                    'source' => 'system',
                    'confidence' => 0.95, // Phase B7: System-computed values are highly confident
                    'producer' => 'system', // Phase B7: System-computed values are from system
                    'approved_at' => now(), // System values are auto-approved
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
