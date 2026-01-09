<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TicketMessage Model
 *
 * Messages/notes associated with support tickets.
 *
 * Message Types:
 * - Public (is_internal = false): Visible to all users who can see the ticket
 * - Internal (is_internal = true): Only visible to support staff, never shown to tenants
 *
 * Internal notes are used for:
 * - Support team coordination
 * - Internal investigation notes
 * - Escalation notes
 * - Any information that should not be visible to the ticket creator/tenant
 */
class TicketMessage extends Model
{
    use RecordsActivity;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::TICKET_MESSAGE_CREATED,
        'updated' => EventType::TICKET_MESSAGE_CREATED, // Using same event for updates
        'deleted' => EventType::TICKET_MESSAGE_CREATED, // Using same event for deletes
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'user_id',
        'body',
        'is_internal',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }

    /**
     * Get the ticket that owns this message.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the user who created this message.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attachments for this message.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Scope a query to only include internal messages.
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    /**
     * Scope a query to only include public messages.
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Update first response time when first message is created
        static::created(function ($message) {
            $ticket = $message->ticket;
            if ($ticket && $ticket->first_response_at === null) {
                // Only count non-internal messages as first response
                if (!$message->is_internal) {
                    $slaService = app(\App\Services\TicketSLAService::class);
                    $slaService->updateResponseTime($ticket);
                }
            }
        });
    }
}
