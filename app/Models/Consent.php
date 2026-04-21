<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    protected $fillable = [
        'user_id',
        'purpose',
        'granted',
        'policy_version',
        'granted_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Latest grant per purpose for a user (for UI prefill).
     *
     * @return array{functional: bool, analytics: bool, marketing: bool}|null
     */
    public static function latestPurposesForUser(int $userId): ?array
    {
        $purposes = ['functional', 'analytics', 'marketing'];
        $out = [];
        $hasAny = false;
        foreach ($purposes as $p) {
            $row = static::query()
                ->where('user_id', $userId)
                ->where('purpose', $p)
                ->orderByDesc('granted_at')
                ->orderByDesc('id')
                ->first();
            $out[$p] = $row ? (bool) $row->granted : false;
            if ($row) {
                $hasAny = true;
            }
        }

        return $hasAny ? $out : null;
    }

    public static function latestRecordForUser(int $userId): ?self
    {
        return static::query()
            ->where('user_id', $userId)
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->first();
    }
}
