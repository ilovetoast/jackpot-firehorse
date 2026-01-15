<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Phase 5A Step 1 — Support Ticket Model
 * 
 * Represents support tickets that can be linked to alert candidates.
 * Phase 4 is LOCKED - this model consumes alerts only, does not modify them.
 * 
 * SupportTicket Model
 * 
 * Represents a support ticket that can be:
 * - Created automatically from alert candidates (system source)
 * - Created manually by support staff (manual source)
 * - Linked to external ticket systems via external_reference
 * 
 * @property int $id
 * @property int|null $alert_candidate_id
 * @property string $summary
 * @property string|null $description
 * @property string $severity (info|warning|critical)
 * @property string $status (open|in_progress|resolved|closed)
 * @property string $source (system|manual)
 * @property string|null $external_reference
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read AlertCandidate|null $alertCandidate
 */
class SupportTicket extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'support_tickets';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'alert_candidate_id',
        'summary',
        'description',
        'severity',
        'status',
        'source',
        'external_reference',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alert_candidate_id' => 'integer',
        ];
    }

    /**
     * Get the alert candidate that this ticket is linked to (if any).
     */
    public function alertCandidate(): BelongsTo
    {
        return $this->belongsTo(AlertCandidate::class, 'alert_candidate_id');
    }

    /**
     * Scope a query to only open tickets.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope a query by status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
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
     * Scope a query by source.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $source
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeBySource($query, string $source)
    {
        return $query->where('source', $source);
    }
}
