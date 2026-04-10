<?php

namespace App\Services\Assets;

use App\Enums\ApprovalStatus;
use App\Enums\AssetBulkAction;
use App\Enums\AssetType;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Exceptions\PlanLimitExceededException;
use App\Jobs\AiMetadataGenerationJob;
use App\Jobs\AiTagAutoApplyJob;
use App\Jobs\GenerateThumbnailsJob;
use App\Jobs\GenerateVideoPreviewJob;
use App\Jobs\ProcessAssetJob;
use App\Jobs\ProcessVideoInsightsBatchJob;
use App\Jobs\RegenerateSystemMetadataQueuedJob;
use App\Models\Asset;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Models\Tenant;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\BulkMetadataService;
use App\Services\FileTypeService;
use App\Services\Assets\AssetProcessingGuardService;
use App\Support\PipelineQueueResolver;
use App\Support\Roles\PermissionMap;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase B1: Bulk Action Service for Assets.
 *
 * One action per request. Processes in chunks of 50. Skips unauthorized assets.
 * Emits asset.bulk_action_performed per asset for audit.
 */
class BulkActionService
{
    private const CHUNK_SIZE = 50;

    public function __construct(
        protected BulkMetadataService $bulkMetadataService
    ) {}

    public function execute(array $assetIds, string $action, array $payload, User $user, int $tenantId, ?int $brandId): BulkActionResult
    {
        $actionEnum = AssetBulkAction::tryFrom($action);
        if ($actionEnum === null) {
            throw new \InvalidArgumentException("Invalid bulk action: {$action}");
        }

        if ($actionEnum === AssetBulkAction::REJECT) {
            $reason = $payload['rejection_reason'] ?? null;
            if ($reason === null || trim((string) $reason) === '') {
                throw new \InvalidArgumentException('Rejection reason is required for REJECT action.');
            }
        }

        if ($actionEnum === AssetBulkAction::ASSIGN_CATEGORY) {
            return $this->executeAssignCategory($assetIds, $payload, $user, $tenantId, $brandId);
        }
        if ($actionEnum === AssetBulkAction::RENAME_ASSETS) {
            return $this->executeRenameAssets($assetIds, $payload, $user, $tenantId, $brandId);
        }
        if ($actionEnum === AssetBulkAction::GENERATE_VIDEO_INSIGHTS) {
            return $this->executeBulkVideoInsights($assetIds, $user, $tenantId, $brandId);
        }
        if ($actionEnum->isSitePipelineAction()) {
            return $this->executeSitePipelineBulk($assetIds, $actionEnum, $user, $tenantId, $brandId);
        }
        if ($actionEnum->isMetadataAction()) {
            $opType = $actionEnum->metadataOperationType();
            $metadata = $payload['metadata'] ?? [];
            if ($opType !== 'clear' && $opType !== 'remove' && empty($metadata)) {
                throw new \InvalidArgumentException('Metadata payload is required for METADATA_ADD and METADATA_REPLACE.');
            }
            if ($opType === 'remove' && empty($metadata['tags'] ?? null)) {
                throw new \InvalidArgumentException('payload.metadata.tags is required for METADATA_REMOVE_TAGS.');
            }

            return $this->executeMetadataBulk($assetIds, $actionEnum, $metadata, $user, $tenantId, $brandId);
        }

        $query = Asset::with(['tenant', 'brand'])
            ->whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId));
        if ($actionEnum === AssetBulkAction::RESTORE_TRASH || $actionEnum === AssetBulkAction::FORCE_DELETE) {
            $query->onlyTrashed();
        }
        $assets = $query->get();

        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];

        // Phase B2: FORCE_DELETE removes assets permanently; use AssetDeletionService
        if ($actionEnum === AssetBulkAction::FORCE_DELETE) {
            $deletionService = app(\App\Services\AssetDeletionService::class);
            foreach ($assets->chunk(self::CHUNK_SIZE) as $chunk) {
                foreach ($chunk as $asset) {
                    if (! Gate::forUser($user)->allows('forceDelete', $asset)) {
                        $skipped++;
                        $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                        continue;
                    }
                    try {
                        $deletionService->forceDelete($asset, $user->id);
                        $processed++;
                    } catch (\Throwable $e) {
                        Log::warning('[BulkActionService] Force delete failed', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);
                        $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                    }
                }
            }

            return new BulkActionResult(
                totalSelected: count($assetIds),
                processed: $processed,
                skipped: $skipped,
                errors: $errors,
                perActionSummary: $perActionSummary
            );
        }

        foreach ($assets->chunk(self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $asset) {
                if (! $this->canPerformAction($user, $asset, $actionEnum)) {
                    $skipped++;
                    $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                    continue;
                }

                try {
                    $previousState = $this->snapshotState($asset);
                    $didApply = $this->applyAction($asset, $actionEnum, $payload, $user);
                    if (! $didApply) {
                        $skipped++;
                        $perActionSummary['skipped_no_op'] = ($perActionSummary['skipped_no_op'] ?? 0) + 1;

                        continue;
                    }
                    $asset->save();
                    $newState = $this->snapshotState($asset);
                    $this->emitBulkActionPerformed($asset, $user, $actionEnum->value, $previousState, $newState);
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] Asset update failed', [
                        'asset_id' => $asset->id,
                        'action' => $action,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return new BulkActionResult(
            totalSelected: count($assetIds),
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    protected function canPerformAction(User $user, Asset $asset, AssetBulkAction $action): bool
    {
        if (! $user->can('view', $asset)) {
            return false;
        }

        if ($action->isApprovalAction()) {
            $tenant = $asset->tenant;
            $tenantRole = $tenant ? $user->getRoleForTenant($tenant) : null;
            if (in_array($tenantRole, ['owner', 'admin'], true)) {
                return true;
            }
            if ($asset->brand_id) {
                $brand = $asset->brand;
                if (! $brand) {
                    return false;
                }
                $membership = $user->activeBrandMembership($brand);
                $brandRole = $membership['role'] ?? null;

                return $brandRole && PermissionMap::canApproveAssets($brandRole);
            }

            return false;
        }

        return match ($action) {
            AssetBulkAction::PUBLISH => Gate::forUser($user)->allows('publish', $asset),
            AssetBulkAction::UNPUBLISH => Gate::forUser($user)->allows('unpublish', $asset),
            AssetBulkAction::ARCHIVE => Gate::forUser($user)->allows('archive', $asset),
            AssetBulkAction::RESTORE_ARCHIVE => Gate::forUser($user)->allows('restoreArchive', $asset),
            AssetBulkAction::SOFT_DELETE, AssetBulkAction::RESTORE_TRASH => Gate::forUser($user)->allows('delete', $asset),
            AssetBulkAction::FORCE_DELETE => Gate::forUser($user)->allows('forceDelete', $asset),
            AssetBulkAction::GENERATE_VIDEO_INSIGHTS => Gate::forUser($user)->allows('update', $asset),
            default => false,
        };
    }

    /**
     * @return bool True if a change was applied, false if skipped (no-op)
     */
    protected function applyAction(Asset $asset, AssetBulkAction $action, array $payload, User $user): bool
    {
        switch ($action) {
            case AssetBulkAction::PUBLISH:
                if ($asset->published_at !== null) {
                    return false;
                }
                $asset->published_at = now();
                $asset->published_by_id = $user->id;

                return true;

            case AssetBulkAction::UNPUBLISH:
                if ($asset->published_at === null) {
                    return false;
                }
                $asset->published_at = null;
                $asset->published_by_id = null;

                return true;

            case AssetBulkAction::ARCHIVE:
                if ($asset->archived_at !== null) {
                    return false;
                }
                $asset->archived_at = now();
                $asset->archived_by_id = $user->id;

                return true;

            case AssetBulkAction::RESTORE_ARCHIVE:
                if ($asset->archived_at === null) {
                    return false;
                }
                $asset->archived_at = null;
                $asset->archived_by_id = null;

                return true;

            case AssetBulkAction::APPROVE:
                $asset->approval_status = ApprovalStatus::APPROVED;
                $asset->approved_at = now();
                $asset->approved_by_user_id = $user->id;
                $asset->rejected_at = null;
                $asset->rejection_reason = null;

                return true;

            case AssetBulkAction::MARK_PENDING:
                $asset->approval_status = ApprovalStatus::PENDING;
                $asset->approved_at = null;
                $asset->approved_by_user_id = null;
                $asset->rejected_at = null;
                $asset->rejection_reason = null;

                return true;

            case AssetBulkAction::REJECT:
                $reason = trim((string) ($payload['rejection_reason'] ?? ''));
                $asset->approval_status = ApprovalStatus::REJECTED;
                $asset->rejected_at = now();
                $asset->rejection_reason = $reason;
                $asset->approved_at = null;
                $asset->approved_by_user_id = null;

                return true;

            case AssetBulkAction::SOFT_DELETE:
                if ($asset->deleted_at !== null) {
                    return false;
                }
                $asset->deleted_at = now();
                $asset->deleted_by_user_id = $user->id;

                return true;

            case AssetBulkAction::RESTORE_TRASH:
                if ($asset->deleted_at === null) {
                    return false;
                }
                $asset->deleted_at = null;
                $asset->deleted_by_user_id = null;

                return true;

            default:
                return false;
        }
    }

    /**
     * Batch rename display title and filename (same pattern as upload batch naming): "Base 1 of N" titles and slug-01.ext filenames.
     *
     * @throws AuthorizationException When user lacks metadata.edit_post_upload for the tenant.
     */
    protected function executeRenameAssets(array $assetIds, array $payload, User $user, int $tenantId, ?int $brandId): BulkActionResult
    {
        $baseName = trim((string) ($payload['base_name'] ?? ''));
        if ($baseName === '') {
            throw new \InvalidArgumentException('base_name is required.');
        }
        if (count($assetIds) < 2) {
            throw new \InvalidArgumentException('Select at least two assets for batch rename.');
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            throw new \InvalidArgumentException('Tenant not found.');
        }

        if (! $user->hasPermissionForTenant($tenant, 'metadata.edit_post_upload')) {
            throw new AuthorizationException('You do not have permission to rename assets.');
        }

        $query = Asset::whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId));

        $assetsById = $query->get()->keyBy('id');
        $ordered = [];
        foreach ($assetIds as $id) {
            if ($assetsById->has($id)) {
                $ordered[] = $assetsById->get($id);
            }
        }

        $total = count($ordered);
        if ($total < 2) {
            throw new \InvalidArgumentException('Some assets were not found or not accessible.');
        }

        $slug = Str::slug($baseName);
        if ($slug === '') {
            $slug = 'asset';
        }
        $padLen = $total <= 9 ? 1 : ($total <= 99 ? 2 : 3);

        $processed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($ordered as $i => $asset) {
            if (! Gate::forUser($user)->allows('view', $asset)) {
                $skipped++;

                continue;
            }

            try {
                $previousState = $this->snapshotState($asset);
                $ext = strtolower(pathinfo((string) ($asset->original_filename ?? ''), PATHINFO_EXTENSION));
                $indexStr = str_pad((string) ($i + 1), $padLen, '0', STR_PAD_LEFT);
                $newFilename = $ext !== '' ? "{$slug}-{$indexStr}.{$ext}" : "{$slug}-{$indexStr}";
                $asset->title = $baseName.' '.($i + 1).' of '.$total;
                $asset->original_filename = $newFilename;
                $asset->save();
                $newState = $this->snapshotState($asset);
                $this->emitBulkActionPerformed($asset, $user, AssetBulkAction::RENAME_ASSETS->value, $previousState, $newState);
                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[BulkActionService] Rename asset failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
            }
        }

        return new BulkActionResult(
            totalSelected: count($assetIds),
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: []
        );
    }

    /**
     * Tenant users: queue video AI insights for eligible video assets (plan call cap checked before dispatch).
     *
     * @param  list<string>  $assetIds
     *
     * @throws AuthorizationException When video_insights monthly cap would be exceeded.
     */
    protected function executeBulkVideoInsights(array $assetIds, User $user, int $tenantId, ?int $brandId): BulkActionResult
    {
        $fileTypeService = app(FileTypeService::class);
        $policyService = app(AiTagPolicyService::class);
        $usageService = app(AiUsageService::class);
        $tenant = Tenant::find($tenantId);

        $query = Asset::query()
            ->whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->whereNull('deleted_at');

        $assets = $query->get()->keyBy('id');
        $ordered = [];
        foreach ($assetIds as $id) {
            if ($assets->has($id)) {
                $ordered[] = $assets->get($id);
            }
        }

        $eligible = [];
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];

        foreach ($ordered as $asset) {
            if (! $this->canPerformAction($user, $asset, AssetBulkAction::GENERATE_VIDEO_INSIGHTS)) {
                $skipped++;
                $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                continue;
            }
            if ($fileTypeService->detectFileTypeFromAsset($asset) !== 'video') {
                $skipped++;
                $perActionSummary['skipped_not_video'] = ($perActionSummary['skipped_not_video'] ?? 0) + 1;

                continue;
            }
            if (! $asset->storage_root_path || ! $asset->storageBucket) {
                $skipped++;
                $perActionSummary['skipped_no_storage'] = ($perActionSummary['skipped_no_storage'] ?? 0) + 1;

                continue;
            }
            $policy = $policyService->shouldProceedWithAiTagging($asset);
            if (! ($policy['should_proceed'] ?? false)) {
                $skipped++;
                $perActionSummary['skipped_ai_policy'] = ($perActionSummary['skipped_ai_policy'] ?? 0) + 1;

                continue;
            }
            $meta = $asset->metadata ?? [];
            if (! empty($meta['_skip_ai_video_insights'])) {
                $skipped++;
                $perActionSummary['skipped_opt_out'] = ($perActionSummary['skipped_opt_out'] ?? 0) + 1;

                continue;
            }
            if (in_array($meta['ai_video_status'] ?? null, ['queued', 'processing'], true)) {
                $skipped++;
                $perActionSummary['skipped_already_running'] = ($perActionSummary['skipped_already_running'] ?? 0) + 1;

                continue;
            }
            $eligible[] = $asset;
        }

        if ($tenant !== null && count($eligible) > 0) {
            try {
                $usageService->checkUsage($tenant, 'video_insights', count($eligible));
            } catch (PlanLimitExceededException $e) {
                throw new AuthorizationException($e->getMessage());
            }
        }

        $processed = 0;
        $toDispatch = [];
        foreach ($eligible as $asset) {
            try {
                $previousState = $this->snapshotState($asset);
                $meta = $asset->metadata ?? [];
                $newMeta = $meta;
                $newMeta['ai_video_status'] = 'queued';
                unset(
                    $newMeta['ai_video_insights_completed_at'],
                    $newMeta['ai_video_insights_error'],
                    $newMeta['ai_video_insights_failed_at']
                );
                $asset->update(['metadata' => $newMeta]);
                $asset->refresh();
                $this->emitBulkActionPerformed($asset, $user, AssetBulkAction::GENERATE_VIDEO_INSIGHTS->value, $previousState, $this->snapshotState($asset));
                $toDispatch[] = (string) $asset->id;
                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[BulkActionService] GENERATE_VIDEO_INSIGHTS prep failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
            }
        }

        if ($toDispatch !== []) {
            ProcessVideoInsightsBatchJob::dispatch($toDispatch);
        }

        return new BulkActionResult(
            totalSelected: count($assetIds),
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    /**
     * Site owner / site admin / site engineering only: queue pipeline jobs per asset.
     * Jobs run on Horizon-managed queues (images, etc.) — this method only dispatches.
     *
     * @throws AuthorizationException When user lacks a site pipeline role.
     */
    protected function executeSitePipelineBulk(
        array $assetIds,
        AssetBulkAction $action,
        User $user,
        int $tenantId,
        ?int $brandId
    ): BulkActionResult {
        if (! $user->hasRole(['site_owner', 'site_admin', 'site_engineering'])) {
            throw new AuthorizationException('Site admin or site engineering permission is required for this action.');
        }

        $query = Asset::query()
            ->with(['tenant', 'storageBucket', 'currentVersion'])
            ->whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->whereNull('deleted_at');

        $assets = $query->get()->keyBy('id');
        $ordered = [];
        foreach ($assetIds as $id) {
            if ($assets->has($id)) {
                $ordered[] = $assets->get($id);
            }
        }

        $totalSelected = count($assetIds);

        return match ($action) {
            AssetBulkAction::SITE_RERUN_AI_METADATA_TAGGING => $this->sitePipelineBulkAiMetadata($ordered, $totalSelected, $user, $tenantId),
            AssetBulkAction::SITE_RERUN_THUMBNAILS => $this->sitePipelineBulkThumbnails($ordered, $totalSelected, $user, $action->value),
            AssetBulkAction::SITE_GENERATE_VIDEO_PREVIEWS => $this->sitePipelineBulkVideoPreviews($ordered, $totalSelected, $user, $action->value),
            AssetBulkAction::SITE_REPROCESS_SYSTEM_METADATA => $this->sitePipelineBulkSystemMetadata($ordered, $totalSelected, $user, $action->value),
            AssetBulkAction::SITE_REPROCESS_FULL_PIPELINE => $this->sitePipelineBulkFullPipeline($ordered, $totalSelected, $user, $action->value),
            default => throw new \InvalidArgumentException('Unsupported site pipeline bulk action.'),
        };
    }

    protected function pipelineBulkChunkSize(): int
    {
        return max(1, (int) config('asset_processing.bulk_pipeline_chunk_size', 10));
    }

    /**
     * @param  array<int, Asset>  $ordered
     */
    protected function sitePipelineBulkAiMetadata(array $ordered, int $totalSelected, User $user, int $tenantId): BulkActionResult
    {
        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];
        $aiPolicy = app(AiTagPolicyService::class);
        $aiUsage = app(AiUsageService::class);
        $tenant = Tenant::find($tenantId);

        $aiEligible = [];
        foreach ($ordered as $asset) {
            if (! Gate::forUser($user)->allows('view', $asset)) {
                $skipped++;
                $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                continue;
            }
            if (! $asset->storage_root_path || ! $asset->storageBucket) {
                $skipped++;
                $perActionSummary['skipped_no_storage'] = ($perActionSummary['skipped_no_storage'] ?? 0) + 1;

                continue;
            }
            if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
                $skipped++;
                $perActionSummary['skipped_thumbnails_not_ready'] = ($perActionSummary['skipped_thumbnails_not_ready'] ?? 0) + 1;

                continue;
            }
            $policy = $aiPolicy->shouldProceedWithAiTagging($asset);
            if (! ($policy['should_proceed'] ?? false)) {
                $skipped++;
                $perActionSummary['skipped_ai_policy'] = ($perActionSummary['skipped_ai_policy'] ?? 0) + 1;

                continue;
            }
            $aiEligible[] = $asset;
        }

        if ($tenant && count($aiEligible) > 0) {
            try {
                $aiUsage->checkUsage($tenant, 'tagging', count($aiEligible));
            } catch (PlanLimitExceededException $e) {
                throw new AuthorizationException($e->getMessage());
            }
        }

        $chunks = array_chunk($aiEligible, $this->pipelineBulkChunkSize());
        foreach ($chunks as $idx => $chunk) {
            if ($idx > 0) {
                usleep(250_000);
            }
            foreach ($chunk as $asset) {
                try {
                    $previousState = $this->snapshotState($asset);
                    Bus::chain([
                        new AiMetadataGenerationJob($asset->id, true),
                        new AiTagAutoApplyJob($asset->id),
                    ])
                        ->onQueue(config('queue.images_queue', 'images'))
                        ->dispatch();
                    $this->emitBulkActionPerformed($asset, $user, AssetBulkAction::SITE_RERUN_AI_METADATA_TAGGING->value, $previousState, $this->snapshotState($asset));
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] SITE_RERUN_AI_METADATA_TAGGING dispatch failed', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return new BulkActionResult(
            totalSelected: $totalSelected,
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    /**
     * @param  array<int, Asset>  $ordered
     */
    protected function sitePipelineBulkThumbnails(array $ordered, int $totalSelected, User $user, string $actionValue): BulkActionResult
    {
        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];

        $chunks = array_chunk($ordered, $this->pipelineBulkChunkSize());
        foreach ($chunks as $idx => $chunk) {
            if ($idx > 0) {
                usleep(250_000);
            }
            foreach ($chunk as $asset) {
                if (! Gate::forUser($user)->allows('view', $asset)) {
                    $skipped++;
                    $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                    continue;
                }
                if (! $asset->storage_root_path || ! $asset->storageBucket) {
                    $skipped++;
                    $perActionSummary['skipped_no_storage'] = ($perActionSummary['skipped_no_storage'] ?? 0) + 1;

                    continue;
                }
                try {
                    $previousState = $this->snapshotState($asset);
                    $asset->update([
                        'thumbnail_status' => ThumbnailStatus::PENDING,
                        'thumbnail_error' => null,
                        'thumbnail_started_at' => null,
                    ]);
                    $asset->loadMissing('currentVersion');
                    $payloadId = $asset->currentVersion ? (string) $asset->currentVersion->id : (string) $asset->id;
                    GenerateThumbnailsJob::dispatch($payloadId, true)->onQueue(PipelineQueueResolver::imagesQueueForAsset($asset));
                    $asset->refresh();
                    $this->emitBulkActionPerformed($asset, $user, $actionValue, $previousState, $this->snapshotState($asset));
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] SITE_RERUN_THUMBNAILS failed', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return new BulkActionResult(
            totalSelected: $totalSelected,
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    /**
     * @param  array<int, Asset>  $ordered
     */
    protected function sitePipelineBulkVideoPreviews(array $ordered, int $totalSelected, User $user, string $actionValue): BulkActionResult
    {
        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];
        $fileTypeService = app(FileTypeService::class);

        $chunks = array_chunk($ordered, $this->pipelineBulkChunkSize());
        foreach ($chunks as $idx => $chunk) {
            if ($idx > 0) {
                usleep(250_000);
            }
            foreach ($chunk as $asset) {
                if (! Gate::forUser($user)->allows('view', $asset)) {
                    $skipped++;
                    $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                    continue;
                }
                if (! Gate::forUser($user)->allows('retryThumbnails', $asset)) {
                    $skipped++;
                    $perActionSummary['skipped_no_retry_permission'] = ($perActionSummary['skipped_no_retry_permission'] ?? 0) + 1;

                    continue;
                }
                if (! $asset->storage_root_path || ! $asset->storageBucket) {
                    $skipped++;
                    $perActionSummary['skipped_no_storage'] = ($perActionSummary['skipped_no_storage'] ?? 0) + 1;

                    continue;
                }
                if ($fileTypeService->detectFileTypeFromAsset($asset) !== 'video') {
                    $skipped++;
                    $perActionSummary['skipped_not_video'] = ($perActionSummary['skipped_not_video'] ?? 0) + 1;

                    continue;
                }
                $hasPosterPath = (bool) ($asset->getRawOriginal('video_poster_url') ?? $asset->attributes['video_poster_url'] ?? null);
                $hasThumbnailPath = (bool) ($asset->thumbnailPathForStyle('thumb') ?? $asset->thumbnailPathForStyle('medium'));
                if (! $hasPosterPath && ! $hasThumbnailPath) {
                    $skipped++;
                    $perActionSummary['skipped_no_poster_or_thumb'] = ($perActionSummary['skipped_no_poster_or_thumb'] ?? 0) + 1;

                    continue;
                }
                try {
                    $previousState = $this->snapshotState($asset);
                    $asset->update(['video_preview_url' => null]);
                    $asset->refresh();
                    GenerateVideoPreviewJob::dispatch($asset->id)->onQueue(config('queue.images_queue', 'images'));
                    $this->emitBulkActionPerformed($asset, $user, $actionValue, $previousState, $this->snapshotState($asset));
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] SITE_GENERATE_VIDEO_PREVIEWS failed', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return new BulkActionResult(
            totalSelected: $totalSelected,
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    /**
     * @param  array<int, Asset>  $ordered
     */
    protected function sitePipelineBulkSystemMetadata(array $ordered, int $totalSelected, User $user, string $actionValue): BulkActionResult
    {
        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];

        $chunks = array_chunk($ordered, $this->pipelineBulkChunkSize());
        foreach ($chunks as $idx => $chunk) {
            if ($idx > 0) {
                usleep(250_000);
            }
            foreach ($chunk as $asset) {
                if (! Gate::forUser($user)->allows('view', $asset)) {
                    $skipped++;
                    $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                    continue;
                }
                $tenant = $asset->tenant;
                if (! $tenant) {
                    $skipped++;
                    $perActionSummary['skipped_no_tenant'] = ($perActionSummary['skipped_no_tenant'] ?? 0) + 1;

                    continue;
                }
                $role = $user->getRoleForTenant($tenant);
                $canRegenerate = $user->hasPermissionForTenant($tenant, 'assets.ai_metadata.regenerate')
                    || in_array($role, ['owner', 'admin'], true);
                if (! $canRegenerate) {
                    $skipped++;
                    $perActionSummary['skipped_no_metadata_permission'] = ($perActionSummary['skipped_no_metadata_permission'] ?? 0) + 1;

                    continue;
                }
                if (! $asset->storage_root_path || ! $asset->storageBucket) {
                    $skipped++;
                    $perActionSummary['skipped_no_storage'] = ($perActionSummary['skipped_no_storage'] ?? 0) + 1;

                    continue;
                }
                try {
                    $previousState = $this->snapshotState($asset);
                    RegenerateSystemMetadataQueuedJob::dispatch($asset->id)->onQueue(config('queue.images_queue', 'images'));
                    $this->emitBulkActionPerformed($asset, $user, $actionValue, $previousState, $this->snapshotState($asset));
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] SITE_REPROCESS_SYSTEM_METADATA dispatch failed', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return new BulkActionResult(
            totalSelected: $totalSelected,
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    /**
     * @param  array<int, Asset>  $ordered
     */
    protected function sitePipelineBulkFullPipeline(array $ordered, int $totalSelected, User $user, string $actionValue): BulkActionResult
    {
        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];
        $guard = app(AssetProcessingGuardService::class);

        $chunks = array_chunk($ordered, $this->pipelineBulkChunkSize());
        foreach ($chunks as $idx => $chunk) {
            if ($idx > 0) {
                usleep(250_000);
            }
            foreach ($chunk as $asset) {
                if (! Gate::forUser($user)->allows('view', $asset)) {
                    $skipped++;
                    $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;

                    continue;
                }
                if (! Gate::forUser($user)->allows('retryThumbnails', $asset)) {
                    $skipped++;
                    $perActionSummary['skipped_no_retry_permission'] = ($perActionSummary['skipped_no_retry_permission'] ?? 0) + 1;

                    continue;
                }
                if ($asset->thumbnail_status === ThumbnailStatus::PROCESSING) {
                    $skipped++;
                    $perActionSummary['skipped_already_processing'] = ($perActionSummary['skipped_already_processing'] ?? 0) + 1;

                    continue;
                }
                try {
                    $guard->assertCanDispatch($user, $asset, AssetProcessingGuardService::ACTION_FULL_PIPELINE);
                } catch (HttpResponseException) {
                    $skipped++;
                    $perActionSummary['skipped_guard'] = ($perActionSummary['skipped_guard'] ?? 0) + 1;

                    continue;
                }
                try {
                    $previousState = $this->snapshotState($asset);
                    $metadata = $asset->metadata ?? [];
                    unset($metadata['processing_started'], $metadata['processing_started_at']);
                    unset($metadata['thumbnail_skip_reason']);
                    unset($metadata['processing_failed'], $metadata['failure_reason'], $metadata['failed_job']);
                    unset($metadata['failure_attempts'], $metadata['failure_is_retryable'], $metadata['failed_at']);

                    $asset->update([
                        'analysis_status' => 'uploading',
                        'thumbnail_status' => ThumbnailStatus::PENDING,
                        'thumbnail_error' => null,
                        'metadata' => $metadata,
                    ]);

                    $version = $asset->currentVersion;
                    if ($version) {
                        $versionMetadata = $version->metadata ?? [];
                        unset($versionMetadata['processing_started'], $versionMetadata['processing_started_at']);
                        $version->update([
                            'pipeline_status' => 'pending',
                            'metadata' => $versionMetadata,
                        ]);
                    }

                    ProcessAssetJob::dispatch($asset->id)->onQueue(config('queue.images_queue', 'images'));
                    $guard->markDispatched($user, $asset, AssetProcessingGuardService::ACTION_FULL_PIPELINE);
                    $asset->refresh();
                    $this->emitBulkActionPerformed($asset, $user, $actionValue, $previousState, $this->snapshotState($asset));
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] SITE_REPROCESS_FULL_PIPELINE failed', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                    $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
                }
            }
        }

        return new BulkActionResult(
            totalSelected: $totalSelected,
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: $perActionSummary
        );
    }

    protected function executeAssignCategory(array $assetIds, array $payload, User $user, int $tenantId, ?int $brandId): BulkActionResult
    {
        $categoryId = (int) ($payload['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new \InvalidArgumentException('category_id is required and must be a valid category ID.');
        }

        $category = \App\Models\Category::where('id', $categoryId)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->first();

        if (! $category) {
            throw new \InvalidArgumentException('Category not found or not accessible.');
        }

        $requestedAssetType = isset($payload['asset_type']) ? trim((string) $payload['asset_type']) : '';
        if ($requestedAssetType !== '') {
            $expected = AssetType::tryFrom($requestedAssetType);
            if ($expected === null) {
                throw new \InvalidArgumentException('Invalid asset_type. Use asset, deliverable, or ai_generated.');
            }
            if ($category->asset_type !== $expected) {
                throw new \InvalidArgumentException('Selected category does not match the chosen asset type.');
            }
        }

        $assets = Asset::whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->get();

        $processed = 0;
        $skipped = 0;
        $errors = [];

        foreach ($assets as $asset) {
            if (! Gate::forUser($user)->allows('view', $asset)) {
                $skipped++;

                continue;
            }
            try {
                $metadata = $asset->metadata ?? [];
                $metadata['category_id'] = $category->id;
                $asset->metadata = $metadata;
                $asset->type = $category->asset_type;
                $asset->intake_state = 'normal';
                $asset->builder_staged = false; // Clear builder_staged when classifying
                $asset->save();
                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[BulkActionService] Assign category failed', ['asset_id' => $asset->id, 'error' => $e->getMessage()]);
                $errors[] = ['asset_id' => $asset->id, 'reason' => $e->getMessage()];
            }
        }

        return new BulkActionResult(
            totalSelected: count($assetIds),
            processed: $processed,
            skipped: $skipped,
            errors: $errors,
            perActionSummary: []
        );
    }

    protected function executeMetadataBulk(array $assetIds, AssetBulkAction $action, array $metadata, User $user, int $tenantId, ?int $brandId): BulkActionResult
    {
        $opType = $action->metadataOperationType();
        $assets = Asset::whereIn('id', $assetIds)
            ->where('tenant_id', $tenantId)
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->get();

        $allowedIds = $assets->filter(fn (Asset $a) => $user->can('view', $a))->pluck('id')->all();
        $tenant = $user->tenants()->find($tenantId);
        $brand = $brandId ? $assets->first()?->brand : null;
        $userRole = $tenant ? $user->getRoleForTenant($tenant) : null;
        if ($userRole === null && $brand) {
            $userRole = $user->getRoleForBrand($brand);
        }
        $userRole = $userRole ?? 'member';

        try {
            $result = $this->bulkMetadataService->execute(
                $allowedIds,
                $opType,
                $metadata,
                $tenantId,
                (int) ($brandId ?? $assets->first()?->brand_id ?? 0),
                $user->id,
                $userRole
            );
        } catch (\Throwable $e) {
            Log::error('[BulkActionService] Metadata bulk execute failed', ['error' => $e->getMessage()]);

            return new BulkActionResult(
                totalSelected: count($assetIds),
                processed: 0,
                skipped: 0,
                errors: [['asset_id' => 'batch', 'reason' => $e->getMessage()]],
                perActionSummary: []
            );
        }

        $processed = count($result['successes'] ?? []);
        $errors = array_map(fn ($f) => ['asset_id' => $f['asset_id'], 'reason' => $f['error'] ?? 'Unknown'], $result['failures'] ?? []);
        $skipped = count($assetIds) - $processed - count($errors);

        return new BulkActionResult(
            totalSelected: count($assetIds),
            processed: $processed,
            skipped: max(0, $skipped),
            errors: $errors,
            perActionSummary: ['metadata_operation' => $opType]
        );
    }

    protected function snapshotState(Asset $asset): array
    {
        return [
            'published_at' => $asset->published_at?->toIso8601String(),
            'archived_at' => $asset->archived_at?->toIso8601String(),
            'approval_status' => $asset->approval_status?->value ?? null,
            'deleted_at' => $asset->deleted_at?->toIso8601String(),
            'title' => $asset->title,
            'original_filename' => $asset->original_filename,
        ];
    }

    protected function emitBulkActionPerformed(Asset $asset, User $user, string $action, array $previousState, array $newState): void
    {
        try {
            ActivityRecorder::record(
                tenant: $asset->tenant,
                eventType: EventType::ASSET_BULK_ACTION_PERFORMED,
                subject: $asset,
                actor: $user,
                brand: $asset->brand,
                metadata: [
                    'action' => $action,
                    'user_id' => $user->id,
                    'previous_state' => $previousState,
                    'new_state' => $newState,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[BulkActionService] Failed to record bulk action event', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
