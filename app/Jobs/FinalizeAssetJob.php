<?php

namespace App\Jobs;

use App\Enums\ThumbnailStatus;
use App\Jobs\GenerateAssetEmbeddingJob;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetCompletionService;
use App\Services\AssetProcessingFailureService;
use App\Services\BrandDNA\BrandComplianceService;
use App\Services\ImageEmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finalize Asset Job
 *
 * Single asset sync authority. When version exists and pipeline is complete,
 * syncs version fields to asset. Never derives values from asset; never trusts stale asset state.
 *
 * Version path: gate on currentVersion->pipeline_status === 'complete', sync from version only.
 * Legacy (Starter): use AssetCompletionService, no version sync.
 */
class FinalizeAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     * Never retry forever - enforce maximum attempts.
     *
     * @var int
     */
    public $tries = 3; // Maximum retry attempts (enforced by AssetProcessingFailureService)

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
    public function handle(): void
    {
        $asset = DB::transaction(fn () => Asset::where('id', $this->assetId)->lockForUpdate()->firstOrFail());
        \App\Services\UploadDiagnosticLogger::jobStart('FinalizeAssetJob', $asset->id, $asset->currentVersion?->id);

        $currentVersion = $asset->currentVersion;

        // Version path: gate on pipeline_status - single sync authority
        if ($currentVersion) {
            if ($currentVersion->pipeline_status !== 'complete') {
                Log::info('[FinalizeAssetJob] Skipping - version pipeline not complete', [
                    'asset_id' => $asset->id,
                    'version_id' => $currentVersion->id,
                    'pipeline_status' => $currentVersion->pipeline_status,
                ]);
                \App\Services\UploadDiagnosticLogger::jobSkip('FinalizeAssetJob', $asset->id, 'pipeline_not_complete', [
                    'pipeline_status' => $currentVersion->pipeline_status,
                ]);
                return;
            }

            // Sync from version - MERGE with asset metadata (never replace)
            // Version metadata: thumbnails, dimensions, version-scoped fields
            // Asset metadata: category_id, metadata_extracted, preview_generated, etc. (upload/processing)
            // CRITICAL: Asset-scoped fields must NEVER be overwritten by version - version may have null
            // when synced from a different source. Preserve these after merge.
            $versionMetadata = $currentVersion->metadata ?? [];
            $assetMetadata = $asset->metadata ?? [];
            $metadata = array_merge($assetMetadata, $versionMetadata);
            $metadata['pipeline_completed_at'] = now()->toIso8601String();

            // Preserve asset-scoped fields - version must NEVER wipe these (set at upload, required for grid)
            $preservedKeys = ['category_id', 'metadata_extracted', 'preview_generated', 'approval_status'];
            foreach ($preservedKeys as $key) {
                $assetVal = $assetMetadata[$key] ?? null;
                if ($assetVal !== null && $assetVal !== '' && !(is_string($assetVal) && strtolower(trim($assetVal)) === 'null')) {
                    $metadata[$key] = $assetVal;
                }
            }

            $updates = [
                'mime_type' => $currentVersion->mime_type,
                'size_bytes' => $currentVersion->file_size,
                'storage_root_path' => $currentVersion->file_path,
                'metadata' => $metadata,
                'width' => $currentVersion->width,
                'height' => $currentVersion->height,
            ];

            if (!ImageEmbeddingService::isImageMimeType($currentVersion->mime_type)) {
                $updates['analysis_status'] = 'complete';
            }

            $asset->update($updates);
            $mimeForEmbedding = $currentVersion->mime_type;
        } else {
            // Legacy (Starter): no version - use AssetCompletionService
            $completionService = app(AssetCompletionService::class);
            if (!$completionService->isComplete($asset)) {
                Log::warning('[FinalizeAssetJob] Asset completion skipped - criteria not met', [
                    'asset_id' => $asset->id,
                    'status' => $asset->status->value,
                    'note' => 'AssetCompletionService determined asset is not ready for completion',
                ]);
                \App\Services\UploadDiagnosticLogger::jobSkip('FinalizeAssetJob', $asset->id, 'completion_criteria_not_met', [
                    'status' => $asset->status->value,
                ]);
                return;
            }

            $metadata = $asset->metadata ?? [];
            $metadata['pipeline_completed_at'] = now()->toIso8601String();
            $updates = ['metadata' => $metadata];
            if (!ImageEmbeddingService::isImageMimeType($asset->mime_type)) {
                $updates['analysis_status'] = 'complete';
            }
            $asset->update($updates);
            $mimeForEmbedding = $asset->mime_type;
        }

        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null,
            'event_type' => 'asset.finalized',
            'metadata' => ['job' => 'FinalizeAssetJob'],
            'created_at' => now(),
        ]);

        Log::info('[FinalizeAssetJob] Asset pipeline completed successfully', [
            'asset_id' => $asset->id,
            'original_filename' => $asset->original_filename,
        ]);
        \App\Services\UploadDiagnosticLogger::jobComplete('FinalizeAssetJob', $asset->id);

        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status
            : null;

        if ($thumbnailStatus === ThumbnailStatus::SKIPPED) {
            // File type does not support thumbnails — skip embedding, create clear compliance state
            $brand = $asset->brand;
            if ($brand) {
                app(BrandComplianceService::class)->upsertFileTypeUnsupported($asset, $brand);
            }
        } elseif (ImageEmbeddingService::isImageMimeType($mimeForEmbedding)) {
            GenerateAssetEmbeddingJob::dispatch($asset->id);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $asset = Asset::find($this->assetId);

        if ($asset) {
            // Use centralized failure recording service
            app(AssetProcessingFailureService::class)->recordFailure(
                $asset,
                self::class,
                $exception,
                $this->attempts(),
                true // preserveVisibility: uploaded assets must never disappear from grid
            );
        }
    }
}
