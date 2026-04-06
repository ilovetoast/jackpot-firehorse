<?php

namespace App\Listeners;

use App\Events\AssetPendingApproval;
use App\Services\FeatureGate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Send Asset Pending Approval Notification Listener
 *
 * Phase L.6.3 — Approval Notifications
 *
 * Immediate per-asset emails are replaced by {@see \App\Console\Commands\SendPendingApprovalDigestsCommand}
 * when tenant approval notifications are enabled on the plan.
 *
 * Requirements:
 * - Queued (ShouldQueue) to prevent blocking upload flow
 * - Idempotent (safe to retry)
 * - Non-blocking (failures are logged but never throw)
 * - Tenant and brand scoped
 */
class SendAssetPendingApprovalNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(AssetPendingApproval $event): void
    {
        $asset = $event->asset;

        if ($asset->isProstaffAsset()) {
            return;
        }

        try {
            $tenant = $asset->tenant;

            if (! $tenant) {
                Log::warning('[SendAssetPendingApprovalNotification] Asset missing tenant', [
                    'asset_id' => $asset->id,
                ]);

                return;
            }

            $featureGate = app(FeatureGate::class);

            if (! $featureGate->notificationsEnabled($tenant)) {
                return;
            }

            // Batched digest only (approvals:send-pending-digests); avoid one email per upload.
            Log::info('[SendAssetPendingApprovalNotification] Skipped immediate email (digest workflow)', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
        } catch (\Exception $e) {
            // Never throw - notification must never block upload flow
            Log::error('[SendAssetPendingApprovalNotification] Listener failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
