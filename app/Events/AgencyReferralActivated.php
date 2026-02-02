<?php

namespace App\Events;

use App\Models\AgencyPartnerReferral;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Agency Referral Activated Event
 * 
 * Phase AG-10 — Partner Marketing & Referral Attribution
 * 
 * Emitted when a referred client becomes active with billing.
 * 
 * NOTE: This event does NOT grant rewards or advance tiers.
 * It is for attribution tracking only.
 */
class AgencyReferralActivated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public AgencyPartnerReferral $referral
    ) {}
}
