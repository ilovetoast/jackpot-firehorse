<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AgencyPartnerReward Model
 * 
 * Phase AG-4 — Partner Reward Attribution
 * 
 * Represents a single reward granted to an agency for a completed company transfer.
 * One row per completed company transfer. Immutable after creation.
 */
class AgencyPartnerReward extends Model
{
    /**
     * Indicates if the model should be timestamped.
     * We only use created_at, no updated_at.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agency_tenant_id',
        'client_tenant_id',
        'ownership_transfer_id',
        'reward_type',
        'reward_value',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reward_value' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the agency tenant that received this reward.
     */
    public function agencyTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agency_tenant_id');
    }

    /**
     * Get the client tenant that triggered this reward.
     */
    public function clientTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'client_tenant_id');
    }

    /**
     * Get the ownership transfer that triggered this reward.
     */
    public function ownershipTransfer(): BelongsTo
    {
        return $this->belongsTo(OwnershipTransfer::class);
    }
}
