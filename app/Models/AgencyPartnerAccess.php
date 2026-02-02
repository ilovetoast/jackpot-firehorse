<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AgencyPartnerAccess Model
 * 
 * Phase AG-5 — Post-Transfer Agency Partner Access
 * 
 * Tracks agency partner access grants to client tenants.
 * Provides audit trail for when access was granted and revoked.
 */
class AgencyPartnerAccess extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'agency_partner_access';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'agency_tenant_id',
        'client_tenant_id',
        'user_id',
        'ownership_transfer_id',
        'granted_at',
        'revoked_at',
        'revoked_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the agency tenant.
     */
    public function agencyTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'agency_tenant_id');
    }

    /**
     * Get the client tenant.
     */
    public function clientTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'client_tenant_id');
    }

    /**
     * Get the user who was granted access.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the ownership transfer that triggered this access.
     */
    public function ownershipTransfer(): BelongsTo
    {
        return $this->belongsTo(OwnershipTransfer::class);
    }

    /**
     * Get the user who revoked access.
     */
    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Check if access is currently active.
     */
    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
