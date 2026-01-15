<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔒 Phase 4 Step 5 — AI Summaries for Alert Candidates
 * 
 * Consumes alert candidates from locked phases only.
 * Must not modify alert candidate lifecycle, detection rules, or aggregation logic.
 * 
 * AlertSummary Model
 * 
 * Stores AI-generated human-readable summaries for alert candidates.
 * Summaries explain what is happening, who is affected, severity, and suggest next steps.
 * 
 * One summary per alert candidate (1:1 relationship).
 * Summaries can be regenerated when detection_count increases significantly or severity changes.
 * 
 * @property int $id
 * @property int $alert_candidate_id
 * @property string $summary_text
 * @property string|null $impact_summary
 * @property string|null $affected_scope
 * @property string $severity (info|warning|critical)
 * @property array|null $suggested_actions
 * @property float $confidence_score (0.00-1.00)
 * @property \Carbon\Carbon $generated_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read AlertCandidate $alertCandidate
 */
class AlertSummary extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'alert_summaries';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'alert_candidate_id',
        'summary_text',
        'impact_summary',
        'affected_scope',
        'severity',
        'suggested_actions',
        'confidence_score',
        'generated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suggested_actions' => 'array',
            'confidence_score' => 'float',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * Get the alert candidate this summary belongs to.
     */
    public function alertCandidate(): BelongsTo
    {
        return $this->belongsTo(AlertCandidate::class, 'alert_candidate_id');
    }
}
