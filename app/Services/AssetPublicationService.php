<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Enums\EventType;
use App\Models\Asset;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Asset Publication Service
 *
 * Handles publishing and unpublishing assets with proper permission checks
 * and lifecycle state management.
 *
 * Phase L.2 — Asset Publication
 */
class AssetPublicationService
{
    /**
     * Publish an asset.
     *
     * Sets published_at timestamp and published_by_id, and makes asset visible
     * (if not archived). Enforces permissions and guards against invalid states.
     *
     * @param Asset $asset The asset to publish
     * @param User $actor The user performing the action
     * @return void
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \RuntimeException
     */
    public function publish(Asset $asset, User $actor): void
    {
        // Check permission via policy
        Gate::forUser($actor)->authorize('publish', $asset);

        // Guard: Cannot publish archived assets
        if ($asset->isArchived()) {
            throw new \RuntimeException('Cannot publish archived assets.');
        }

        // Guard: Cannot publish failed assets
        if ($asset->status === AssetStatus::FAILED) {
            throw new \RuntimeException('Cannot publish failed assets.');
        }

        // Idempotent: If already published, do nothing
        if ($asset->isPublished()) {
            return;
        }

        DB::transaction(function () use ($asset, $actor) {
            // Set publication fields
            $asset->published_at = now();
            $asset->published_by_id = $actor->id;

            // Make asset visible (only if not archived)
            // Archived assets remain hidden even when published
            if (!$asset->isArchived()) {
                $asset->status = AssetStatus::VISIBLE;
            }

            $asset->save();

            // Log activity event
            try {
                ActivityRecorder::record(
                    tenant: $asset->tenant,
                    eventType: EventType::ASSET_PUBLISHED,
                    subject: $asset,
                    actor: $actor,
                    brand: $asset->brand,
                    metadata: [
                        'published_at' => $asset->published_at->toIso8601String(),
                        'published_by_id' => $actor->id,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break the operation
                Log::error('[AssetPublicationService] Failed to log publish event', [
                    'asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Unpublish an asset.
     *
     * Clears published_at and published_by_id, and hides the asset.
     * Enforces permissions.
     *
     * @param Asset $asset The asset to unpublish
     * @param User $actor The user performing the action
     * @return void
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function unpublish(Asset $asset, User $actor): void
    {
        // Check permission via policy
        Gate::forUser($actor)->authorize('unpublish', $asset);

        // Idempotent: If already unpublished, do nothing
        if (!$asset->isPublished()) {
            return;
        }

        DB::transaction(function () use ($asset, $actor) {
            // Clear publication fields
            $asset->published_at = null;
            $asset->published_by_id = null;

            // Hide the asset
            $asset->status = AssetStatus::HIDDEN;

            $asset->save();

            // Log activity event
            try {
                ActivityRecorder::record(
                    tenant: $asset->tenant,
                    eventType: EventType::ASSET_UNPUBLISHED,
                    subject: $asset,
                    actor: $actor,
                    brand: $asset->brand,
                    metadata: [
                        'unpublished_at' => now()->toIso8601String(),
                        'unpublished_by_id' => $actor->id,
                    ]
                );
            } catch (\Exception $e) {
                // Activity logging must never break the operation
                Log::error('[AssetPublicationService] Failed to log unpublish event', [
                    'asset_id' => $asset->id,
                    'actor_id' => $actor->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
