<?php

namespace App\Events;

use App\Models\OwnershipTransfer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * CompanyTransferCompleted Event
 *
 * Phase AG-4 — Partner Reward Attribution
 * 
 * Domain event emitted when a company ownership transfer completes successfully.
 * This event is fired AFTER the ownership has been transferred and the transfer
 * is marked as COMPLETED.
 *
 * Listeners:
 * - GrantAgencyPartnerReward: Grants partner rewards to originating agency
 */
class CompanyTransferCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param OwnershipTransfer $transfer The completed ownership transfer
     */
    public function __construct(
        public OwnershipTransfer $transfer
    ) {
    }
}
