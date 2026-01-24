<?php

namespace App\Jobs;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Models\Asset;
use App\Services\ActivityRecorder;
use App\Services\AssetProcessingFailureService;
use App\Services\AutomaticMetadataWriter;
use App\Services\Automation\ColorAnalysisService;
use App\Services\Automation\DominantColorsExtractor;
use App\Services\MetadataSchemaResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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
        MetadataSchemaResolver $schemaResolver,
        ColorAnalysisService $colorService,
        DominantColorsExtractor $dominantColorsExtractor
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

        // Compute metadata values
        $computationResult = $this->computeMetadataValues($asset, $fieldsToPopulate, $colorService);
        $metadataValues = $computationResult['values'];
        $colorAnalysisResult = $computationResult['color_analysis'] ?? null;

        if (empty($metadataValues)) {
            Log::info('[PopulateAutomaticMetadataJob] No metadata values computed', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Persist internal color analysis data (if ai_color_palette was computed)
        if ($colorAnalysisResult !== null) {
            $this->persistColorAnalysisData($asset, $colorAnalysisResult);
            
            // Refresh asset to get updated metadata before extracting dominant colors
            $asset->refresh();
            
            // Extract and persist dominant colors from cluster data
            $dominantColorsExtractor->extractAndPersist($asset);
            
            // Log color analysis completion to activity timeline
            ActivityRecorder::logAsset($asset, EventType::ASSET_COLOR_ANALYSIS_COMPLETED, [
                'buckets' => $colorAnalysisResult['buckets'],
                'buckets_count' => count($colorAnalysisResult['buckets']),
                'clusters_count' => count($colorAnalysisResult['internal']['clusters'] ?? []),
            ]);
        }

        // Write metadata values (respects manual overrides)
        $results = $writer->writeMetadata($asset, $metadataValues);
        
        // Note: We don't log activity here because:
        // - System metadata generation is already logged by ComputedMetadataJob (ASSET_SYSTEM_METADATA_GENERATED)
        // - This job just populates automatic/hybrid fields, which is part of system metadata processing
        // - We only want to show: System Metadata, AI Metadata, and AI Tagging in the timeline

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
     * @param Asset $asset
     * @param array $fields Keyed by field_id
     * @param ColorAnalysisService $colorService
     * @return array{values: array, color_analysis: array|null} Metadata values and optional color analysis result
     */
    protected function computeMetadataValues(Asset $asset, array $fields, ColorAnalysisService $colorService): array
    {
        $values = [];
        $colorAnalysisResult = null;

        foreach ($fields as $fieldId => $field) {
            $fieldKey = $field['key'] ?? null;
            if (!$fieldKey) {
                continue;
            }

            // Handle ai_color_palette with deterministic color analysis
            if ($fieldKey === 'ai_color_palette') {
                $result = $colorService->analyze($asset);
                if ($result !== null && !empty($result['buckets'])) {
                    $values[$fieldId] = $result['buckets'];
                    $colorAnalysisResult = $result; // Store for internal data persistence
                }
                continue;
            }

            // Stub: Deterministic placeholder values for other fields
            $value = $this->computeStubValue($asset, $fieldKey, $field);
            if ($value !== null) {
                $values[$fieldId] = $value;
            }
        }

        return [
            'values' => $values,
            'color_analysis' => $colorAnalysisResult,
        ];
    }

    /**
     * Persist internal color analysis data to asset.metadata.
     * Stores cluster data for future use (non-filter, non-UI).
     *
     * @param Asset $asset
     * @param array $colorAnalysisResult Result from ColorAnalysisService::analyze()
     * @return void
     */
    protected function persistColorAnalysisData(Asset $asset, array $colorAnalysisResult): void
    {
        // Merge internal data into asset.metadata (preserve existing metadata)
        $metadata = $asset->metadata ?? [];
        $metadata['_color_analysis'] = $colorAnalysisResult['internal'];
        $asset->update(['metadata' => $metadata]);

        Log::info('[PopulateAutomaticMetadataJob] Persisted color analysis data', [
            'asset_id' => $asset->id,
            'clusters_count' => count($colorAnalysisResult['internal']['clusters']),
            'ignored_pixels' => $colorAnalysisResult['internal']['ignored_pixels'],
        ]);
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
        // Stub implementations for automatic fields that aren't computed by ComputedMetadataJob
        // NOTE: orientation, dimensions, color_space, and resolution_class are computed by ComputedMetadataJob
        // and should NOT be overwritten here. Only handle fields that aren't system-computed.
        switch ($fieldKey) {
            case 'color_mode':
                // Deterministic: Based on mime type
                $mimeType = $asset->mime_type ?? '';
                if (str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg')) {
                    return 'RGB';
                }
                return 'sRGB';

            default:
                // For other fields, return null (no stub value)
                // This includes orientation, dimensions, color_space, resolution_class which are
                // computed by ComputedMetadataJob and should not be overwritten
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
