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
use App\Models\Asset;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Models\Tenant;
use App\Services\AiTagPolicyService;
use App\Services\AiUsageService;
use App\Services\BulkMetadataService;
use App\Support\PipelineQueueResolver;
use App\Support\Roles\PermissionMap;
use Illuminate\Auth\Access\AuthorizationException;
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
        if ($actionEnum->isSitePipelineAction()) {
            return $this->executeSitePipelineBulk($assetIds, $actionEnum, $user, $tenantId, $brandId);
        }
        if ($actionEnum->isMetadataAction()) {
            $opType = $actionEnum->metadataOperationType();
            $metadata = $payload['metadata'] ?? [];
            if ($opType !== 'clear' && empty($metadata)) {
                throw new \InvalidArgumentException('Metadata payload is required for METADATA_ADD and METADATA_REPLACE.');
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
            ->with(['storageBucket', 'currentVersion'])
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

        $processed = 0;
        $skipped = 0;
        $errors = [];
        $perActionSummary = [];
        $aiPolicy = app(AiTagPolicyService::class);
        $aiUsage = app(AiUsageService::class);
        $tenant = Tenant::find($tenantId);

        if ($action === AssetBulkAction::SITE_RERUN_AI_METADATA_TAGGING) {
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

            foreach ($aiEligible as $asset) {
                try {
                    $previousState = $this->snapshotState($asset);
                    Bus::chain([
                        new AiMetadataGenerationJob($asset->id, true),
                        new AiTagAutoApplyJob($asset->id),
                    ])
                        ->onQueue(config('queue.images_queue', 'images'))
                        ->dispatch();
                    $this->emitBulkActionPerformed($asset, $user, $action->value, $previousState, $this->snapshotState($asset));
                    $processed++;
                } catch (\Throwable $e) {
                    Log::warning('[BulkActionService] SITE_RERUN_AI_METADATA_TAGGING dispatch failed', [
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
                perActionSummary: $perActionSummary
            );
        }

        // SITE_RERUN_THUMBNAILS
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
                $this->emitBulkActionPerformed($asset, $user, $action->value, $previousState, $this->snapshotState($asset));
                $processed++;
            } catch (\Throwable $e) {
                Log::warning('[BulkActionService] SITE_RERUN_THUMBNAILS failed', [
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
