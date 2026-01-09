<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Automation Override Model
 *
 * Stores database-backed overrides for AI automation trigger configuration.
 * Allows administrators to override trigger enabled state and thresholds
 * without modifying code. Config files remain the source of truth for base definitions.
 */
class AIAutomationOverride extends Model
{
    use RecordsActivity;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_automation_overrides';

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::AI_AUTOMATION_OVERRIDE_CREATED,
        'updated' => EventType::AI_AUTOMATION_OVERRIDE_UPDATED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'trigger_key',
        'enabled',
        'thresholds',
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
            'enabled' => 'boolean',
            'thresholds' => 'array',
        ];
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
     * Scope a query to only include overrides for a specific environment.
     */
    public function scopeByEnvironment(Builder $query, ?string $environment): Builder
    {
        if ($environment === null) {
            return $query->whereNull('environment');
        }

        return $query->where(function ($q) use ($environment) {
            $q->whereNull('environment')
                ->orWhere('environment', $environment);
        });
    }

    /**
     * Scope a query to only include enabled overrides.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Merge this override with the base config.
     *
     * @param array $config Base config from config/automation.php
     * @return array Merged configuration
     */
    public function mergeWithConfig(array $config): array
    {
        $merged = $config;

        // Override enabled state if set
        if ($this->enabled !== null) {
            $merged['enabled'] = $this->enabled;
        }

        // Merge thresholds if set
        if ($this->thresholds !== null && is_array($this->thresholds)) {
            $merged = array_merge($merged, $this->thresholds);
        }

        return $merged;
    }

    /**
     * Get the effective configuration after merging with base config.
     *
     * @param array $baseConfig Base config from config/automation.php
     * @return array Effective configuration
     */
    public function getEffectiveConfig(array $baseConfig): array
    {
        return $this->mergeWithConfig($baseConfig);
    }
}
