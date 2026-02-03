<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase T-1: Tracks derivative generation failures (thumbnails, previews, posters, waveforms).
 *
 * OBSERVABILITY ONLY. Does NOT affect Asset.status or visibility.
 */
class AssetDerivativeFailure extends Model
{
    protected $table = 'asset_derivative_failures';

    protected $fillable = [
        'asset_id',
        'derivative_type',
        'processor',
        'failure_reason',
        'failure_count',
        'last_failed_at',
        'escalation_ticket_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Check if agent job should be triggered.
     */
    public static function shouldTriggerAgent(self $record): bool
    {
        if ($record->failure_count >= 2) {
            return true;
        }
        $reason = strtolower($record->failure_reason ?? '');
        if (str_contains($reason, 'timeout') || str_contains($reason, 'oom')) {
            return true;
        }

        return false;
    }
}
