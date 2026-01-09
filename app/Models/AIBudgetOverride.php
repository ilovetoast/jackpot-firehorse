<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Budget Override Model
 *
 * Stores database-backed overrides for AI budget configuration.
 * Allows administrators to override budget settings without modifying code.
 */
class AIBudgetOverride extends Model
{
    use RecordsActivity;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_budget_overrides';

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::AI_BUDGET_OVERRIDE_CREATED,
        'updated' => EventType::AI_BUDGET_OVERRIDE_UPDATED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'budget_id',
        'amount',
        'warning_threshold_percent',
        'hard_limit_enabled',
        'environment',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'warning_threshold_percent' => 'integer',
            'hard_limit_enabled' => 'boolean',
        ];
    }

    /**
     * Get the budget this override belongs to.
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(AIBudget::class, 'budget_id');
    }

    /**
     * Get the user who created this override.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user who last updated this override.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Scope a query to filter by environment.
     */
    public function scopeByEnvironment(Builder $query, ?string $environment): Builder
    {
        return $query->where(function ($q) use ($environment) {
            $q->whereNull('environment')
                ->when($environment, fn ($q) => $q->orWhere('environment', $environment));
        });
    }

    /**
     * Merge override values with base config.
     *
     * @param array $config Base configuration array
     * @return array Merged configuration
     */
    public function mergeWithConfig(array $config): array
    {
        $merged = $config;

        if ($this->amount !== null) {
            $merged['amount'] = $this->amount;
        }

        if ($this->warning_threshold_percent !== null) {
            $merged['warning_threshold_percent'] = $this->warning_threshold_percent;
        }

        if ($this->hard_limit_enabled !== null) {
            $merged['hard_limit_enabled'] = $this->hard_limit_enabled;
        }

        return $merged;
    }
}
