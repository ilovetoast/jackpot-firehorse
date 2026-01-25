<?php

namespace App\Events;

use App\Models\Asset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AssetPendingApproval Event
 *
 * Phase L.6.3 — Approval Notifications
 * 
 * Domain event emitted when an asset requires approval (category with requires_approval = true).
 * This event is fired after the asset is set to HIDDEN status and unpublished.
 *
 * Listeners:
 * - SendAssetPendingApprovalNotification: Sends email notifications to approvers
 */
class AssetPendingApproval
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Asset $asset The asset pending approval
     * @param \App\Models\User|null $uploader The user who uploaded the asset
     * @param string|null $categoryName The category name (from metadata)
     */
    public function __construct(
        public Asset $asset,
        public ?\App\Models\User $uploader = null,
        public ?string $categoryName = null
    ) {
    }
}
