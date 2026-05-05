<?php

namespace App\Services\Assets;

use App\Enums\AssetStatus;
use App\Enums\ThumbnailStatus;
use App\Jobs\FinalizeAssetJob;
use App\Jobs\ProcessAssetJob;
use App\Jobs\PromoteAssetJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\Tenant;
use App\Services\Reliability\ReliabilityEngine;
use App\Services\TenantBucketService;
use App\Services\Studio\EditorStudioVideoPublishApplier;
use App\Support\PipelineQueueResolver;
use App\Support\ThumbnailMetadata;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Asset State Reconciliation Service
 *
 * Prevents invalid asset state combinations. Only promotes forward, never downgrades.
 */
class AssetStateReconciliationService
{
    /**
     * Reconcile asset state to fix invalid combinations.
     *
     * @return array{updated: bool, changes: array}
     */
    public function reconcile(Asset $asset): array
    {
        $changes = [];
        $metadata = $asset->metadata ?? [];

        // Rule 1 — Completed Pipeline Must Finalize Analysis
        if (
            isset($metadata['pipeline_completed_at'])
            && ($metadata['thumbnails_generated'] ?? false) === true
        ) {
            $promotions = $this->applyRule1($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 1b — Pipeline finalized with intentional thumbnail skip (no embedding for skipped images)
        // FinalizeAssetJob does not queue embeddings when thumbnails are SKIPPED; without this rule,
        // analysis_status can stay on generating_thumbnails forever while metadata shows pipeline_completed_at.
        if (
            isset($metadata['pipeline_completed_at'])
            && ($metadata['thumbnails_generated'] ?? false) !== true
            && ! empty($metadata['thumbnail_skip_reason'] ?? null)
            && ($asset->analysis_status ?? '') !== 'complete'
        ) {
            $promotions = $this->applyRule1b($asset);
            $changes = array_merge($changes, $promotions);
            $asset = $asset->fresh();
            $metadata = $asset->metadata ?? [];
        }

        // Rule 1c — Pipeline + analysis already complete but thumbnail row stuck FAILED (e.g. .ai raster
        // exhausted retries, or Illustrator-like files that will not succeed in headless raster paths).
        // "Attempt Repair" only reconciles; without this, admins see complete analysis + failed thumb forever.
        $promotions = $this->applyRule1cIfApplicable($asset);
        if (! empty($promotions)) {
            $changes = array_merge($changes, $promotions);
            $asset = $asset->fresh();
            $metadata = $asset->metadata ?? [];
        }

        // Rule 2 — Timeout But Later Success
        if (
            ($metadata['thumbnail_timeout'] ?? false) === true
            && ($metadata['thumbnails_generated'] ?? false) === true
        ) {
            $promotions = $this->applyRule2($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 3 — Uploading With Extracted Metadata
        if (
            ($metadata['metadata_extracted'] ?? false) === true
            && ($asset->analysis_status ?? 'uploading') === 'uploading'
        ) {
            $promotions = $this->applyRule3($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 4 — Promotion Failed + Thumbnails Missing: record critical incident
        if (($asset->analysis_status ?? 'uploading') === 'promotion_failed') {
            $hasThumbnails = (bool) data_get($asset->metadata, 'thumbnails.large.path');
            if (!$hasThumbnails) {
                app(ReliabilityEngine::class)->report([
                    'source_type' => 'asset',
                    'source_id' => $asset->id,
                    'tenant_id' => $asset->tenant_id,
                    'severity' => 'critical',
                    'title' => 'Promotion failed and thumbnails missing',
                    'retryable' => true,
                    'unique_signature' => "promotion_failed_no_thumbnails:{$asset->id}",
                ]);
            }
        }

        // Rule 5 — Pipeline outputs present but analysis_status stuck (e.g. post-lifecycle migration)
        // Fixes assets that completed pipeline but weren't backfilled (migration only touched brand_compliance_scores)
        if (($asset->analysis_status ?? 'uploading') !== 'complete') {
            $promotions = $this->applyRule5($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 5b — Non-image assets stuck at generating_embedding (PDF, video, etc.)
        // Embedding job is never dispatched for non-images; nothing advances status. Fix stuck assets.
        if (($asset->analysis_status ?? 'uploading') === 'generating_embedding') {
            $promotions = $this->applyRule5b($asset);
            $changes = array_merge($changes, $promotions);
        }

        // Rule 6 — Visual metadata now ready: auto-resolve "Expected visual metadata missing" incident
        // Prevents incident from lingering in Ops center after backfill or recovery
        if ($asset->visualMetadataReady()) {
            $this->resolveVisualMetadataIncidentIfExists($asset);
        }

        // Rule 7 — Studio export / animation: version marked complete before pipeline ran → ProcessAssetJob no-ops forever
        $promotions = $this->applyRule7($asset->fresh());
        $changes = array_merge($changes, $promotions);

        // Rule 7b — Eager thumbnails + chain GenerateThumbnailsJob idempotent skip left version stuck at
        // pipeline_status=processing, so Finalize never ran. Heal by completing the version gate and tail chain.
        $promotions = $this->applyRule7b($asset->fresh());
        $changes = array_merge($changes, $promotions);

        // Rule 8 — Studio composition video export: completed pipeline but still unpublished → default grid hides asset
        $promotions = $this->applyRule8($asset->fresh());
        $changes = array_merge($changes, $promotions);

        // Rule 9 — Studio MP4 rows created before storage_bucket_id was set: backfill tenant bucket when missing
        $promotions = $this->applyRule9($asset->fresh());
        $changes = array_merge($changes, $promotions);

        return [
            'updated' => !empty($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Resolve "Expected visual metadata missing" incident when asset now has visualMetadataReady.
     * Only resolves this specific incident type — does not touch unrelated incidents.
     */
    protected function resolveVisualMetadataIncidentIfExists(Asset $asset): void
    {
        $incident = \App\Models\SystemIncident::where('source_type', 'asset')
            ->where('source_id', $asset->id)
            ->whereNull('resolved_at')
            ->where('title', 'Expected visual metadata missing')
            ->first();

        if ($incident) {
            $incident->update([
                'resolved_at' => now(),
                'auto_resolved' => true,
                'metadata' => array_merge($incident->metadata ?? [], ['auto_recovered' => true]),
            ]);
            Log::info('[AssetStateReconciliationService] Auto-resolved visual metadata incident', [
                'asset_id' => $asset->id,
                'incident_id' => $incident->id,
            ]);
        }
    }

    /**
     * Rule 1: pipeline_completed_at + thumbnails_generated → analysis_status=complete, thumbnail_status=completed
     */
    protected function applyRule1(Asset $asset): array
    {
        $changes = [];
        $updates = [];

        if (($asset->analysis_status ?? 'uploading') !== 'complete') {
            $updates['analysis_status'] = 'complete';
            $changes[] = 'analysis_status → complete';
        }

        if (($asset->thumbnail_status?->value ?? null) !== ThumbnailStatus::COMPLETED->value) {
            $updates['thumbnail_status'] = ThumbnailStatus::COMPLETED;
            $updates['thumbnail_error'] = null;
            $updates['thumbnail_started_at'] = null;
            $changes[] = 'thumbnail_status → completed';
        }

        if (!empty($updates)) {
            $asset->update($updates);
            Log::info('[AssetStateReconciliationService] Rule 1 applied', [
                'asset_id' => $asset->id,
                'changes' => $changes,
            ]);
        }

        return $changes;
    }

    /**
     * Rule 1b: pipeline_completed_at + thumbnail_skip_reason + analysis not complete → complete analysis;
     * align stuck thumbnail row (pending/processing) with SKIPPED when metadata says skip.
     *
     * @return list<string>
     */
    protected function applyRule1b(Asset $asset): array
    {
        $changes = [];
        $metadata = $asset->metadata ?? [];
        $updates = ['analysis_status' => 'complete'];
        $changes[] = 'analysis_status → complete';

        $thumbEnum = $asset->thumbnail_status instanceof ThumbnailStatus ? $asset->thumbnail_status : null;
        if ($thumbEnum === ThumbnailStatus::PENDING || $thumbEnum === ThumbnailStatus::PROCESSING) {
            $updates['thumbnail_status'] = ThumbnailStatus::SKIPPED;
            $updates['thumbnail_error'] = (string) ($metadata['thumbnail_skip_message'] ?? $metadata['thumbnail_skip_reason'] ?? 'Thumbnail generation skipped');
            $updates['thumbnail_started_at'] = null;
            $changes[] = 'thumbnail_status → skipped (aligned with metadata skip)';
        }

        $asset->update($updates);
        $asset->refresh();
        Log::info('[AssetStateReconciliationService] Rule 1b applied (pipeline done, thumbnails skipped)', [
            'asset_id' => $asset->id,
            'changes' => $changes,
        ]);

        return $changes;
    }

    /**
     * @return list<string>
     */
    protected function applyRule1cIfApplicable(Asset $asset): array
    {
        $asset = $asset->fresh();
        $metadata = $asset->metadata ?? [];

        if (($asset->analysis_status ?? '') !== 'complete') {
            return [];
        }
        if (! isset($metadata['pipeline_completed_at'])) {
            return [];
        }

        $thumbEnum = $asset->thumbnail_status instanceof ThumbnailStatus ? $asset->thumbnail_status : null;
        if ($thumbEnum !== ThumbnailStatus::FAILED) {
            return [];
        }

        $maxRetries = max(1, (int) config('assets.thumbnail.max_retries', 3));
        $exhausted = (int) ($asset->thumbnail_retry_count ?? 0) >= $maxRetries;
        $designRaster = $this->isDesignIllustratorLikeAsset($asset);

        if (! $exhausted && ! $designRaster) {
            return [];
        }

        $reason = $designRaster ? 'design_raster_failed' : 'generation_failed_exhausted';
        $message = $designRaster
            ? 'Design/vector preview could not be rasterized (or is unsupported in this environment); the library shows a type icon.'
            : "Thumbnail generation failed after {$maxRetries} attempts; marked as skipped for display.";

        $meta = $metadata;
        $meta['thumbnail_skip_reason'] = $reason;
        $meta['thumbnail_skip_message'] = $message;

        $retryCountBefore = (int) ($asset->thumbnail_retry_count ?? 0);
        $asset->update([
            'thumbnail_status' => ThumbnailStatus::SKIPPED,
            'thumbnail_error' => $message,
            'thumbnail_started_at' => null,
            'metadata' => $meta,
        ]);
        $asset->refresh();

        Log::info('[AssetStateReconciliationService] Rule 1c applied (terminal FAILED→SKIPPED after pipeline complete)', [
            'asset_id' => $asset->id,
            'reason' => $reason,
            'thumbnail_retry_count' => $retryCountBefore,
            'design_raster' => $designRaster,
        ]);

        return ['Rule 1c: thumbnail_status failed→skipped after pipeline (terminal)'];
    }

    private function isDesignIllustratorLikeAsset(Asset $asset): bool
    {
        $ext = strtolower(pathinfo((string) $asset->original_filename, PATHINFO_EXTENSION));
        $mime = strtolower((string) ($asset->mime_type ?? ''));

        if (in_array($ext, ['ai', 'eps'], true)) {
            return true;
        }

        return str_contains($mime, 'illustrator')
            || str_contains($mime, 'postscript')
            || str_contains($mime, 'illustrator-artwork');
    }

    /**
     * Rule 2: thumbnail_timeout + thumbnails_generated → clear timeout, thumbnail_status=completed
     */
    protected function applyRule2(Asset $asset): array
    {
        $changes = [];
        $metadata = $asset->metadata ?? [];

        unset($metadata['thumbnail_timeout']);
        unset($metadata['thumbnail_timeout_reason']);

        $updates = [
            'metadata' => $metadata,
            'thumbnail_status' => ThumbnailStatus::COMPLETED,
            'thumbnail_error' => null,
            'thumbnail_started_at' => null,
        ];

        $asset->update($updates);
        $changes[] = 'cleared thumbnail_timeout, thumbnail_status → completed';

        Log::info('[AssetStateReconciliationService] Rule 2 applied', [
            'asset_id' => $asset->id,
        ]);

        return $changes;
    }

    /**
     * Rule 3: metadata_extracted + analysis_status=uploading → analysis_status=generating_thumbnails
     */
    protected function applyRule3(Asset $asset): array
    {
        $changes = [];

        if (($asset->analysis_status ?? 'uploading') === 'uploading') {
            $asset->update(['analysis_status' => 'generating_thumbnails']);
            $changes[] = 'analysis_status → generating_thumbnails';

            Log::info('[AssetStateReconciliationService] Rule 3 applied', [
                'asset_id' => $asset->id,
            ]);
        }

        return $changes;
    }

    /**
     * Rule 5: All pipeline outputs present → analysis_status=complete
     *
     * Fixes assets that completed the pipeline but analysis_status was never set
     * (e.g. post analysis_status migration, assets without brand_compliance_scores).
     */
    protected function applyRule5(Asset $asset): array
    {
        $hasDominantColors = ! empty(data_get($asset->metadata, 'dominant_colors'))
            || ! empty(data_get($asset->metadata, 'fields.dominant_colors'));
        $hasDominantHueGroup = ! empty($asset->dominant_hue_group);
        $hasEmbedding = $asset->embedding()->exists();
        $thumbEnum = $asset->thumbnail_status instanceof ThumbnailStatus ? $asset->thumbnail_status : null;
        $thumbnailDone = $thumbEnum === ThumbnailStatus::COMPLETED || $thumbEnum === ThumbnailStatus::SKIPPED;

        if (!$hasDominantColors || !$hasDominantHueGroup || !$hasEmbedding || !$thumbnailDone) {
            return [];
        }

        $asset->update(['analysis_status' => 'complete']);
        Log::info('[AssetStateReconciliationService] Rule 5 applied (pipeline outputs present)', [
            'asset_id' => $asset->id,
        ]);

        return ['analysis_status → complete'];
    }

    /**
     * Rule 5b: Non-image assets stuck at generating_embedding.
     *
     * PDF/video/etc. never get GenerateAssetEmbeddingJob dispatched, so status stays stuck.
     * If pipeline completed (thumbnails, metadata, etc.), advance to complete.
     */
    protected function applyRule5b(Asset $asset): array
    {
        if (\App\Services\ImageEmbeddingService::isImageMimeType($asset->mime_type ?? '', $asset->original_filename)) {
            return [];
        }
        $metadata = $asset->metadata ?? [];
        $thumbEnum = $asset->thumbnail_status instanceof ThumbnailStatus ? $asset->thumbnail_status : null;
        $thumbnailDone = $thumbEnum === ThumbnailStatus::COMPLETED || $thumbEnum === ThumbnailStatus::SKIPPED;
        $pipelineDone = isset($metadata['pipeline_completed_at'])
            || ($thumbnailDone && ($metadata['ai_tagging_completed'] ?? false) && ($metadata['metadata_extracted'] ?? false));

        if ($pipelineDone) {
            $asset->update(['analysis_status' => 'complete']);
            Log::info('[AssetStateReconciliationService] Rule 5b applied (non-image stuck at generating_embedding)', [
                'asset_id' => $asset->id,
            ]);
            return ['analysis_status → complete'];
        }
        return [];
    }

    /**
     * Rule 7: Studio composition video export / studio animation can end up with
     * version.pipeline_status=complete (e.g. eager {@see GenerateThumbnailsJob}) while
     * version.metadata.processing_started is still false, so {@see ProcessAssetJob} used to
     * exit at the "version already complete" guard and the asset stayed analysis_status=uploading.
     * Reset the version gate and re-dispatch. Applies both when thumbs are still pending and when
     * eager thumbnails already ran (thumbnails_generated=true) but the main chain never did.
     *
     * @return list<string>
     */
    protected function applyRule7(Asset $asset): array
    {
        $allowedSources = ['studio_composition_video_export', 'studio_animation'];
        if (! in_array((string) ($asset->source ?? ''), $allowedSources, true)) {
            return [];
        }
        if (($asset->analysis_status ?? '') !== 'uploading') {
            return [];
        }
        $metadata = $asset->metadata ?? [];
        if (isset($metadata['pipeline_completed_at'])) {
            return [];
        }
        $version = $asset->currentVersion()->first();
        if (! $version || $version->pipeline_status !== 'complete') {
            return [];
        }
        $versionMeta = is_array($version->metadata) ? $version->metadata : [];
        if (($versionMeta['processing_started'] ?? false) === true) {
            return [];
        }

        $version->update(['pipeline_status' => 'pending']);
        ProcessAssetJob::dispatch((string) $asset->id)
            ->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));

        Log::info('[AssetStateReconciliationService] Rule 7 applied (studio output version gate reset)', [
            'asset_id' => $asset->id,
            'version_id' => $version->id,
            'source' => $asset->source,
        ]);

        return ['Rule 7: version.pipeline_status → pending; ProcessAssetJob dispatched'];
    }

    /**
     * Rule 7b: After {@see ProcessAssetJob} runs, the chain's {@see GenerateThumbnailsJob} may return
     * immediately when thumbnails already exist (eager generation). That path did not set
     * version.pipeline_status=complete, so {@see FinalizeAssetJob} skipped and the asset never finished.
     * Repair: set complete and dispatch the tail of the normal chain.
     *
     * @return list<string>
     */
    protected function applyRule7b(Asset $asset): array
    {
        $allowedSources = ['studio_composition_video_export', 'studio_animation'];
        if (! in_array((string) ($asset->source ?? ''), $allowedSources, true)) {
            return [];
        }

        $meta = $asset->metadata ?? [];
        if (isset($meta['pipeline_completed_at'])) {
            return [];
        }
        if (($meta['thumbnails_generated'] ?? false) !== true) {
            return [];
        }
        if (($meta['metadata_extracted'] ?? false) !== true) {
            return [];
        }

        $version = $asset->currentVersion()->first();
        if (! $version || $version->pipeline_status === 'failed') {
            return [];
        }
        if ($version->pipeline_status === 'complete') {
            return [];
        }

        $vMeta = is_array($version->metadata) ? $version->metadata : [];
        if (($vMeta['processing_started'] ?? false) !== true) {
            return [];
        }
        if (! ThumbnailMetadata::hasThumb($vMeta) && ! ThumbnailMetadata::hasThumb($meta)) {
            return [];
        }
        if (($asset->analysis_status ?? '') === 'uploading') {
            return [];
        }

        $version->update(['pipeline_status' => 'complete']);
        $queue = PipelineQueueResolver::imagesQueueForAsset($asset);
        Bus::chain([
            new FinalizeAssetJob($asset->id),
            new PromoteAssetJob($asset->id),
        ])->onQueue($queue);

        Log::info('[AssetStateReconciliationService] Rule 7b applied (studio tail chain after eager thumbnail idempotent skip)', [
            'asset_id' => $asset->id,
            'version_id' => $version->id,
            'source' => $asset->source,
        ]);

        return ['Rule 7b: version complete; Finalize+Promote dispatched'];
    }

    /**
     * Rule 8: Studio composition MP4 exports were created with published_at=null; the grid only shows published
     * assets with metadata.category_id. After the pipeline completes, publish when a shelf category exists
     * (assign default category if still missing).
     *
     * @return list<string>
     */
    protected function applyRule8(Asset $asset): array
    {
        if (($asset->source ?? '') !== 'studio_composition_video_export') {
            return [];
        }
        if ($asset->published_at !== null) {
            return [];
        }
        if ($asset->deleted_at !== null || $asset->archived_at !== null) {
            return [];
        }
        if ($asset->status !== AssetStatus::VISIBLE) {
            return [];
        }
        $meta = $asset->metadata ?? [];
        $pipelineDone = ($asset->analysis_status ?? '') === 'complete' || isset($meta['pipeline_completed_at']);
        if (! $pipelineDone) {
            return [];
        }

        $tenant = Tenant::query()->find($asset->tenant_id);
        $brand = Brand::query()->find($asset->brand_id);
        if (! $tenant || ! $brand) {
            return [];
        }

        if (! $this->assetHasGridCategoryId($asset)) {
            app(EditorStudioVideoPublishApplier::class)->ensureShelfCategoryWhenMissing($asset, $tenant, $brand);
            $asset = $asset->fresh();
            if (! $asset || ! $this->assetHasGridCategoryId($asset)) {
                return [];
            }
        }

        if ($asset->published_at !== null) {
            return [];
        }

        $asset->update(['published_at' => now()]);
        Log::info('[AssetStateReconciliationService] Rule 8 applied (studio export published after pipeline)', [
            'asset_id' => $asset->id,
        ]);

        return ['Rule 8: published_at set for studio composition video export'];
    }

    /**
     * Rule 9: Studio composition export / studio animation assets with a storage path but no {@see Asset::$storage_bucket_id}.
     * Older code wrote objects to the tenant disk without linking the row to {@see \App\Models\StorageBucket}, which broke
     * jobs that required the FK. Backfill from {@see TenantBucketService::getOrProvisionBucket} so reconcile / admin repair
     * can heal existing rows without a migration.
     *
     * @return list<string>
     */
    protected function applyRule9(Asset $asset): array
    {
        $allowedSources = ['studio_composition_video_export', 'studio_animation'];
        if (! in_array((string) ($asset->source ?? ''), $allowedSources, true)) {
            return [];
        }
        if ($asset->deleted_at !== null) {
            return [];
        }
        if ($asset->storage_bucket_id !== null) {
            return [];
        }
        $path = trim((string) ($asset->storage_root_path ?? ''));
        if ($path === '') {
            return [];
        }
        if (! $asset->tenant_id) {
            return [];
        }
        $tenant = Tenant::query()->find($asset->tenant_id);
        if (! $tenant) {
            return [];
        }

        try {
            $bucketId = app(TenantBucketService::class)->getOrProvisionBucket($tenant)->id;
        } catch (\Throwable $e) {
            Log::warning('[AssetStateReconciliationService] Rule 9 skipped (tenant bucket unavailable)', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $asset->update(['storage_bucket_id' => $bucketId]);
        Log::info('[AssetStateReconciliationService] Rule 9 applied (storage_bucket_id backfilled)', [
            'asset_id' => $asset->id,
            'storage_bucket_id' => $bucketId,
            'source' => $asset->source,
        ]);

        return ['Rule 9: storage_bucket_id backfilled for studio output asset'];
    }

    private function assetHasGridCategoryId(Asset $asset): bool
    {
        $meta = $asset->metadata ?? [];
        $categoryId = $meta['category_id'] ?? null;
        if ($categoryId === null || $categoryId === '') {
            return false;
        }
        if (is_string($categoryId) && strtolower(trim($categoryId)) === 'null') {
            return false;
        }

        return true;
    }
}
