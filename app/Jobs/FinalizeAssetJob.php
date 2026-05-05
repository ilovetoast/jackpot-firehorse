<?php

namespace App\Jobs;

use App\Enums\ThumbnailStatus;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Services\AssetCompletionService;
use App\Services\AssetProcessingFailureService;
use App\Services\BrandIntelligence\BrandIntelligenceScheduleService;
use App\Services\Studio\EditorStudioVideoPublishApplier;
use App\Jobs\Concerns\QueuesOnImagesChannel;
use App\Services\ImageEmbeddingService;
use App\Support\ProcessingMetrics;
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
    use Dispatchable, InteractsWithQueue, Queueable, QueuesOnImagesChannel, SerializesModels;

    public $tries = 32;

    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly string $assetId
    ) {
        $this->configureImagesQueue();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $asset = DB::transaction(fn () => Asset::where('id', $this->assetId)->lockForUpdate()->first());
        if (! $asset) {
            Log::info('[FinalizeAssetJob] Skipping — asset no longer exists (likely deleted during processing)', [
                'asset_id' => $this->assetId,
            ]);

            return;
        }
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

            // Studio MP4 exports are created unpublished; publish when the pipeline completes so the default
            // library grid (lifecycle=null) can include them. Shelf category may still be missing if no editor_publish.
            if (($asset->source ?? '') === 'studio_composition_video_export') {
                $tenant = $asset->tenant;
                $brand = $asset->brand;
                if ($tenant && $brand) {
                    app(EditorStudioVideoPublishApplier::class)->ensureShelfCategoryWhenMissing($asset, $tenant, $brand);
                    $asset->refresh();
                }
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
                if ($assetVal !== null && $assetVal !== '' && ! (is_string($assetVal) && strtolower(trim($assetVal)) === 'null')) {
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

            if (! ImageEmbeddingService::isImageMimeType($currentVersion->mime_type, $asset->original_filename)) {
                $updates['analysis_status'] = 'complete';
            }

            if (($asset->source ?? '') === 'studio_composition_video_export' && $asset->published_at === null) {
                $updates['published_at'] = now();
            }

            $updates['processing_duration_ms'] = ProcessingMetrics::pipelineDurationMs($asset, $currentVersion);

            $asset->update($updates);
            $mimeForEmbedding = $currentVersion->mime_type;
        } else {
            // Legacy (Starter): no version - use AssetCompletionService
            $completionService = app(AssetCompletionService::class);
            if (! $completionService->isComplete($asset)) {
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
            $updates = [
                'metadata' => $metadata,
                'processing_duration_ms' => ProcessingMetrics::pipelineDurationMs($asset, null),
            ];
            if (! ImageEmbeddingService::isImageMimeType($asset->mime_type, $asset->original_filename)) {
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

        $asset = $asset->fresh();
        $thumbnailStatus = $asset->thumbnail_status instanceof ThumbnailStatus
            ? $asset->thumbnail_status
            : null;

        $willDispatchEmbedding = $thumbnailStatus !== ThumbnailStatus::SKIPPED
            && ImageEmbeddingService::isImageMimeType($mimeForEmbedding, $asset->original_filename);

        if ($willDispatchEmbedding) {
            GenerateAssetEmbeddingJob::dispatch($asset->id)->onQueue(config('queue.images_queue', 'images'));
        } else {
            // Images with thumbnails skipped (or otherwise no embedding): GenerateAssetEmbeddingJob is not
            // dispatched, so nothing would advance analysis_status from generating_thumbnails — align here.
            if (ImageEmbeddingService::isImageMimeType($mimeForEmbedding, $asset->original_filename)
                && ($asset->analysis_status ?? '') !== 'complete') {
                $patch = ['analysis_status' => 'complete'];
                if ($thumbnailStatus === ThumbnailStatus::PENDING || $thumbnailStatus === ThumbnailStatus::PROCESSING) {
                    $meta = $asset->metadata ?? [];
                    $patch['thumbnail_status'] = ThumbnailStatus::SKIPPED;
                    $patch['thumbnail_error'] = (string) ($meta['thumbnail_skip_message'] ?? $meta['thumbnail_skip_reason'] ?? 'Thumbnail generation skipped');
                    $patch['thumbnail_started_at'] = null;
                }
                Asset::where('id', $this->assetId)->update($patch);
                $asset = $asset->fresh();
            }
            // Non-images and image assets without embeddings: embedding job normally dispatches EBI after scoring.
            $fresh = $asset->fresh();
            $schedule = app(BrandIntelligenceScheduleService::class);
            if ($schedule->shouldDeferBrandIntelligenceUntilVideoInsights($fresh)) {
                Log::debug('[FinalizeAssetJob] Deferring EBI until video insights complete (library video)', [
                    'asset_id' => $fresh->id,
                ]);
            } else {
                $schedule->dispatchAfterPipelineComplete($fresh);
            }
        }

        ComputeImageFocalPointJob::dispatch($this->assetId)->onQueue(config('queue.images_queue', 'images'));
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
