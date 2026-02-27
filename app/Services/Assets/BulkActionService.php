<?php

namespace App\Services\Assets;

use App\Enums\ApprovalStatus;
use App\Enums\AssetBulkAction;
use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Models\Asset;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BulkMetadataService;
use App\Support\Roles\PermissionMap;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

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
    ) {
    }

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
                    if (!Gate::forUser($user)->allows('forceDelete', $asset)) {
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
                if (!$this->canPerformAction($user, $asset, $actionEnum)) {
                    $skipped++;
                    $perActionSummary['skipped_unauthorized'] = ($perActionSummary['skipped_unauthorized'] ?? 0) + 1;
                    continue;
                }

                try {
                    $previousState = $this->snapshotState($asset);
                    $didApply = $this->applyAction($asset, $actionEnum, $payload, $user);
                    if (!$didApply) {
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
        if (!$user->can('view', $asset)) {
            return false;
        }

        if ($action->isApprovalAction()) {
            $tenant = $asset->tenant;
            $tenantRole = $user->getRoleForTenant($tenant);
            if (in_array($tenantRole, ['owner', 'admin'], true)) {
                return true;
            }
            if ($asset->brand_id) {
                $membership = $user->activeBrandMembership($asset->brand);
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
