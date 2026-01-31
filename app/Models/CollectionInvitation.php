<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase C12.0: Pending invite for collection-only access (by email).
 * On accept: create CollectionUser grant; does NOT add brand membership.
 */
class CollectionInvitation extends Model
{
    protected $fillable = [
        'collection_id',
        'email',
        'token',
        'invited_by_user_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
