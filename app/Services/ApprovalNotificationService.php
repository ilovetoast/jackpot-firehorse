<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\User;
use App\Support\Roles\PermissionMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase AF-3: Approval Notification Service
 * 
 * Handles in-app notifications for approval events.
 * Respects active brand membership only.
 * 
 * Recipient Rules:
 * - Asset submitted/resubmitted: All approval_capable users with active membership
 * - Asset approved: Original uploader (if still active member)
 * - Asset rejected: Original uploader (if still active member)
 */
class ApprovalNotificationService
{
    /**
     * Notify approvers when an asset is submitted for approval.
     */
    public function notifyOnSubmitted(Asset $asset, User $uploader): void
    {
        $brand = $asset->brand;
        if (!$brand) {
            Log::warning('[ApprovalNotificationService] Asset has no brand, skipping notification', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Notifications disabled for tenant plan, skipping', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        // Get all approval_capable users with active brand membership
        $recipients = $this->getApprovalCapableUsers($brand);

        // Remove uploader from recipients (they don't need to be notified of their own submission)
        $recipients = $recipients->reject(fn ($user) => $user->id === $uploader->id);

        if ($recipients->isEmpty()) {
            Log::info('[ApprovalNotificationService] No approvers to notify for submitted asset', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
            ]);
            return;
        }

        $this->createNotifications(
            recipients: $recipients,
            type: 'asset.submitted',
            data: $this->buildNotificationData($asset, $uploader, 'submitted'),
        );

        Log::info('[ApprovalNotificationService] Notified approvers of submitted asset', [
            'asset_id' => $asset->id,
            'brand_id' => $brand->id,
            'recipient_count' => $recipients->count(),
        ]);
    }

    /**
     * Notify uploader when asset is approved.
     */
    public function notifyOnApproved(Asset $asset, User $approver): void
    {
        $uploader = $asset->user;
        if (!$uploader) {
            Log::warning('[ApprovalNotificationService] Asset has no uploader, skipping notification', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        $brand = $asset->brand;
        if (!$brand) {
            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Notifications disabled for tenant plan, skipping', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        // Phase MI-1: Verify uploader has active brand membership
        $membership = $uploader->activeBrandMembership($brand);
        if (!$membership) {
            Log::info('[ApprovalNotificationService] Uploader no longer has active membership, skipping notification', [
                'asset_id' => $asset->id,
                'uploader_id' => $uploader->id,
                'brand_id' => $brand->id,
            ]);
            return;
        }

        // Don't notify if uploader is the approver
        if ($uploader->id === $approver->id) {
            return;
        }

        $this->createNotifications(
            recipients: collect([$uploader]),
            type: 'asset.approved',
            data: $this->buildNotificationData($asset, $approver, 'approved'),
        );

        Log::info('[ApprovalNotificationService] Notified uploader of approved asset', [
            'asset_id' => $asset->id,
            'uploader_id' => $uploader->id,
        ]);
    }

    /**
     * Notify uploader when asset is rejected.
     */
    public function notifyOnRejected(Asset $asset, User $rejector, string $rejectionReason): void
    {
        $uploader = $asset->user;
        if (!$uploader) {
            Log::warning('[ApprovalNotificationService] Asset has no uploader, skipping notification', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        $brand = $asset->brand;
        if (!$brand) {
            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Notifications disabled for tenant plan, skipping', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        // Phase MI-1: Verify uploader has active brand membership
        $membership = $uploader->activeBrandMembership($brand);
        if (!$membership) {
            Log::info('[ApprovalNotificationService] Uploader no longer has active membership, skipping notification', [
                'asset_id' => $asset->id,
                'uploader_id' => $uploader->id,
                'brand_id' => $brand->id,
            ]);
            return;
        }

        // Don't notify if uploader is the rejector
        if ($uploader->id === $rejector->id) {
            return;
        }

        $data = $this->buildNotificationData($asset, $rejector, 'rejected');
        $data['rejection_reason'] = $rejectionReason;

        $this->createNotifications(
            recipients: collect([$uploader]),
            type: 'asset.rejected',
            data: $data,
        );

        Log::info('[ApprovalNotificationService] Notified uploader of rejected asset', [
            'asset_id' => $asset->id,
            'uploader_id' => $uploader->id,
        ]);
    }

    /**
     * Notify approvers when an asset is resubmitted for approval.
     */
    public function notifyOnResubmitted(Asset $asset, User $resubmitter): void
    {
        $brand = $asset->brand;
        if (!$brand) {
            Log::warning('[ApprovalNotificationService] Asset has no brand, skipping notification', [
                'asset_id' => $asset->id,
            ]);
            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (!$featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Notifications disabled for tenant plan, skipping', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        // Get all approval_capable users with active brand membership
        $recipients = $this->getApprovalCapableUsers($brand);

        // Remove resubmitter from recipients
        $recipients = $recipients->reject(fn ($user) => $user->id === $resubmitter->id);

        if ($recipients->isEmpty()) {
            Log::info('[ApprovalNotificationService] No approvers to notify for resubmitted asset', [
                'asset_id' => $asset->id,
                'brand_id' => $brand->id,
            ]);
            return;
        }

        $this->createNotifications(
            recipients: $recipients,
            type: 'asset.resubmitted',
            data: $this->buildNotificationData($asset, $resubmitter, 'resubmitted'),
        );

        Log::info('[ApprovalNotificationService] Notified approvers of resubmitted asset', [
            'asset_id' => $asset->id,
            'brand_id' => $brand->id,
            'recipient_count' => $recipients->count(),
        ]);
    }

    /**
     * Get all approval_capable users with active brand membership.
     * 
     * Phase MI-1: Uses activeBrandMembership to ensure only active members are included.
     */
    protected function getApprovalCapableUsers(Brand $brand): \Illuminate\Support\Collection
    {
        $tenant = $brand->tenant;
        $users = collect();

        // Get all tenant users
        $tenantUsers = $tenant->users;

        foreach ($tenantUsers as $user) {
            // Phase MI-1: Check active brand membership
            $membership = $user->activeBrandMembership($brand);
            if (!$membership) {
                continue;
            }

            // Check if user is approval_capable
            $brandRole = $membership['role'];
            if ($brandRole && PermissionMap::canApproveAssets($brandRole)) {
                $users->push($user);
            }

            // Also check tenant-level admin/owner (they can approve)
            $tenantRole = $user->getRoleForTenant($tenant);
            if (in_array($tenantRole, ['admin', 'owner'])) {
                // Avoid duplicates if already added via brand role
                if (!$users->contains('id', $user->id)) {
                    $users->push($user);
                }
            }
        }

        return $users->unique('id');
    }

    /**
     * Build email-safe notification data payload.
     */
    protected function buildNotificationData(Asset $asset, User $actor, string $action): array
    {
        $brand = $asset->brand;
        
        // Get asset title with proper fallback (title -> original_filename -> 'Untitled Asset')
        $assetName = $asset->title ?? $asset->original_filename ?? 'Untitled Asset';
        
        $tenant = $brand?->tenant;

        return [
            'asset_id' => $asset->id,
            'asset_name' => $assetName,
            'asset_type' => $asset->asset_type->value ?? 'unknown',
            'brand_id' => $brand?->id,
            'brand_name' => $brand?->name ?? 'Unknown Brand',
            'tenant_id' => $tenant?->id,
            'tenant_name' => $tenant?->name ?? null,
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'actor_email' => $actor->email,
            'created_at' => now()->toISOString(),
        ];
    }

    /**
     * Create notifications for recipients.
     */
    protected function createNotifications(\Illuminate\Support\Collection $recipients, string $type, array $data): void
    {
        $notifications = $recipients->map(function (User $user) use ($type, $data) {
            return [
                'user_id' => $user->id,
                'type' => $type,
                'data' => json_encode($data),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->toArray();

        // Batch insert for efficiency
        if (!empty($notifications)) {
            DB::table('notifications')->insert($notifications);
        }
    }
}
