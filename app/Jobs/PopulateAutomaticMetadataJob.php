<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Services\AssetProcessingFailureService;
use App\Services\AutomaticMetadataWriter;
use App\Services\MetadataSchemaResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Populate Automatic Metadata Job
 *
 * Phase B6: Populates automatic and hybrid metadata fields after processing.
 *
 * Runs AFTER:
 * - ExtractMetadataJob (file metadata extracted)
 * - GenerateThumbnailsJob (file accessible)
 * - ComputedMetadataJob (technical metadata computed)
 *
 * Rules:
 * - Only populates fields with population_mode = 'automatic' or 'hybrid'
 * - Never overwrites manual overrides
 * - Idempotent (safe to re-run)
 */
class PopulateAutomaticMetadataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AutomaticMetadataWriter $writer,
        MetadataSchemaResolver $schemaResolver
    ): void {
        $asset = Asset::findOrFail($this->assetId);

        // Skip if asset is not visible
        if ($asset->status !== AssetStatus::VISIBLE) {
            Log::info('[PopulateAutomaticMetadataJob] Skipping - asset not visible', [
                'asset_id' => $asset->id,
                'status' => $asset->status->value,
            ]);
            return;
        }

        // Load category for schema resolution
        $category = null;
        if ($asset->metadata && isset($asset->metadata['category_id'])) {
            $categoryId = $asset->metadata['category_id'];
            $category = \App\Models\Category::where('id', $categoryId)
                ->where('tenant_id', $asset->tenant_id)
                ->first();
        }

        if (!$category) {
            Log::info('[PopulateAutomaticMetadataJob] Skipping - no category', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Determine asset type for schema resolution
        $assetType = $this->determineAssetType($asset);

        // Resolve metadata schema
        $schema = $schemaResolver->resolve(
            $asset->tenant_id,
            $asset->brand_id,
            $category->id,
            $assetType
        );

        // Extract automatic and hybrid fields
        $fieldsToPopulate = [];
        foreach ($schema['fields'] ?? [] as $field) {
            $populationMode = $field['population_mode'] ?? 'manual';
            if ($populationMode === 'automatic' || $populationMode === 'hybrid') {
                $fieldsToPopulate[$field['field_id']] = $field;
            }
        }

        if (empty($fieldsToPopulate)) {
            Log::info('[PopulateAutomaticMetadataJob] No automatic/hybrid fields to populate', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Compute metadata values (stub implementation)
        $metadataValues = $this->computeMetadataValues($asset, $fieldsToPopulate);

        if (empty($metadataValues)) {
            Log::info('[PopulateAutomaticMetadataJob] No metadata values computed', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Write metadata values (respects manual overrides)
        $results = $writer->writeMetadata($asset, $metadataValues);

        Log::info('[PopulateAutomaticMetadataJob] Completed', [
            'asset_id' => $asset->id,
            'written' => count($results['written']),
            'skipped' => count($results['skipped']),
            'skipped_reasons' => array_column($results['skipped'], 'reason'),
        ]);
    }

    /**
     * Compute metadata values for automatic/hybrid fields.
     *
     * Phase B6: Stub implementation - returns deterministic placeholder values.
     * Future: Replace with actual EXIF extraction, AI analysis, etc.
     *
     * @param Asset $asset
     * @param array $fields Keyed by field_id
     * @return array Keyed by field_id => value
     */
    protected function computeMetadataValues(Asset $asset, array $fields): array
    {
        $values = [];

        foreach ($fields as $fieldId => $field) {
            $fieldKey = $field['key'] ?? null;
            if (!$fieldKey) {
                continue;
            }

            // Stub: Deterministic placeholder values based on field key
            $value = $this->computeStubValue($asset, $fieldKey, $field);
            if ($value !== null) {
                $values[$fieldId] = $value;
            }
        }

        return $values;
    }

    /**
     * Compute stub value for a field (deterministic placeholder).
     *
     * @param Asset $asset
     * @param string $fieldKey
     * @param array $field
     * @return mixed|null
     */
    protected function computeStubValue(Asset $asset, string $fieldKey, array $field): mixed
    {
        // Stub implementations for common automatic fields
        switch ($fieldKey) {
            case 'orientation':
                // Deterministic: Based on filename hash
                $hash = crc32($asset->original_filename);
                $orientations = ['landscape', 'portrait', 'square'];
                return $orientations[$hash % count($orientations)];

            case 'dimensions':
                // Deterministic: Based on file size
                $size = $asset->size_bytes ?? 0;
                // Simulate dimensions based on file size (placeholder logic)
                $width = 1920 + ($size % 1000);
                $height = 1080 + ($size % 500);
                return "{$width}x{$height}";

            case 'color_mode':
            case 'color_space':
                // Deterministic: Based on mime type
                $mimeType = $asset->mime_type ?? '';
                if (str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg')) {
                    return 'RGB';
                }
                return 'sRGB';

            default:
                // For other fields, return null (no stub value)
                return null;
        }
    }

    /**
     * Determine asset type from asset properties.
     *
     * @param Asset $asset
     * @return string 'image' | 'video' | 'document'
     */
    protected function determineAssetType(Asset $asset): string
    {
        $mimeType = $asset->mime_type ?? '';
        $filename = $asset->original_filename ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Video types
        if (str_starts_with($mimeType, 'video/') || in_array($extension, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
            return 'video';
        }

        // Document types
        if ($mimeType === 'application/pdf' || $extension === 'pdf' ||
            in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
            return 'document';
        }

        // Image types (default)
        return 'image';
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts()
            );
        }
    }
}
