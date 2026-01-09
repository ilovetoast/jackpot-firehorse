<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TicketSLAState Model
 *
 * Tracks SLA state and timing for each ticket.
 * Stores calculated deadlines, actual response/resolution times, breach flags,
 * and pause/resume state for SLA timers.
 *
 * Pause/Resume Logic:
 * - SLA timers pause when ticket status is waiting_on_user or blocked
 * - When paused, we store paused_at timestamp and last_status_before_pause
 * - When resumed, we calculate total_paused_minutes and adjust deadlines
 * - Only business hours count toward SLA targets (handled by BusinessHoursCalculator)
 */
class TicketSLAState extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ticket_sla_states';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'sla_plan_id',
        'first_response_target_minutes',
        'resolution_target_minutes',
        'first_response_deadline',
        'resolution_deadline',
        'first_response_at',
        'resolved_at',
        'breached_first_response',
        'breached_resolution',
        'paused_at',
        'total_paused_minutes',
        'last_status_before_pause',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'breached_first_response' => 'boolean',
            'breached_resolution' => 'boolean',
            'first_response_deadline' => 'datetime',
            'resolution_deadline' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * Get the ticket that owns this SLA state.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Pause the SLA timer.
     * Called when ticket status changes to waiting_on_user or blocked.
     */
    public function pause(): void
    {
        if ($this->paused_at === null) {
            $this->paused_at = now();
            $this->last_status_before_pause = $this->ticket->status->value;
            $this->save();
        }
    }

    /**
     * Resume the SLA timer.
     * Called when ticket status changes from waiting_on_user or blocked to an active status.
     * Adjusts deadlines by the paused duration.
     */
    public function resume(): void
    {
        if ($this->paused_at !== null) {
            $pausedDuration = now()->diffInMinutes($this->paused_at);
            $this->total_paused_minutes += $pausedDuration;

            // Adjust deadlines by paused duration
            if ($this->first_response_deadline) {
                $this->first_response_deadline = $this->first_response_deadline->addMinutes($pausedDuration);
            }
            if ($this->resolution_deadline) {
                $this->resolution_deadline = $this->resolution_deadline->addMinutes($pausedDuration);
            }

            $this->paused_at = null;
            $this->last_status_before_pause = null;
            $this->save();
        }
    }

    /**
     * Check if deadlines are breached.
     * Updates breach flags and returns breach status.
     *
     * @return array{breached_first_response: bool, breached_resolution: bool}
     */
    public function checkBreaches(): array
    {
        $now = now();
        $breachedFirstResponse = false;
        $breachedResolution = false;

        // Check first response breach (only if not already responded)
        if ($this->first_response_at === null && $this->first_response_deadline) {
            if ($now->greaterThan($this->first_response_deadline)) {
                $breachedFirstResponse = true;
                $this->breached_first_response = true;
            }
        }

        // Check resolution breach (only if not already resolved)
        if ($this->resolved_at === null && $this->resolution_deadline) {
            if ($now->greaterThan($this->resolution_deadline)) {
                $breachedResolution = true;
                $this->breached_resolution = true;
            }
        }

        if ($breachedFirstResponse || $breachedResolution) {
            $this->save();
        }

        return [
            'breached_first_response' => $breachedFirstResponse,
            'breached_resolution' => $breachedResolution,
        ];
    }

    /**
     * Update first response time.
     * Called when first message is sent on the ticket.
     */
    public function updateResponseTime(): void
    {
        if ($this->first_response_at === null) {
            $this->first_response_at = now();
            $this->save();
        }
    }

    /**
     * Update resolution time.
     * Called when ticket status changes to resolved.
     */
    public function updateResolutionTime(): void
    {
        if ($this->resolved_at === null) {
            $this->resolved_at = now();
            $this->save();
        }
    }
}
