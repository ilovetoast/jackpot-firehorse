<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProstaffPeriodStat extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'prostaff_membership_id',
        'period_type',
        'period_start',
        'period_end',
        'target_uploads',
        'actual_uploads',
        'approved_uploads',
        'rejected_uploads',
        'completion_percentage',
        'last_calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'completion_percentage' => 'decimal:2',
            'last_calculated_at' => 'datetime',
        ];
    }

    public function prostaffMembership(): BelongsTo
    {
        return $this->belongsTo(ProstaffMembership::class, 'prostaff_membership_id');
    }

    public static function completionFromCounts(?int $target, int $actual): string
    {
        if ($target === null || $target <= 0) {
            return '0.00';
        }

        $pct = min(100, round(($actual / $target) * 100, 2));

        return number_format($pct, 2, '.', '');
    }

    /**
     * Completion % string for the row’s current {@see $actual_uploads} / {@see $target_uploads}.
     */
    public function completionFromCountsForRow(): string
    {
        return static::completionFromCounts(
            $this->target_uploads !== null ? (int) $this->target_uploads : null,
            (int) $this->actual_uploads
        );
    }

    public function isOnTrack(): bool
    {
        return (float) $this->completion_percentage >= 100.0;
    }
}
