<?php

namespace App\Models;

use App\Enums\EventType;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TicketAttachment Model
 *
 * File attachments for support tickets and messages.
 *
 * Storage:
 * - Files are stored in S3 (or MinIO in local development)
 * - file_path contains the S3 path to the file
 * - Use signed URLs for file access (never expose direct S3 URLs)
 * - Follows existing S3 storage pattern used throughout the application
 *
 * Attachments can be:
 * - Attached to a ticket directly (ticket_message_id is null)
 * - Attached to a specific message (ticket_message_id is set)
 */
class TicketAttachment extends Model
{
    use RecordsActivity;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::TICKET_ATTACHMENT_CREATED,
        'updated' => EventType::TICKET_ATTACHMENT_CREATED, // Using same event for updates
        'deleted' => EventType::TICKET_ATTACHMENT_CREATED, // Using same event for deletes
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'ticket_message_id',
        'user_id',
        'file_path',
        'file_name',
        'file_size',
        'mime_type',
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
            'file_size' => 'integer',
            'is_internal' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include internal attachments.
     */
    public function scopeInternal($query)
    {
        return $query->where('is_internal', true);
    }

    /**
     * Scope a query to only include public attachments.
     */
    public function scopePublic($query)
    {
        return $query->where('is_internal', false);
    }

    /**
     * Get the ticket that owns this attachment.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the message this attachment belongs to (if any).
     */
    public function ticketMessage(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class);
    }

    /**
     * Get the user who uploaded this attachment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
