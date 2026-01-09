<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SLAPlan Model
 *
 * Database model for SLA plan overrides and customizations.
 * Default SLA plans are defined in config/sla_plans.php.
 * This model allows database-level overrides for specific plans.
 *
 * Usage:
 * - Config defines default SLA plans
 * - Database records can override specific values
 * - Use mergeWithConfig() to combine config defaults with database overrides
 */
class SLAPlan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'sla_plans';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'plan_name',
        'first_response_target_minutes',
        'resolution_target_minutes',
        'support_hours',
        'escalation_rules',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'support_hours' => 'array',
            'escalation_rules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get SLA plan for a subscription plan name.
     * Returns database override if exists and active, otherwise null.
     *
     * @param string $planName
     * @return self|null
     */
    public static function getForPlan(string $planName): ?self
    {
        return static::where('plan_name', $planName)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Merge database overrides with config defaults.
     * Database values take precedence over config values.
     *
     * @param array $configDefaults Config defaults from config/sla_plans.php
     * @return array Merged SLA plan configuration
     */
    public function mergeWithConfig(array $configDefaults): array
    {
        $merged = $configDefaults;

        // Override with database values if they exist
        if ($this->first_response_target_minutes !== null) {
            $merged['first_response_target_minutes'] = $this->first_response_target_minutes;
        }

        if ($this->resolution_target_minutes !== null) {
            $merged['resolution_target_minutes'] = $this->resolution_target_minutes;
        }

        if ($this->support_hours !== null) {
            $merged['support_hours'] = $this->support_hours;
        }

        if ($this->escalation_rules !== null) {
            $merged['escalation_rules'] = $this->escalation_rules;
        }

        return $merged;
    }
}
