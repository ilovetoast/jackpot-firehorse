<?php

namespace App\Listeners;

use App\Events\AssetPendingApproval;
use App\Mail\AssetPendingApprovalNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Send Asset Pending Approval Notification Listener
 *
 * Phase L.6.3 — Approval Notifications
 *
 * Sends email notifications to users who can approve assets when an asset
 * is pending approval.
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
     *
     * @param AssetPendingApproval $event
     * @return void
     */
    public function handle(AssetPendingApproval $event): void
    {
        $asset = $event->asset;
        $uploader = $event->uploader;
        $categoryName = $event->categoryName;

        try {
            // Get tenant and brand from asset
            $tenant = $asset->tenant;
            $brand = $asset->brand;

            if (!$tenant) {
                Log::warning('[SendAssetPendingApprovalNotification] Asset missing tenant', [
                    'asset_id' => $asset->id,
                ]);
                return;
            }

            // Find users who can approve:
            // 1. Belong to the same tenant
            // 2. Are assigned to the same brand (or are tenant admin/owner)
            // 3. Have asset.publish permission
            $tenantUsers = User::whereHas('tenants', function ($query) use ($tenant) {
                $query->where('tenants.id', $tenant->id);
            })->get();

            // Filter to users who:
            // - Are assigned to the brand (or are tenant admin/owner)
            // - Have asset.publish permission
            $approvers = $tenantUsers->filter(function ($user) use ($tenant, $brand) {
                // Check if user has asset.publish permission
                if (!$user->hasPermissionForTenant($tenant, 'asset.publish')) {
                    return false;
                }

                // Check brand assignment or tenant admin/owner
                if ($brand) {
                    $tenantRole = $user->getRoleForTenant($tenant);
                    $isTenantAdmin = in_array($tenantRole, ['admin', 'owner']);
                    $isBrandAssigned = $user->brands()->where('brands.id', $brand->id)->exists();
                    
                    return $isTenantAdmin || $isBrandAssigned;
                } else {
                    // No brand - only tenant admins/owners
                    $tenantRole = $user->getRoleForTenant($tenant);
                    return in_array($tenantRole, ['admin', 'owner']);
                }
            });

            // Filter out uploader to avoid notifying them about their own upload
            // (even if they have approval permissions, they already know they uploaded it)
            if ($uploader) {
                $approvers = $approvers->reject(function ($approver) use ($uploader) {
                    return $approver->id === $uploader->id;
                });
            }

            if ($approvers->isEmpty()) {
                Log::info('[SendAssetPendingApprovalNotification] No approvers found for asset', [
                    'asset_id' => $asset->id,
                    'tenant_id' => $tenant->id,
                    'brand_id' => $brand?->id,
                ]);
                return;
            }

            // Send email to each approver
            foreach ($approvers as $approver) {
                try {
                    Mail::to($approver->email)->send(
                        new AssetPendingApprovalNotification($asset, $uploader, $categoryName)
                    );

                    Log::info('[SendAssetPendingApprovalNotification] Notification sent', [
                        'asset_id' => $asset->id,
                        'approver_id' => $approver->id,
                        'approver_email' => $approver->email,
                    ]);
                } catch (\Exception $e) {
                    // Log failure but continue with other approvers
                    Log::error('[SendAssetPendingApprovalNotification] Failed to send notification', [
                        'asset_id' => $asset->id,
                        'approver_id' => $approver->id,
                        'approver_email' => $approver->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
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
