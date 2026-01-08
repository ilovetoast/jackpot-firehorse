<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\OwnershipTransferStatus;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OwnershipTransfer Model
 * 
 * Represents a secure, multi-step tenant ownership transfer workflow.
 * This is NOT a simple role change - it requires explicit confirmation and acceptance.
 */
class OwnershipTransfer extends Model
{
    use RecordsActivity;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::TENANT_OWNER_TRANSFER_INITIATED,
        'updated' => null, // Status changes are logged manually via service
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'initiated_by_user_id',
        'from_user_id',
        'to_user_id',
        'status',
        'initiated_at',
        'confirmed_at',
        'accepted_at',
        'completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OwnershipTransferStatus::class,
            'initiated_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that this transfer belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who initiated this transfer.
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * Get the current owner (from user).
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Get the new owner (to user).
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * Check if the transfer is in an active state.
     */
    public function isActive(): bool
    {
        return in_array($this->status, [
            OwnershipTransferStatus::PENDING,
            OwnershipTransferStatus::CONFIRMED,
            OwnershipTransferStatus::ACCEPTED,
        ]);
    }

    /**
     * Check if the transfer is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === OwnershipTransferStatus::COMPLETED;
    }

    /**
     * Check if the transfer is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === OwnershipTransferStatus::CANCELLED;
    }
}
