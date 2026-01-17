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
        // Only process image assets
        if (!$this->isImageAsset($asset)) {
            Log::info('[ComputedMetadataService] Skipping non-image asset', [
                'asset_id' => $asset->id,
                'mime_type' => $asset->mime_type,
            ]);
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
        $computedValues = [
            'orientation' => $this->computeOrientation($imageData['width'], $imageData['height']),
            'color_space' => $this->computeColorSpace($imageData['exif'] ?? []),
            'resolution_class' => $this->computeResolutionClass($imageData['width'], $imageData['height']),
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

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Extract EXIF data (if available)
            $exif = [];
            if (function_exists('exif_read_data') && in_array(strtolower(pathinfo($tempPath, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'tiff', 'tif'])) {
                $exifData = @exif_read_data($tempPath);
                if ($exifData !== false) {
                    $exif = $exifData;
                }
            }

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
        $s3Client = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => $bucket->region,
            'credentials' => [
                'key' => $bucket->access_key_id,
                'secret' => $bucket->secret_access_key,
            ],
            'endpoint' => $bucket->endpoint,
            'use_path_style_endpoint' => $bucket->use_path_style_endpoint ?? false,
        ]);

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
     * Compute orientation from dimensions.
     *
     * @param int $width
     * @param int $height
     * @return string|null 'landscape', 'portrait', 'square', or null if cannot determine
     */
    protected function computeOrientation(int $width, int $height): ?string
    {
        if ($width === 0 || $height === 0) {
            return null;
        }

        if ($width > $height) {
            return 'landscape';
        } elseif ($height > $width) {
            return 'portrait';
        } else {
            return 'square';
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
                    Log::info('[ComputedMetadataService] Skipping field - user value exists', [
                        'asset_id' => $asset->id,
                        'field_key' => $fieldKey,
                    ]);
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
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $field->id,
                    'value_json' => json_encode($value),
                    'source' => 'system',
                    'confidence' => null,
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
                    'field_key' => $fieldKey,
                    'value' => $value,
                ]);
            }
        });
    }
}
