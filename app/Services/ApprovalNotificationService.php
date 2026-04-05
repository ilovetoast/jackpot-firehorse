<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\ProstaffUploadBatch;
use App\Models\User;
use App\Support\Roles\PermissionMap;
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
        if (! $brand) {
            Log::warning('[ApprovalNotificationService] Asset has no brand, skipping notification', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (! $featureGate->notificationsEnabled($tenant)) {
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
        if (! $uploader) {
            Log::warning('[ApprovalNotificationService] Asset has no uploader, skipping notification', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        $brand = $asset->brand;
        if (! $brand) {
            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (! $featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Notifications disabled for tenant plan, skipping', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);

            return;
        }

        // Phase MI-1: Verify uploader has active brand membership
        $membership = $uploader->activeBrandMembership($brand);
        if (! $membership) {
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
        if (! $uploader) {
            Log::warning('[ApprovalNotificationService] Asset has no uploader, skipping notification', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        $brand = $asset->brand;
        if (! $brand) {
            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (! $featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Notifications disabled for tenant plan, skipping', [
                'asset_id' => $asset->id,
                'tenant_id' => $tenant->id,
            ]);

            return;
        }

        // Phase MI-1: Verify uploader has active brand membership
        $membership = $uploader->activeBrandMembership($brand);
        if (! $membership) {
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
        if (! $brand) {
            Log::warning('[ApprovalNotificationService] Asset has no brand, skipping notification', [
                'asset_id' => $asset->id,
            ]);

            return;
        }

        // Phase AF-5: Gate notifications based on plan feature
        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (! $featureGate->notificationsEnabled($tenant)) {
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
     * Public wrapper for prostaff batch notifications and other callers that need the same resolver.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function approvalCapableRecipientsForBrand(Brand $brand): \Illuminate\Support\Collection
    {
        return $this->getApprovalCapableUsers($brand);
    }

    /**
     * Phase 5: grouped in-app notification for multiple prostaff uploads in one batch window.
     */
    public function notifyProstaffUploadBatch(ProstaffUploadBatch $batch): void
    {
        $brand = $batch->brand;
        if (! $brand) {
            Log::warning('[ApprovalNotificationService] Prostaff batch missing brand', [
                'batch_id' => $batch->id,
            ]);

            return;
        }

        $tenant = $brand->tenant;
        $featureGate = app(FeatureGate::class);
        if (! $featureGate->notificationsEnabled($tenant)) {
            Log::info('[ApprovalNotificationService] Prostaff batch skipped (notifications disabled for plan)', [
                'batch_id' => $batch->id,
                'tenant_id' => $tenant->id,
            ]);

            return;
        }

        $uploader = $batch->prostaffUser;
        $resolver = app(\App\Services\Prostaff\ResolveProstaffBatchNotificationRecipients::class);
        $recipients = $resolver->resolve($brand, $uploader);

        if ($recipients->isEmpty()) {
            Log::info('[ApprovalNotificationService] No recipients for prostaff upload batch', [
                'batch_id' => $batch->id,
                'brand_id' => $brand->id,
            ]);

            return;
        }

        $uploaderName = $uploader?->name ?? 'Prostaff uploader';
        $count = (int) $batch->upload_count;
        $message = $count === 1
            ? "1 upload from {$uploaderName}"
            : "{$count} uploads from {$uploaderName}";

        $data = [
            'brand_id' => $brand->id,
            'brand_name' => $brand->name,
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'upload_count' => $count,
            'prostaff_user_id' => $batch->prostaff_user_id,
            'prostaff_user_name' => $uploaderName,
            'first_asset_id' => $batch->first_asset_id,
            'last_asset_id' => $batch->last_asset_id,
            'message' => $message,
            'action' => 'prostaff_batch',
            'created_at' => now()->toISOString(),
        ];

        $this->createNotifications(
            recipients: $recipients,
            type: 'prostaff.upload.batch',
            data: $data,
        );

        Log::info('[ApprovalNotificationService] Prostaff upload batch notified', [
            'batch_id' => $batch->id,
            'recipient_count' => $recipients->count(),
            'upload_count' => $count,
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
            if (! $membership) {
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
                if (! $users->contains('id', $user->id)) {
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
            'asset_type' => $asset->type?->value ?? 'unknown',
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
     * Create notifications for recipients (grouped by type + brand + date).
     */
    protected function createNotifications(\Illuminate\Support\Collection $recipients, string $type, array $data): void
    {
        $groupService = app(NotificationGroupService::class);
        foreach ($recipients as $user) {
            $groupService->upsert($user->id, $type, $data);
        }
    }
}
