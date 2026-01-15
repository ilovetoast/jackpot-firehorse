<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Phase 5A Step 2 — Automatic Ticket Creation Rules
 * 
 * Defines when alert candidates should automatically generate support tickets.
 * Phase 4 and Phase 5A Step 1 are LOCKED - this phase consumes alerts and tickets only.
 * 
 * TicketCreationRule Model
 * 
 * Defines rules that determine when to automatically create support tickets
 * from alert candidates based on detection rules, severity, and detection count.
 * 
 * @property int $id
 * @property int $rule_id
 * @property string $min_severity (warning|critical)
 * @property int $required_detection_count
 * @property bool $auto_create
 * @property bool $enabled
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read DetectionRule $rule
 */
class TicketCreationRule extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ticket_creation_rules';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'rule_id',
        'min_severity',
        'required_detection_count',
        'auto_create',
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
            'required_detection_count' => 'integer',
            'auto_create' => 'boolean',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Get the detection rule this ticket creation rule is linked to.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(DetectionRule::class, 'rule_id');
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
     * Scope a query by minimum severity.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $severity
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByMinSeverity($query, string $severity)
    {
        return $query->where('min_severity', $severity);
    }

    /**
     * Check if an alert candidate meets the requirements for ticket creation.
     * 
     * @param \App\Models\AlertCandidate $alertCandidate
     * @return bool
     */
    public function shouldCreateTicket(\App\Models\AlertCandidate $alertCandidate): bool
    {
        // Check severity
        if (!$this->meetsSeverityRequirement($alertCandidate->severity)) {
            return false;
        }

        // Check detection count
        if ($alertCandidate->detection_count < $this->required_detection_count) {
            return false;
        }

        // Check if auto_create is enabled
        if (!$this->auto_create) {
            return false;
        }

        return true;
    }

    /**
     * Check if alert severity meets the minimum requirement.
     * 
     * @param string $alertSeverity
     * @return bool
     */
    protected function meetsSeverityRequirement(string $alertSeverity): bool
    {
        $severityLevels = [
            'info' => 1,
            'warning' => 2,
            'critical' => 3,
        ];

        $alertLevel = $severityLevels[$alertSeverity] ?? 0;
        $requiredLevel = $severityLevels[$this->min_severity] ?? 0;

        return $alertLevel >= $requiredLevel;
    }
}
