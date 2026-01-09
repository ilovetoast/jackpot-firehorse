<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI Budget Usage Model
 *
 * Tracks budget consumption per period (monthly).
 * Records are created/reset at the start of each period.
 */
class AIBudgetUsage extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_budget_usage';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'budget_id',
        'period_start',
        'period_end',
        'amount_used',
        'last_updated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount_used' => 'decimal:2',
            'last_updated_at' => 'datetime',
        ];
    }

    /**
     * Get the budget this usage record belongs to.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(AIBudget::class, 'budget_id');
    }

    /**
     * Increment usage by the given amount.
     */
    public function incrementUsage(float $amount): void
    {
        $this->increment('amount_used', $amount);
        $this->update(['last_updated_at' => now()]);
    }

    /**
     * Reset usage for a new period.
     */
    public function resetForPeriod(Carbon $periodStart, Carbon $periodEnd): void
    {
        $this->update([
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'amount_used' => 0,
            'last_updated_at' => now(),
        ]);
    }
}
