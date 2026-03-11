<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportRoundRobinUser extends Model
{
    protected $table = 'support_round_robin_users';

    protected $fillable = ['user_id', 'sort_order'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get ordered user IDs in the round-robin bucket.
     *
     * @return array<int>
     */
    public static function getBucketUserIds(): array
    {
        return static::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('user_id')
            ->toArray();
    }
}
