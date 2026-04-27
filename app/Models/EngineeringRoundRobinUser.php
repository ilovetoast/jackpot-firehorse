<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineeringRoundRobinUser extends Model
{
    protected $table = 'engineering_round_robin_users';

    protected $fillable = ['user_id', 'sort_order'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<int, int>
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
