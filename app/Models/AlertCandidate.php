<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 🔒 Phase 4 Step 4 — Alert Candidate Generation
 * 
 * Consumes pattern detection results from locked phases only.
 * Must not modify detection rules, aggregation logic, or event producers.
 * 
 * AlertCandidate Model
 * 
 * Represents a detected anomalous condition that may require attention.
 * Alert candidates are persisted for review, suppression, escalation, or AI explanation.
 * 
 * Status Lifecycle:
 * - open: Newly detected or unresolved alert
 * - acknowledged: Alert has been seen/acknowledged but not yet resolved
 * - resolved: Alert condition has been resolved or closed
 * 
 * Deduplication:
 * - Same rule + scope + subject + status='open' → update existing
 * - New rule + scope + subject → create new
 * - Allows multiple alerts for same rule+scope+subject if previous is acknowledged/resolved
 * 
 * @property int $id
 * @property int $rule_id
 * @property string $scope (global|tenant|asset|download)
 * @property string|null $subject_id (tenant_id, asset_id, download_id, or null)
 * @property int|null $tenant_id
 * @property string $severity (info|warning|critical)
 * @property int $observed_count
 * @property int $threshold_count
 * @property int $window_minutes
 * @property string $status (open|acknowledged|resolved)
 * @property \Carbon\Carbon $first_detected_at
 * @property \Carbon\Carbon $last_detected_at
 * @property int $detection_count
 * @property array|null $context
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read DetectionRule $rule
 * @property-read Tenant|null $tenant
 */
class AlertCandidate extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alert_candidates';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'rule_id',
        'scope',
        'subject_id',
        'tenant_id',
        'severity',
        'observed_count',
        'threshold_count',
        'window_minutes',
        'status',
        'first_detected_at',
        'last_detected_at',
        'detection_count',
        'context',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_count' => 'integer',
            'threshold_count' => 'integer',
            'window_minutes' => 'integer',
            'detection_count' => 'integer',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'context' => 'array',
        ];
    }

    /**
     * Get the detection rule that triggered this alert.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DetectionRule::class, 'rule_id');
    }

    /**
     * Get the tenant associated with this alert (if applicable).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Get the AI-generated summary for this alert candidate.
     */
    public function summary(): HasOne
    {
        return $this->hasOne(AlertSummary::class, 'alert_candidate_id');
    }

    /**
     * Get the support ticket linked to this alert candidate (if any).
     * 
     * Phase 5A: Support Ticket Integration
     */
    public function supportTicket(): HasOne
    {
        return $this->hasOne(SupportTicket::class, 'alert_candidate_id');
    }

    /**
     * Scope a query to only open alerts.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope a query by severity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $severity
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope a query by tenant.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query by rule.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $ruleId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByRule($query, int $ruleId)
    {
        return $query->where('rule_id', $ruleId);
    }
}
