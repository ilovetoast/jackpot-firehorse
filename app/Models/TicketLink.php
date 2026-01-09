<?php

namespace App\Models;

use App\Enums\LinkDesignation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * TicketLink Model
 *
 * Polymorphic relationship for linking tickets to other entities
 * (events, error logs, other tickets, etc.)
 */
class TicketLink extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'linkable_type',
        'linkable_id',
        'link_type',
        'designation',
        'metadata',
    ];

    /**
     * Get the ticket that owns this link.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'designation' => LinkDesignation::class,
            'metadata' => 'array',
        ];
    }

    /**
     * Get the linked entity (polymorphic).
     */
    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include primary links.
     */
    public function scopePrimary($query)
    {
        return $query->where('designation', LinkDesignation::PRIMARY);
    }

    /**
     * Scope a query to only include related links.
     */
    public function scopeRelated($query)
    {
        return $query->where('designation', LinkDesignation::RELATED);
    }

    /**
     * Scope a query to only include duplicate links.
     */
    public function scopeDuplicates($query)
    {
        return $query->where('designation', LinkDesignation::DUPLICATE);
    }

    /**
     * Normalize linkable_type value.
     */
    protected function normalizeLinkableType(?string $value): ?string
    {
        if (!$value || str_contains($value, '\\')) {
            return $value; // Already normalized
        }

        // Map short names to full class names
        $typeMap = [
            'user' => \App\Models\User::class,
            'ticket' => \App\Models\Ticket::class,
            'event' => \App\Models\ActivityEvent::class,
            'error_log' => \App\Models\ErrorLog::class,
            'frontend_error' => \App\Models\FrontendError::class,
            'job_failure' => \App\Models\JobFailure::class,
            'ai_agent_run' => \App\Models\AIAgentRun::class,
            'aiagentrun' => \App\Models\AIAgentRun::class,
        ];
        
        $normalized = $typeMap[strtolower($value)] ?? null;
        if ($normalized && class_exists($normalized)) {
            return $normalized;
        }
        
        // Try to construct full class name
        $normalized = 'App\\Models\\' . ucfirst($value);
        if (class_exists($normalized)) {
            return $normalized;
        }
        
        return $value; // Return original if normalization fails
    }

    /**
     * Set the linkable_type attribute with normalization.
     */
    public function setLinkableTypeAttribute($value): void
    {
        $this->attributes['linkable_type'] = $this->normalizeLinkableType($value);
    }

    /**
     * Boot the model to normalize linkable_type values on retrieval.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Normalize linkable_type when model is retrieved from database
        static::retrieved(function ($ticketLink) {
            $rawType = $ticketLink->getRawOriginal('linkable_type');
            if ($rawType) {
                $normalized = $ticketLink->normalizeLinkableType($rawType);
                if ($normalized !== $rawType) {
                    // Update both the attribute and raw attributes
                    $ticketLink->setAttribute('linkable_type', $normalized);
                    $attributes = $ticketLink->getAttributes();
                    $attributes['linkable_type'] = $normalized;
                    $ticketLink->setRawAttributes($attributes, false);
                }
            }
        });
    }
}
