<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantInvitation extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'email',
        'role',
        'token',
        'invited_by',
        'brand_assignments',
        'sent_at',
        'accepted_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'brand_assignments' => 'array',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that this invitation is for.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who sent this invitation.
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if the invitation is pending (not accepted).
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null;
    }
}
