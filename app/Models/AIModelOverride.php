<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI Model Override Model
 *
 * Stores database-backed overrides for AI model configuration.
 * Allows administrators to override model active state and default model selection
 * without modifying code. Config files remain the source of truth for base definitions.
 */
class AIModelOverride extends Model
{
    use RecordsActivity;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_model_overrides';

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::AI_MODEL_OVERRIDE_CREATED,
        'updated' => EventType::AI_MODEL_OVERRIDE_UPDATED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'model_key',
        'active',
        'default_for_tasks',
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
            'active' => 'boolean',
            'default_for_tasks' => 'array',
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
     * Scope a query to only include active overrides.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Merge this override with the base config.
     *
     * @param array $config Base config from config/ai.php
     * @return array Merged configuration
     */
    public function mergeWithConfig(array $config): array
    {
        $merged = $config;

        // Override active state if set
        if ($this->active !== null) {
            $merged['active'] = $this->active;
        }

        // Note: default_for_tasks is used by AIConfigService for task-to-model mapping
        // It doesn't directly override the model config, but affects model selection

        return $merged;
    }

    /**
     * Get the effective configuration after merging with base config.
     *
     * @param array $baseConfig Base config from config/ai.php
     * @return array Effective configuration
     */
    public function getEffectiveConfig(array $baseConfig): array
    {
        return $this->mergeWithConfig($baseConfig);
    }
}
