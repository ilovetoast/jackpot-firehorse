<?php

namespace App\Models;

use App\Enums\EventType;
use App\Enums\TicketStatus;
use App\Enums\TicketTeam;
use App\Enums\TicketType;
use App\Enums\TicketSeverity;
use App\Enums\TicketEnvironment;
use App\Enums\TicketComponent;
use App\Models\TicketSLAState;
use App\Services\TicketAssignmentService;
use App\Services\TicketSLAService;
use App\Traits\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ticket Model
 *
 * Support ticket system for multi-tenant SaaS application.
 *
 * Ticket Types & Visibility Rules:
 * - tenant: Visible to tenant users when assigned to their tenant
 * - tenant_internal: Never visible to tenants (internal notes about tenant issues)
 * - internal: Never visible to tenants (internal-only tickets)
 *
 * Future Phases:
 * - Phase 2: UI implementation
 * - Phase 3: SLA enforcement and tracking (uses sla_plan_id, first_response_at, resolved_at)
 * - Phase 4: Notifications and email integration
 * - Phase 5: Automation and workflows
 * - Phase 6: AI features (uses metadata field)
 */
class Ticket extends Model
{
    use RecordsActivity;

    /**
     * Custom event names for activity logging.
     */
    protected static $activityEventNames = [
        'created' => EventType::TICKET_CREATED,
        'updated' => EventType::TICKET_UPDATED,
        'deleted' => EventType::TICKET_DELETED,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'type',
        'status',
        'tenant_id',
        'created_by_user_id',
        'assigned_to_user_id',
        'assigned_team',
        'sla_plan_id',
        'first_response_at',
        'resolved_at',
        'metadata',
        'converted_from_ticket_id',
        'converted_at',
        'converted_by_user_id',
        'severity',
        'environment',
        'component',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TicketType::class,
            'status' => TicketStatus::class,
            'assigned_team' => TicketTeam::class,
            'severity' => TicketSeverity::class,
            'environment' => TicketEnvironment::class,
            'component' => TicketComponent::class,
            'metadata' => 'array',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate ticket number on creation
        static::creating(function ($ticket) {
            if (empty($ticket->ticket_number)) {
                // Set a temporary placeholder value since the column is required
                // This will be replaced with the actual ticket number in the created event
                // Use microtime and random to ensure uniqueness
                $ticket->ticket_number = 'TEMP-' . microtime(true) . '-' . mt_rand(1000, 9999);
            }
        });

        // Set ticket number after the model is created (we have the ID now)
        static::created(function ($ticket) {
            // Replace temporary ticket number with actual one
            if (str_starts_with($ticket->ticket_number, 'TEMP-')) {
                $ticket->ticket_number = 'SUP-' . $ticket->id;
                $ticket->saveQuietly(); // Save without triggering events
            }

            // Assign SLA plan to ticket
            $slaService = app(TicketSLAService::class);
            $slaService->assignSLAToTicket($ticket);

            // Assign ticket to team and user
            $assignmentService = app(TicketAssignmentService::class);
            $assignmentService->assignTicket($ticket);
        });

        // Handle SLA pause/resume on status changes
        static::updated(function ($ticket) {
            if ($ticket->isDirty('status')) {
                $slaService = app(TicketSLAService::class);

                // Pause SLA if status is waiting_on_user or blocked
                if (in_array($ticket->status, [TicketStatus::WAITING_ON_USER, TicketStatus::BLOCKED])) {
                    $slaService->pauseSLA($ticket);
                } else {
                    // Resume SLA if status changed from paused state
                    $slaService->resumeSLA($ticket);
                }

                // Update resolution time if status changed to resolved
                if ($ticket->status === TicketStatus::RESOLVED) {
                    $slaService->updateResolutionTime($ticket);
                }

                // Check for breaches after status change
                $slaService->checkBreaches($ticket);
            }
        });
    }

    /**
     * Get the tenant that owns this ticket (if any).
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user who created this ticket.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Get the user assigned to this ticket (if any).
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * Get the messages for this ticket.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    /**
     * Get the attachments for this ticket.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Get the brands associated with this ticket.
     */
    public function brands(): BelongsToMany
    {
        return $this->belongsToMany(Brand::class)->withTimestamps();
    }

    /**
     * Get the SLA state for this ticket.
     */
    public function slaState(): HasOne
    {
        return $this->hasOne(TicketSLAState::class);
    }

    /**
     * Get the ticket this was converted from (if any).
     */
    public function convertedFrom(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'converted_from_ticket_id');
    }

    /**
     * Get tickets that were converted from this ticket.
     */
    public function convertedTo(): HasMany
    {
        return $this->hasMany(Ticket::class, 'converted_from_ticket_id');
    }

    /**
     * Get the user who converted this ticket (if converted).
     */
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by_user_id');
    }

    /**
     * Get ticket links where this ticket is the parent.
     */
    public function ticketLinks(): HasMany
    {
        return $this->hasMany(TicketLink::class);
    }

    /**
     * Get the AI suggestions for this ticket.
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(\App\Models\AITicketSuggestion::class);
    }

    /**
     * Pause SLA timer.
     * Delegates to TicketSLAService.
     */
    public function pauseSLA(): void
    {
        app(TicketSLAService::class)->pauseSLA($this);
    }

    /**
     * Resume SLA timer.
     * Delegates to TicketSLAService.
     */
    public function resumeSLA(): void
    {
        app(TicketSLAService::class)->resumeSLA($this);
    }

    /**
     * Check SLA breaches.
     * Delegates to TicketSLAService.
     *
     * @return array{breached_first_response: bool, breached_resolution: bool}
     */
    public function checkSLA(): array
    {
        return app(TicketSLAService::class)->checkBreaches($this);
    }

    /**
     * Scope a query to only include tickets for a specific tenant.
     */
    public function scopeForTenant(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant->id);
    }

    /**
     * Scope a query to only include tickets of a specific type.
     */
    public function scopeOfType(Builder $query, TicketType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include tickets with a specific status.
     */
    public function scopeWithStatus(Builder $query, TicketStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include tickets assigned to a specific team.
     */
    public function scopeAssignedToTeam(Builder $query, TicketTeam $team): Builder
    {
        return $query->where('assigned_team', $team);
    }

    /**
     * Scope a query to only include converted tickets.
     */
    public function scopeConverted(Builder $query): Builder
    {
        return $query->whereNotNull('converted_from_ticket_id');
    }

    /**
     * Scope a query to only include internal engineering tickets.
     * Engineering tickets are: type=internal AND assigned_team=engineering
     */
    public function scopeEngineering(Builder $query): Builder
    {
        return $query->where('type', TicketType::INTERNAL)
            ->where('assigned_team', TicketTeam::ENGINEERING);
    }

    /**
     * Scope a query to filter by severity level.
     */
    public function scopeBySeverity(Builder $query, TicketSeverity $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope a query to filter by environment.
     */
    public function scopeByEnvironment(Builder $query, TicketEnvironment $environment): Builder
    {
        return $query->where('environment', $environment);
    }

    /**
     * Scope a query to filter by component.
     */
    public function scopeByComponent(Builder $query, TicketComponent $component): Builder
    {
        return $query->where('component', $component);
    }

    /**
     * Get the error fingerprint from metadata.
     */
    public function getErrorFingerprintAttribute(): ?string
    {
        return $this->metadata['error_fingerprint'] ?? null;
    }

    /**
     * Set the error fingerprint in metadata.
     */
    public function setErrorFingerprintAttribute(?string $value): void
    {
        $metadata = $this->metadata ?? [];
        if ($value === null) {
            unset($metadata['error_fingerprint']);
        } else {
            $metadata['error_fingerprint'] = $value;
        }
        $this->metadata = $metadata;
    }

    /**
     * Scope a query to include all ticket types for staff users.
     * This scope does not filter by type - it's used in staff contexts
     * where all types (tenant, tenant_internal, internal) are visible.
     */
    public function scopeForStaff(Builder $query): Builder
    {
        // No filtering - staff can see all ticket types
        return $query;
    }
}
