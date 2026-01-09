<?php

namespace App\Models;

use App\Enums\AutomationSuggestionStatus;
use App\Enums\AutomationSuggestionType;
use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AITicketSuggestion Model
 *
 * Stores AI-generated suggestions for tickets.
 * All suggestions require explicit human approval before being applied.
 *
 * Suggestion types:
 * - classification: Suggested category, severity, component
 * - duplicate: Suggested duplicate ticket links
 * - ticket_creation: Suggested internal ticket from error patterns
 * - severity: Suggested severity adjustment
 */
class AITicketSuggestion extends Model
{
    use RecordsActivity;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_ticket_suggestions';

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::TICKET_SUGGESTION_CREATED,
        'updated' => EventType::TICKET_SUGGESTION_UPDATED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'suggestion_type',
        'suggested_value',
        'confidence_score',
        'ai_agent_run_id',
        'status',
        'accepted_at',
        'accepted_by_user_id',
        'rejected_at',
        'rejected_by_user_id',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suggestion_type' => AutomationSuggestionType::class,
            'suggested_value' => 'array',
            'confidence_score' => 'decimal:2',
            'status' => AutomationSuggestionStatus::class,
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the ticket this suggestion belongs to.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the AI agent run that generated this suggestion.
     */
    public function aiAgentRun(): BelongsTo
    {
        return $this->belongsTo(AIAgentRun::class);
    }

    /**
     * Get the user who accepted this suggestion.
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * Get the user who rejected this suggestion.
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    /**
     * Scope a query to only include pending suggestions.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AutomationSuggestionStatus::PENDING);
    }

    /**
     * Scope a query to only include accepted suggestions.
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', AutomationSuggestionStatus::ACCEPTED);
    }

    /**
     * Scope a query to only include rejected suggestions.
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', AutomationSuggestionStatus::REJECTED);
    }

    /**
     * Scope a query to only include suggestions of a specific type.
     */
    public function scopeByType(Builder $query, AutomationSuggestionType $type): Builder
    {
        return $query->where('suggestion_type', $type);
    }

    /**
     * Accept this suggestion.
     *
     * @param User $user The user accepting the suggestion
     * @return void
     */
    public function accept(User $user): void
    {
        $this->update([
            'status' => AutomationSuggestionStatus::ACCEPTED,
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);
    }

    /**
     * Reject this suggestion.
     *
     * @param User $user The user rejecting the suggestion
     * @return void
     */
    public function reject(User $user): void
    {
        $this->update([
            'status' => AutomationSuggestionStatus::REJECTED,
            'rejected_at' => now(),
            'rejected_by_user_id' => $user->id,
        ]);
    }

    /**
     * Mark this suggestion as expired.
     *
     * @return void
     */
    public function expire(): void
    {
        $this->update([
            'status' => AutomationSuggestionStatus::EXPIRED,
        ]);
    }
}
