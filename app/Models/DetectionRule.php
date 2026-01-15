<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 🔒 Phase 4 Step 3 — Pattern Detection Rules
 * 
 * Consumes aggregates from locked phases only.
 * Must not modify event producers or aggregation logic.
 * 
 * DetectionRule Model
 * 
 * Declarative pattern detection rules for identifying system health issues,
 * tenant-specific failures, and cross-tenant anomalies.
 * 
 * Rules are evaluated against event aggregates and return matched results.
 * This model stores rule definitions only — evaluation logic is in PatternDetectionService.
 * 
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $event_type
 * @property string $scope (global|tenant|asset|download)
 * @property int $threshold_count
 * @property int $threshold_window_minutes
 * @property string $comparison (greater_than|greater_than_or_equal)
 * @property array|null $metadata_filters
 * @property string $severity (info|warning|critical)
 * @property bool $enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class DetectionRule extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'detection_rules';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'event_type',
        'scope',
        'threshold_count',
        'threshold_window_minutes',
        'comparison',
        'metadata_filters',
        'severity',
        'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'threshold_count' => 'integer',
            'threshold_window_minutes' => 'integer',
            'metadata_filters' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Scope a query to only enabled rules.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /**
     * Scope a query by scope type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $scope
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByScope($query, string $scope)
    {
        return $query->where('scope', $scope);
    }

    /**
     * Scope a query by event type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $eventType
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }
}
