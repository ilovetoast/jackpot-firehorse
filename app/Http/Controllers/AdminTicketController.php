<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Enums\LinkDesignation;
use App\Enums\TicketCategory;
use App\Enums\TicketComponent;
use App\Enums\TicketEnvironment;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Enums\TicketTeam;
use App\Enums\TicketType;
use App\Models\ActivityEvent;
use App\Models\AITicketSuggestion;
use App\Models\Brand;
use App\Models\Ticket;
use App\Models\TicketLink;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\TicketAttachmentService;
use App\Services\TicketAuditService;
use App\Services\TicketConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AdminTicketController
 *
 * Staff-facing ticket management operations.
 * Provides full operational interface for Site Support, Site Admin, Site Owner,
 * Compliance, and Site Engineering roles.
 *
 * Key Features:
 * - View all ticket types (tenant, tenant_internal, internal)
 * - Assignment and reassignment
 * - Status management
 * - Internal notes (never visible to tenants)
 * - Ticket conversion (tenant → internal)
 * - Ticket linking (to events, logs, other tickets)
 * - SLA monitoring (internal-only)
 * - Audit logging
 *
 * Authorization:
 * - All methods check TicketPolicy for appropriate permissions
 * - Compliance role has view-only access (enforced at policy level)
 * - Tenant users cannot access these routes (enforced by middleware/policy)
 *
 * Tenant Isolation:
 * - Staff can see tickets across all tenants
 * - Tenant users remain restricted to their own tenant's tickets
 * - Internal notes and SLA data never exposed to tenants
 *
 * SLA Visibility (Internal Only):
 * - SLA data (deadlines, breach status, time remaining) is only included in responses for staff users
 * - Tenant-facing endpoints never include SLA data
 * - SLA targets are operational goals, NOT customer-facing guarantees
 * - This ensures tenants don't see internal performance metrics
 *
 * Ticket Conversion Rationale:
 * - Allows engineering to track technical issues separately from customer-facing tickets
 * - Maintains audit trail linking original customer request to internal work
 * - Original ticket remains accessible to tenant for status updates
 * - Conversion is restricted to Site Admin and Site Owner to prevent misuse
 *
 * Role Boundaries:
 * - Site Support: Can manage tenant tickets, add internal notes, change status
 * - Site Engineering: Can view and manage internal tickets, view converted tenant tickets
 * - Site Admin/Owner: Full access including ticket conversion and assignment overrides
 * - Compliance: View-only access (no assignment, no deletion, no conversion)
 */
class AdminTicketController extends Controller
{
    public function __construct(
        protected TicketAttachmentService $attachmentService,
        protected TicketConversionService $conversionService,
        protected TicketAuditService $auditService
    ) {
    }

    /**
     * Display ticket queue with filtering and sorting.
     *
     * Filters:
     * - Status, Category, Assigned Team, Assigned User, Tenant, Brand(s), SLA State
     *
     * Sorting:
     * - Oldest first (default)
     * - SLA urgency (deadline ascending)
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $this->authorize('viewAnyForStaff', Ticket::class);

        $query = Ticket::query()
            ->with([
                'suggestions' => function ($query) {
                    $query->pending()->select('id', 'ticket_id');
                },
                'tenant:id,name,slug',
                'createdBy:id,first_name,last_name,email,avatar_url',
                'assignedTo:id,first_name,last_name,email,avatar_url',
                'brands:id,name,logo_path,primary_color,icon,icon_bg_color,icon_path',
                'slaState:id,ticket_id,first_response_deadline,resolution_deadline,breached_first_response,breached_resolution',
            ])
            ->forStaff(); // Include all ticket types

        // Apply filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $query->whereJsonContains('metadata->category', $request->category);
        }

        if ($request->filled('assigned_team')) {
            $query->where('assigned_team', $request->assigned_team);
        }

        if ($request->filled('assigned_to_user_id')) {
            $query->where('assigned_to_user_id', $request->assigned_to_user_id);
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->filled('brand_ids')) {
            $brandIds = is_array($request->brand_ids) ? $request->brand_ids : [$request->brand_ids];
            $query->whereHas('brands', function ($q) use ($brandIds) {
                $q->whereIn('brands.id', $brandIds);
            });
        }

        // Engineering field filters
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        
        if ($request->filled('environment')) {
            $query->where('environment', $request->environment);
        }
        
        if ($request->filled('component')) {
            $query->where('component', $request->component);
        }
        
        // Filter for engineering tickets (type=internal, assigned_team=engineering)
        if ($request->filled('engineering_only')) {
            $query->where('type', TicketType::INTERNAL)
                ->where('assigned_team', TicketTeam::ENGINEERING);
        }

        // SLA state filters
        if ($request->filled('sla_state')) {
            if ($request->sla_state === 'approaching_breach') {
                $query->whereHas('slaState', function ($q) {
                    $q->where('first_response_deadline', '<=', now()->addHours(2))
                        ->where('breached_first_response', false)
                        ->whereNull('first_response_at')
                        ->orWhere(function ($q) {
                            $q->where('resolution_deadline', '<=', now()->addHours(2))
                                ->where('breached_resolution', false)
                                ->whereNull('resolved_at');
                        });
                });
            } elseif ($request->sla_state === 'breached') {
                $query->whereHas('slaState', function ($q) {
                    $q->where('breached_first_response', true)
                        ->orWhere('breached_resolution', true);
                });
            }
        }

        // Apply sorting
        if ($request->filled('sort') && $request->sort === 'sla_urgency') {
            $query->leftJoin('ticket_sla_states', 'tickets.id', '=', 'ticket_sla_states.ticket_id')
                ->orderByRaw('COALESCE(ticket_sla_states.resolution_deadline, ticket_sla_states.first_response_deadline) ASC NULLS LAST')
                ->select('tickets.*');
        } else {
            $query->orderBy('created_at', 'asc'); // Default: oldest first
        }

        $tickets = $query->paginate(10)->withQueryString();

        // Format tickets for display
        $formattedTickets = $tickets->map(function ($ticket) {
            return $this->formatTicketForList($ticket);
        });

        // Get filter options
        $filterOptions = [
            'statuses' => array_map(fn($s) => ['value' => $s->value, 'label' => ucfirst(str_replace('_', ' ', $s->name))], TicketStatus::cases()),
            'categories' => array_map(fn($c) => ['value' => $c->value, 'label' => $c->label()], TicketCategory::cases()),
            'teams' => array_map(fn($t) => ['value' => $t->value, 'label' => ucfirst($t->value)], TicketTeam::cases()),
            'tenants' => \App\Models\Tenant::select('id', 'name')->orderBy('name')->get(),
            'brands' => Brand::select('id', 'name', 'tenant_id')->orderBy('name')->get(),
            'staff_users' => User::whereHas('roles', function ($q) {
                $q->whereIn('name', ['site_support', 'site_admin', 'site_owner', 'site_engineering']);
            })->select('id', 'first_name', 'last_name', 'email')->orderBy('first_name')->get(),
        ];

        return Inertia::render('Admin/Support/Tickets/Index', [
            'tickets' => $formattedTickets,
            'pagination' => $tickets->toArray(),
            'filterOptions' => $filterOptions,
            'filters' => $request->only(['status', 'category', 'assigned_team', 'assigned_to_user_id', 'tenant_id', 'brand_ids', 'sla_state', 'sort', 'severity', 'environment', 'component', 'engineering_only']),
        ]);
    }

    /**
     * Display ticket detail view.
     */
    public function show(Ticket $ticket): Response
    {
        $user = Auth::user();
        $this->authorize('viewForStaff', $ticket);

        // Load ticket with all relationships (convertedFrom/convertedTo only if conversion columns exist)
        $relations = [
            'tenant:id,name,slug',
            'createdBy:id,first_name,last_name,email,avatar_url',
            'assignedTo:id,first_name,last_name,email,avatar_url',
            'brands:id,name,logo_path,primary_color,icon,icon_bg_color,icon_path',
            'slaState',
            'messages' => function ($query) {
                $query->with('user:id,first_name,last_name,email,avatar_url')
                    ->orderBy('created_at', 'asc');
            },
            'attachments' => function ($query) {
                $query->orderBy('created_at', 'asc');
            },
            'ticketLinks',
        ];
        if (Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
            $relations[] = 'convertedFrom:id,ticket_number,type,status';
            $relations[] = 'convertedTo:id,ticket_number,type,status';
        }
        $ticket->load($relations);

        // Separate public and internal messages
        $publicMessages = $ticket->messages->where('is_internal', false)->values();
        $internalNotes = $ticket->messages->where('is_internal', true)->values();

        // Format ticket data
        $formattedTicket = $this->formatTicketForDetail($ticket);
        
        // Add download URLs for attachments
        $formattedTicket['attachments'] = $ticket->attachments->map(function ($attachment) {
            return [
                'id' => $attachment->id,
                'file_name' => $attachment->file_name,
                'file_size' => $this->formatFileSize($attachment->file_size),
                'created_at' => $attachment->created_at->toDateTimeString(),
                'download_url' => $this->attachmentService->getSignedUrl($attachment),
            ];
        });

        // Include SLA data (staff only)
        $slaData = null;
        if ($user->can('viewSLA', $ticket)) {
            $slaData = $this->formatSLAData($ticket);
        }

        // Include audit log (Site Owner/Compliance only)
        $auditLog = null;
        if ($user->can('viewAuditLog', $ticket)) {
            $auditLog = $this->auditService->getAuditLog($ticket);
        }

        // Get staff users for assignment dropdown
        $staffUsers = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['site_support', 'site_admin', 'site_owner', 'site_engineering']);
        })->select('id', 'first_name', 'last_name', 'email')->orderBy('first_name')->get();

        // Get filter options for engineering fields
        $filterOptions = [
            'severities' => array_map(fn($s) => ['value' => $s->value, 'label' => $s->label()], \App\Enums\TicketSeverity::cases()),
            'environments' => array_map(fn($e) => ['value' => $e->value, 'label' => $e->label()], \App\Enums\TicketEnvironment::cases()),
            'components' => array_map(fn($c) => ['value' => $c->value, 'label' => $c->label()], \App\Enums\TicketComponent::cases()),
        ];

        // Get AI suggestions for this ticket
        $suggestions = AITicketSuggestion::where('ticket_id', $ticket->id)
            ->pending()
            ->with('aiAgentRun:id,agent_id,task_type,estimated_cost,started_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($suggestion) {
                return [
                    'id' => $suggestion->id,
                    'suggestion_type' => $suggestion->suggestion_type->value,
                    'suggested_value' => $suggestion->suggested_value,
                    'confidence_score' => $suggestion->confidence_score,
                    'created_at' => $suggestion->created_at->toDateTimeString(),
                    'ai_agent_run' => $suggestion->aiAgentRun ? [
                        'id' => $suggestion->aiAgentRun->id,
                        'agent_id' => $suggestion->aiAgentRun->agent_id,
                        'task_type' => $suggestion->aiAgentRun->task_type,
                        'estimated_cost' => $suggestion->aiAgentRun->estimated_cost,
                        'started_at' => $suggestion->aiAgentRun->started_at->toDateTimeString(),
                    ] : null,
                ];
            });

        return Inertia::render('Admin/Support/Tickets/Show', [
            'ticket' => $formattedTicket,
            'publicMessages' => $publicMessages,
            'internalNotes' => $internalNotes,
            'slaData' => $slaData,
            'auditLog' => $auditLog,
            'staffUsers' => $staffUsers,
            'filterOptions' => $filterOptions,
            'suggestions' => $suggestions,
            'permissions' => [
                'canAssign' => $user->can('assign', $ticket),
                'canAddInternalNote' => $user->can('addInternalNote', $ticket),
                'canConvert' => $user->can('convert', $ticket),
                'canViewSLA' => $user->can('viewSLA', $ticket),
                'canViewAuditLog' => $user->can('viewAuditLog', $ticket),
            ],
        ]);
    }

    /**
     * Update ticket assignment.
     */
    public function updateAssignment(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('assign', $ticket);

        $validated = $request->validate([
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'assigned_team' => 'required|string|in:' . implode(',', array_column(TicketTeam::cases(), 'value')),
        ]);

        // Store old values for audit log
        $oldAssignedUserId = $ticket->assigned_to_user_id;
        $oldAssignedTeam = $ticket->assigned_team?->value;

        // Get user names for audit log
        $oldAssignedUser = $oldAssignedUserId ? User::find($oldAssignedUserId)?->name : 'Unassigned';
        $newAssignedUser = $validated['assigned_to_user_id'] ? User::find($validated['assigned_to_user_id'])?->name : 'Unassigned';

        $ticket->update([
            'assigned_to_user_id' => $validated['assigned_to_user_id'],
            'assigned_team' => TicketTeam::from($validated['assigned_team']),
        ]);

        // Log assignment change
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1, // Use ticket tenant or fallback
            eventType: EventType::TICKET_ASSIGNED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'old_assigned_user_id' => $oldAssignedUserId,
                'new_assigned_user_id' => $validated['assigned_to_user_id'],
                'old_assigned_user' => $oldAssignedUser,
                'new_assigned_user' => $newAssignedUser,
                'old_assigned_team' => $oldAssignedTeam,
                'new_assigned_team' => $validated['assigned_team'],
            ]
        );

        return redirect()->back()->with('success', 'Ticket assignment updated.');
    }

    /**
     * Resolve a ticket.
     * Sets status to resolved and records resolution time for SLA tracking.
     */
    public function resolve(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('assign', $ticket);

        if ($ticket->status === TicketStatus::RESOLVED) {
            return redirect()->back()->with('info', 'Ticket is already resolved.');
        }

        if ($ticket->status === TicketStatus::CLOSED) {
            return redirect()->back()->withErrors(['status' => 'Cannot resolve a closed ticket. Please reopen it first.']);
        }

        $oldStatus = $ticket->status->value;
        $ticket->update(['status' => TicketStatus::RESOLVED]);
        // Note: Ticket model observer will automatically call updateResolutionTime() for SLA tracking

        // Log status change
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_STATUS_CHANGED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'old_status' => $oldStatus,
                'new_status' => 'resolved',
                'action' => 'resolve',
            ]
        );

        return redirect()->back()->with('success', 'Ticket resolved. Resolution time has been recorded for SLA tracking.');
    }

    /**
     * Close a ticket.
     * Sets status to closed (final state).
     */
    public function close(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('assign', $ticket);

        if ($ticket->status === TicketStatus::CLOSED) {
            return redirect()->back()->with('info', 'Ticket is already closed.');
        }

        $oldStatus = $ticket->status->value;
        $ticket->update(['status' => TicketStatus::CLOSED]);

        // Log status change
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_STATUS_CHANGED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'old_status' => $oldStatus,
                'new_status' => 'closed',
                'action' => 'close',
            ]
        );

        return redirect()->back()->with('success', 'Ticket closed.');
    }

    /**
     * Reopen a ticket.
     * Changes status from resolved/closed back to open.
     */
    public function reopen(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('assign', $ticket);

        if ($ticket->status !== TicketStatus::RESOLVED && $ticket->status !== TicketStatus::CLOSED) {
            return redirect()->back()->with('info', 'Ticket is not in a final state and cannot be reopened.');
        }

        $oldStatus = $ticket->status->value;
        $ticket->update(['status' => TicketStatus::OPEN]);

        // Log status change
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_STATUS_CHANGED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'old_status' => $oldStatus,
                'new_status' => 'open',
                'action' => 'reopen',
            ]
        );

        return redirect()->back()->with('success', 'Ticket reopened.');
    }

    /**
     * Update ticket status.
     */
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('assign', $ticket); // Use assign permission for status changes

        $validated = $request->validate([
            'status' => 'required|string|in:' . implode(',', array_column(TicketStatus::cases(), 'value')),
        ]);

        $oldStatus = $ticket->status->value;
        $ticket->update(['status' => TicketStatus::from($validated['status'])]);

        // Log status change
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_STATUS_CHANGED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
            ]
        );

        return redirect()->back()->with('success', 'Ticket status updated.');
    }

    /**
     * Add public reply to ticket.
     */
    public function publicReply(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('assign', $ticket); // Use assign permission for public replies

        $validated = $request->validate([
            'body' => 'required|string|max:10000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:5120', // 5MB max
        ]);

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'is_internal' => false, // Public message
        ]);

        // Handle attachments if provided
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->attachmentService->uploadAttachment($ticket, $file, $message, false); // false = public attachment
            }
        }

        // Update ticket status to waiting_on_user when staff replies
        if ($ticket->status === TicketStatus::WAITING_ON_SUPPORT) {
            $ticket->update(['status' => TicketStatus::WAITING_ON_USER]);
        }

        // Log public message creation
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_MESSAGE_CREATED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'message_id' => $message->id,
                'is_internal' => false,
            ]
        );

        return redirect()->back()->with('success', 'Public reply added.');
    }

    /**
     * Add internal note to ticket.
     */
    public function addInternalNote(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('addInternalNote', $ticket);

        $validated = $request->validate([
            'body' => 'required|string|max:10000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:5120', // 5MB max
        ]);

        $message = TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
            'is_internal' => true,
        ]);

        // Handle attachments if provided
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $this->attachmentService->uploadAttachment($ticket, $file, $message, true);
            }
        }

        // Log internal note creation
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_INTERNAL_NOTE_ADDED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'message_id' => $message->id,
            ]
        );

        return redirect()->back()->with('success', 'Internal note added.');
    }

    /**
     * Upload internal attachment.
     */
    public function uploadInternalAttachment(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('addInternalNote', $ticket);

        $validated = $request->validate([
            'attachment' => 'required|file|max:5120', // 5MB max
        ]);

        $this->attachmentService->uploadAttachment($ticket, $request->file('attachment'), null, true);

        return redirect()->back()->with('success', 'Internal attachment uploaded.');
    }

    /**
     * Convert tenant ticket to internal ticket.
     */
    public function convert(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('convert', $ticket);

        if ($ticket->type !== TicketType::TENANT) {
            return redirect()->back()->withErrors(['ticket' => 'Only tenant tickets can be converted.']);
        }

        $validated = $request->validate([
            'severity' => 'nullable|string|in:' . implode(',', array_column(TicketSeverity::cases(), 'value')),
            'environment' => 'nullable|string|in:' . implode(',', array_column(TicketEnvironment::cases(), 'value')),
            'component' => 'nullable|string|in:' . implode(',', array_column(TicketComponent::cases(), 'value')),
        ]);

        $severity = isset($validated['severity']) ? TicketSeverity::from($validated['severity']) : null;
        $environment = isset($validated['environment']) ? TicketEnvironment::from($validated['environment']) : null;
        $component = isset($validated['component']) ? TicketComponent::from($validated['component']) : null;

        $internalTicket = $this->conversionService->convertToInternal($ticket, $user, $severity, $environment, $component);

        // Log conversion
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_CONVERTED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'original_ticket_id' => $ticket->id,
                'original_ticket_number' => $ticket->ticket_number,
                'new_ticket_id' => $internalTicket->id,
                'new_ticket_number' => $internalTicket->ticket_number,
            ]
        );

        return redirect()->route('admin.support.tickets.show', $internalTicket)
            ->with('success', 'Ticket converted to internal ticket.');
    }

    /**
     * Link ticket to event/log/other ticket.
     */
    public function link(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('addInternalNote', $ticket); // Use same permission as internal notes

        $validated = $request->validate([
            'linkable_type' => 'required|string',
            'linkable_id' => 'required|integer',
            'link_type' => 'required|string|in:event,error_log,ticket,frontend_error,job_failure',
            'designation' => 'nullable|string|in:' . implode(',', array_column(LinkDesignation::cases(), 'value')),
            'metadata' => 'nullable|array',
        ]);

        // Normalize linkable_type to full class name
        // Handle both short names (e.g., "user", "ticket") and full class names (e.g., "App\Models\User")
        $linkableType = $validated['linkable_type'];
        
        // Map common short names to full class names
        $typeMap = [
            'user' => \App\Models\User::class,
            'ticket' => \App\Models\Ticket::class,
            'event' => \App\Models\ActivityEvent::class,
            'error_log' => \App\Models\ErrorLog::class,
            'frontend_error' => \App\Models\FrontendError::class,
            'job_failure' => \App\Models\JobFailure::class,
        ];
        
        // If it's a short name, get the full class name
        if (isset($typeMap[strtolower($linkableType)])) {
            $linkableType = $typeMap[strtolower($linkableType)];
        } elseif (!class_exists($linkableType)) {
            // If it's not a recognized short name and not a valid class, try to construct it
            if (!str_contains($linkableType, '\\')) {
                $linkableType = 'App\\Models\\' . ucfirst($linkableType);
            }
            
            if (!class_exists($linkableType)) {
                return redirect()->back()->withErrors(['linkable_type' => 'Invalid model type.']);
            }
        }
        
        $linkable = $linkableType::find($validated['linkable_id']);
        if (!$linkable) {
            return redirect()->back()->withErrors(['linkable_id' => 'Linked item not found.']);
        }

        // Use the actual model's class name to ensure consistency
        $actualClassName = get_class($linkable);

        TicketLink::create([
            'ticket_id' => $ticket->id,
            'linkable_type' => $actualClassName,
            'linkable_id' => $validated['linkable_id'],
            'link_type' => $validated['link_type'],
            'designation' => $validated['designation'] ?? LinkDesignation::RELATED,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        // Log linking
        ActivityRecorder::record(
            tenant: $ticket->tenant_id ?? 1,
            eventType: EventType::TICKET_LINKED,
            subject: $ticket,
            actor: $user,
            brand: null,
            metadata: [
                'link_type' => $validated['link_type'],
                'linkable_type' => $actualClassName,
                'linkable_id' => $validated['linkable_id'],
            ]
        );

        return redirect()->back()->with('success', 'Ticket linked successfully.');
    }

    /**
     * Create an internal engineering ticket.
     */
    public function createEngineeringTicket(Request $request)
    {
        $user = Auth::user();
        $this->authorize('createEngineeringTicket', Ticket::class);

        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string|max:10000',
            'severity' => 'nullable|string|in:' . implode(',', array_column(TicketSeverity::cases(), 'value')),
            'environment' => 'nullable|string|in:' . implode(',', array_column(TicketEnvironment::cases(), 'value')),
            'component' => 'nullable|string|in:' . implode(',', array_column(TicketComponent::cases(), 'value')),
            'error_fingerprint' => 'nullable|string|max:255',
            'tenant_id' => 'nullable|exists:tenants,id',
        ]);

        $creationService = app(\App\Services\TicketCreationService::class);
        
        $ticket = $creationService->createInternalEngineeringTicket([
            'subject' => $validated['subject'],
            'description' => $validated['description'],
            'severity' => $validated['severity'] ?? null,
            'environment' => $validated['environment'] ?? null,
            'component' => $validated['component'] ?? null,
            'error_fingerprint' => $validated['error_fingerprint'] ?? null,
            'tenant_id' => $validated['tenant_id'] ?? null,
        ], $user);

        return redirect()->route('admin.support.tickets.show', $ticket)
            ->with('success', 'Engineering ticket created successfully.');
    }

    /**
     * Get audit log for ticket.
     */
    public function auditLog(Ticket $ticket)
    {
        $user = Auth::user();
        $this->authorize('viewAuditLog', $ticket);

        $auditLog = $this->auditService->getAuditLog($ticket);

        return response()->json($auditLog);
    }

    /**
     * Format ticket for list display.
     */
    protected function formatTicketForList(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'type' => $ticket->type->value,
            'status' => $ticket->status->value,
            'subject' => $ticket->metadata['subject'] ?? '—',
            'category' => $ticket->metadata['category'] ?? null,
            'category_value' => $ticket->metadata['category'] ?? null,
            'tenant' => $ticket->tenant ? [
                'id' => $ticket->tenant->id,
                'name' => $ticket->tenant->name,
            ] : null,
            'assigned_team' => $ticket->assigned_team?->value,
            'assigned_to' => $ticket->assignedTo ? [
                'id' => $ticket->assignedTo->id,
                'name' => $ticket->assignedTo->name,
                'email' => $ticket->assignedTo->email,
            ] : null,
            'created_by' => $ticket->createdBy ? [
                'id' => $ticket->createdBy->id,
                'name' => $ticket->createdBy->name,
                'email' => $ticket->createdBy->email,
                'avatar_url' => $ticket->createdBy->avatar_url,
            ] : null,
            'brands' => $ticket->brands->map(fn($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'logo_path' => $b->logo_path,
                'primary_color' => $b->primary_color,
                'icon' => $b->icon,
                'icon_bg_color' => $b->icon_bg_color,
                'icon_path' => $b->icon_path,
            ]),
            'sla_state' => $ticket->slaState ? [
                'breached_first_response' => $ticket->slaState->breached_first_response,
                'breached_resolution' => $ticket->slaState->breached_resolution,
                'first_response_deadline' => $ticket->slaState->first_response_deadline?->toISOString(),
                'resolution_deadline' => $ticket->slaState->resolution_deadline?->toISOString(),
            ] : null,
            // Engineering fields (only for internal engineering tickets)
            'severity' => $ticket->severity?->value,
            'environment' => $ticket->environment?->value,
            'component' => $ticket->component?->value,
            'error_fingerprint' => $ticket->error_fingerprint,
            'has_pending_suggestions' => $ticket->relationLoaded('suggestions')
                ? $ticket->suggestions->isNotEmpty()
                : AITicketSuggestion::where('ticket_id', $ticket->id)->pending()->exists(),
            'created_at' => $ticket->created_at->toISOString(),
            'updated_at' => $ticket->updated_at->toISOString(),
        ];
    }

    /**
     * Format ticket for detail display.
     */
    protected function formatTicketForDetail(Ticket $ticket): array
    {
        $listData = $this->formatTicketForList($ticket);
        
        $convertedFrom = null;
        $convertedTo = [];
        if (Schema::hasColumn('tickets', 'converted_from_ticket_id')) {
            $convertedFrom = $ticket->convertedFrom ? [
                'id' => $ticket->convertedFrom->id,
                'ticket_number' => $ticket->convertedFrom->ticket_number,
                'type' => $ticket->convertedFrom->type->value,
                'status' => $ticket->convertedFrom->status->value,
            ] : null;
            $convertedTo = $ticket->convertedTo->map(fn ($t) => [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'type' => $t->type->value,
                'status' => $t->status->value,
            ])->all();
        }

        return array_merge($listData, [
            'description' => $ticket->metadata['description'] ?? null,
            'converted_from' => $convertedFrom,
            'converted_to' => $convertedTo,
            'links' => $ticket->ticketLinks->map(fn($link) => [
                'id' => $link->id,
                'link_type' => $link->link_type,
                'linkable_type' => $link->linkable_type,
                'linkable_id' => $link->linkable_id,
                'designation' => $link->designation?->value,
                'metadata' => $link->metadata,
            ]),
        ]);
    }

    /**
     * Format SLA data for display.
     */
    protected function formatSLAData(Ticket $ticket): ?array
    {
        if (!$ticket->slaState) {
            return null;
        }

        $now = now();
        $sla = $ticket->slaState;

        return [
            'first_response_target_minutes' => $sla->first_response_target_minutes,
            'resolution_target_minutes' => $sla->resolution_target_minutes,
            'first_response_deadline' => $sla->first_response_deadline?->toISOString(),
            'resolution_deadline' => $sla->resolution_deadline?->toISOString(),
            'first_response_at' => $sla->first_response_at?->toISOString(),
            'resolved_at' => $sla->resolved_at?->toISOString(),
            'breached_first_response' => $sla->breached_first_response,
            'breached_resolution' => $sla->breached_resolution,
            'first_response_time_remaining' => $sla->first_response_deadline && !$sla->first_response_at
                ? max(0, $now->diffInMinutes($sla->first_response_deadline, false))
                : null,
            'resolution_time_remaining' => $sla->resolution_deadline && !$sla->resolved_at
                ? max(0, $now->diffInMinutes($sla->resolution_deadline, false))
                : null,
            'paused_at' => $sla->paused_at?->toISOString(),
            'total_paused_minutes' => $sla->total_paused_minutes ?? 0,
        ];
    }

    /**
     * Accept an AI suggestion.
     *
     * @param Request $request
     * @param AITicketSuggestion $suggestion
     * @return \Illuminate\Http\RedirectResponse
     */
    public function acceptSuggestion(Request $request, AITicketSuggestion $suggestion)
    {
        $user = Auth::user();
        $this->authorize('assign', $suggestion->ticket);

        try {
            DB::transaction(function () use ($suggestion, $user) {
                $ticket = $suggestion->ticket;
                $suggestedValue = $suggestion->suggested_value;

                // Apply suggestion based on type
                if ($suggestion->suggestion_type->value === 'classification') {
                    $metadata = $ticket->metadata ?? [];
                    if (isset($suggestedValue['category'])) {
                        $metadata['category'] = $suggestedValue['category'];
                    }
                    $ticket->update(['metadata' => $metadata]);

                    // If engineering ticket, apply severity, component, environment
                    if ($ticket->assigned_team?->value === 'engineering' || $ticket->type->value === 'internal') {
                        if (isset($suggestedValue['severity'])) {
                            $ticket->severity = \App\Enums\TicketSeverity::tryFrom($suggestedValue['severity']);
                        }
                        if (isset($suggestedValue['component'])) {
                            $ticket->component = \App\Enums\TicketComponent::tryFrom($suggestedValue['component']);
                        }
                        if (isset($suggestedValue['environment'])) {
                            $ticket->environment = \App\Enums\TicketEnvironment::tryFrom($suggestedValue['environment']);
                        }
                        $ticket->save();
                    }
                } elseif ($suggestion->suggestion_type->value === 'duplicate') {
                    // Duplicate links are already created, just confirm them
                    $pendingLinks = TicketLink::where('ticket_id', $ticket->id)
                        ->where('metadata->suggestion_id', $suggestion->id)
                        ->where('metadata->pending_confirmation', true)
                        ->get();

                    foreach ($pendingLinks as $link) {
                        $metadata = $link->metadata ?? [];
                        unset($metadata['pending_confirmation']);
                        $link->update(['metadata' => $metadata]);
                    }
                }

                // Mark suggestion as accepted
                $suggestion->accept($user);
            });

            return redirect()->back()->with('success', 'Suggestion accepted and applied.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to accept suggestion: ' . $e->getMessage()]);
        }
    }

    /**
     * Reject an AI suggestion.
     *
     * @param Request $request
     * @param AITicketSuggestion $suggestion
     * @return \Illuminate\Http\RedirectResponse
     */
    public function rejectSuggestion(Request $request, AITicketSuggestion $suggestion)
    {
        $user = Auth::user();
        $this->authorize('assign', $suggestion->ticket);

        try {
            DB::transaction(function () use ($suggestion, $user) {
                // Remove pending links if duplicate suggestion
                if ($suggestion->suggestion_type->value === 'duplicate') {
                    TicketLink::where('ticket_id', $suggestion->ticket_id)
                        ->where('metadata->suggestion_id', $suggestion->id)
                        ->where('metadata->pending_confirmation', true)
                        ->delete();
                }

                // Mark suggestion as rejected
                $suggestion->reject($user);
            });

            return redirect()->back()->with('success', 'Suggestion rejected.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to reject suggestion: ' . $e->getMessage()]);
        }
    }

    /**
     * Create internal ticket from error pattern suggestion.
     *
     * @param Request $request
     * @param AITicketSuggestion $suggestion
     * @return \Illuminate\Http\RedirectResponse
     */
    public function createTicketFromSuggestion(Request $request, AITicketSuggestion $suggestion)
    {
        $user = Auth::user();
        $this->authorize('createEngineeringTicket', Ticket::class);

        if ($suggestion->suggestion_type->value !== 'ticket_creation') {
            return redirect()->back()->withErrors(['error' => 'Suggestion is not a ticket creation suggestion.']);
        }

        try {
            $suggestedValue = $suggestion->suggested_value;
            $creationService = app(\App\Services\TicketCreationService::class);

            $ticket = DB::transaction(function () use ($suggestion, $suggestedValue, $creationService, $user) {
                // Create ticket from suggestion
                $ticket = $creationService->createInternalEngineeringTicket([
                    'subject' => $suggestedValue['subject'] ?? 'Error Pattern Detected',
                    'description' => $suggestedValue['description'] ?? '',
                    'severity' => $suggestedValue['severity'] ?? null,
                    'environment' => $suggestedValue['environment'] ?? null,
                    'component' => $suggestedValue['component'] ?? null,
                    'error_fingerprint' => $suggestedValue['error_fingerprint'] ?? null,
                    'tenant_id' => null, // System-level ticket
                ], $user);

                // Link errors to ticket
                if (isset($suggestion->metadata['error_ids'])) {
                    $errorType = $suggestion->metadata['error_type'] ?? 'error_log';
                    foreach ($suggestion->metadata['error_ids'] as $errorId) {
                        $linkableType = $errorType === 'frontend_error' ? \App\Models\FrontendError::class : \App\Models\ErrorLog::class;
                        TicketLink::create([
                            'ticket_id' => $ticket->id,
                            'linkable_type' => $linkableType,
                            'linkable_id' => $errorId,
                            'link_type' => $errorType,
                            'designation' => LinkDesignation::PRIMARY,
                        ]);
                    }
                }

                // Mark suggestion as accepted and link to created ticket
                $suggestion->accept($user);
                $suggestion->update(['ticket_id' => $ticket->id]);

                return $ticket;
            });

            return redirect()->route('admin.support.tickets.show', $ticket)
                ->with('success', 'Internal ticket created from suggestion.');
        } catch (\Exception $e) {
            return redirect()->back()->withErrors(['error' => 'Failed to create ticket: ' . $e->getMessage()]);
        }
    }

    /**
     * Format file size for display.
     */
    protected function formatFileSize(?int $bytes): string
    {
        if (!$bytes) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
