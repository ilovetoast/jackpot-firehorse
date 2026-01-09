<?php

namespace App\Http\Controllers;

use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Brand;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketAttachmentService;
use App\Services\TicketPlanGate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TenantTicketController
 *
 * Tenant-facing ticket operations.
 * Handles ticket creation, viewing, and replies for tenant users.
 *
 * Visibility Rules:
 * - Only shows tickets where type = tenant AND tenant_id matches user's tenant
 * - Never exposes tenant_internal or internal tickets
 * - Never exposes internal notes (is_internal = true)
 * - Never exposes SLA internals (deadlines, breach flags, assigned team/user)
 */
class TenantTicketController extends Controller
{
    public function __construct(
        protected TicketPlanGate $planGate,
        protected TicketAttachmentService $attachmentService
    ) {
    }

    /**
     * Display a listing of tenant tickets.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        $this->authorize('viewAny', Ticket::class);

        // Only show tenant tickets for this tenant
        $tickets = Ticket::where('type', TicketType::TENANT)
            ->where('tenant_id', $tenant->id)
            ->with(['brands:id,name,logo_path,primary_color,icon,icon_bg_color,icon_path', 'createdBy:id,first_name,last_name,email,avatar_url'])
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Format tickets for display (exclude internal data)
        $formattedTickets = $tickets->getCollection()->map(function ($ticket) use ($tenant) {
            $metadata = $ticket->metadata ?? [];
            $category = $metadata['category'] ?? null;
            $subject = $metadata['subject'] ?? null;

            return [
                'id' => $ticket->id,
                'ticket_number' => $ticket->ticket_number,
                'subject' => $subject,
                'status' => $this->formatStatusForTenant($ticket->status),
                'category' => $category ? TicketCategory::tryFrom($category)?->label() : null,
                'category_value' => $category,
                'created_by' => $ticket->createdBy ? [
                    'id' => $ticket->createdBy->id,
                    'name' => $ticket->createdBy->name,
                    'email' => $ticket->createdBy->email,
                    'first_name' => $ticket->createdBy->first_name,
                    'last_name' => $ticket->createdBy->last_name,
                    'avatar_url' => $ticket->createdBy->avatar_url,
                ] : null,
                'brands' => $ticket->brands->map(fn ($brand) => [
                    'id' => $brand->id, 
                    'name' => $brand->name,
                    'logo_path' => $brand->logo_path,
                    'primary_color' => $brand->primary_color,
                    'icon' => $brand->icon,
                    'icon_bg_color' => $brand->icon_bg_color,
                    'icon_path' => $brand->icon_path,
                ]),
                'created_at' => $this->formatTimestamp($ticket->created_at, $tenant->timezone),
                'updated_at' => $this->formatTimestamp($ticket->updated_at, $tenant->timezone),
            ];
        });

        return Inertia::render('Support/Tickets/Index', [
            'tickets' => $formattedTickets->values(),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ]);
    }

    /**
     * Show the form for creating a new ticket.
     */
    public function create(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        $this->authorize('create', Ticket::class);

        // Get available brands for this tenant
        $brands = $tenant->brands()->orderBy('name')->get(['id', 'name']);

        // Get plan limits and SLA messaging
        $planLimits = [
            'max_attachment_size' => $this->planGate->getMaxAttachmentSizeDisplay($tenant),
            'max_attachments' => $this->planGate->getMaxAttachmentsPerTicket($tenant),
            'can_attach_files' => $this->planGate->canAttachFiles($tenant),
        ];

        $slaMessage = $this->planGate->getSLAExpectationMessage($tenant);

        return Inertia::render('Support/Tickets/Create', [
            'brands' => $brands,
            'plan_limits' => $planLimits,
            'sla_message' => $slaMessage,
            'categories' => array_map(
                fn ($category) => ['value' => $category->value, 'label' => $category->label()],
                TicketCategory::cases()
            ),
        ]);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        $this->authorize('create', Ticket::class);

        try {
            $validated = $request->validate([
                'category' => 'required|string|in:' . implode(',', TicketCategory::values()),
                'brand_ids' => 'required|array|min:1',
                'brand_ids.*' => 'exists:brands,id',
                'subject' => 'required|string|max:255',
                'description' => 'required|string|max:250',
                'attachments' => 'nullable|array|max:' . $this->planGate->getMaxAttachmentsPerTicket($tenant),
                'attachments.*' => 'file|max:' . ($this->planGate->getMaxAttachmentSize($tenant) / 1024), // Convert bytes to KB (Laravel's file|max expects KB)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Manually preserve old input for Inertia
            // Extract input directly from request since $request->old() may be empty at this point
            $inputToPreserve = $request->only(['category', 'brand_ids', 'subject', 'description']);
            
            return back()
                ->withErrors($e->errors())
                ->withInput($inputToPreserve);
        }

        // Verify brands belong to tenant
        $brandIds = $validated['brand_ids'];
        $brands = Brand::whereIn('id', $brandIds)
            ->where('tenant_id', $tenant->id)
            ->get();

        if ($brands->count() !== count($brandIds)) {
            return back()
                ->withErrors(['brand_ids' => 'One or more selected brands are invalid.'])
                ->withInput($request->only(['category', 'brand_ids', 'subject', 'description']));
        }

        DB::beginTransaction();
        try {
            // Create ticket
            $ticket = Ticket::create([
                'type' => TicketType::TENANT,
                'status' => TicketStatus::OPEN,
                'tenant_id' => $tenant->id,
                'created_by_user_id' => $user->id,
                'metadata' => [
                    'category' => $validated['category'],
                    'subject' => $validated['subject'],
                ],
            ]);

            // Attach brands
            $ticket->brands()->attach($brandIds);

            // Create initial message
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'body' => $validated['description'],
                'is_internal' => false,
            ]);

            // Upload attachments if provided
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $this->attachmentService->uploadAttachment($ticket, $file, $message);
                }
            }

            DB::commit();

            return redirect()->route('tickets.show', $ticket)
                ->with('success', 'Ticket created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()
                ->withErrors(['error' => 'Failed to create ticket: ' . $e->getMessage()])
                ->withInput($request->only(['category', 'brand_ids', 'subject', 'description']));
        }
    }

    /**
     * Display the specified ticket.
     */
    public function show(Ticket $ticket): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Policy check
        $this->authorize('view', $ticket);

        // Load ticket with relationships
        $ticket->load([
            'brands:id,name,logo_path,primary_color,icon,icon_bg_color,icon_path',
            'createdBy:id,first_name,last_name,email,avatar_url',
            'messages' => function ($query) {
                // Only load public messages (exclude internal notes)
                $query->where('is_internal', false)
                    ->with('user:id,first_name,last_name,email,avatar_url')
                    ->orderBy('created_at', 'asc');
            },
            'attachments' => function ($query) {
                $query->orderBy('created_at', 'asc');
            },
        ]);

        // Format ticket for display
        $metadata = $ticket->metadata ?? [];
        $category = $metadata['category'] ?? null;
        $subject = $metadata['subject'] ?? null;

        $formattedTicket = [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $subject,
            'status' => $this->formatStatusForTenant($ticket->status),
            'category' => $category ? TicketCategory::tryFrom($category)?->label() : null,
            'category_value' => $category,
            'brands' => $ticket->brands->map(fn ($brand) => [
                'id' => $brand->id, 
                'name' => $brand->name,
                'logo_path' => $brand->logo_path,
                'primary_color' => $brand->primary_color,
                'icon' => $brand->icon,
                'icon_bg_color' => $brand->icon_bg_color,
                'icon_path' => $brand->icon_path,
            ]),
            'created_at' => $this->formatTimestamp($ticket->created_at, $tenant->timezone),
            'updated_at' => $this->formatTimestamp($ticket->updated_at, $tenant->timezone),
            'created_by' => $ticket->createdBy ? [
                'id' => $ticket->createdBy->id,
                'name' => $ticket->createdBy->name,
                'email' => $ticket->createdBy->email,
                'first_name' => $ticket->createdBy->first_name,
                'last_name' => $ticket->createdBy->last_name,
                'avatar_url' => $ticket->createdBy->avatar_url,
            ] : null,
            'messages' => $ticket->messages->map(function ($message) use ($tenant) {
                return [
                    'id' => $message->id,
                    'body' => $message->body,
                    'user' => $message->user ? [
                        'id' => $message->user->id,
                        'name' => $message->user->name,
                        'email' => $message->user->email,
                        'first_name' => $message->user->first_name,
                        'last_name' => $message->user->last_name,
                        'avatar_url' => $message->user->avatar_url,
                    ] : null,
                    'created_at' => $this->formatTimestamp($message->created_at, $tenant->timezone),
                ];
            }),
            'attachments' => $ticket->attachments->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'file_name' => $attachment->file_name,
                    'file_size' => $this->formatFileSize($attachment->file_size),
                    'created_at' => $attachment->created_at->toDateTimeString(),
                    'download_url' => $this->attachmentService->getSignedUrl($attachment),
                ];
            }),
        ];

        // Get plan limits for reply form
        $planLimits = [
            'max_attachment_size' => $this->planGate->getMaxAttachmentSizeDisplay($tenant),
            'max_attachments' => $this->planGate->getMaxAttachmentsPerTicket($tenant),
            'can_attach_files' => $this->planGate->canAttachFiles($tenant),
        ];

        return Inertia::render('Support/Tickets/Show', [
            'ticket' => $formattedTicket,
            'plan_limits' => $planLimits,
        ]);
    }

    /**
     * Reply to a ticket.
     */
    public function reply(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Policy check
        $this->authorize('reply', $ticket);

        $validated = $request->validate([
            'body' => 'required|string|max:250',
            'attachments' => 'nullable|array|max:' . $this->planGate->getMaxAttachmentsPerTicket($tenant),
            'attachments.*' => [
                'file',
                'max:' . ($this->planGate->getMaxAttachmentSize($tenant) / 1024), // Convert bytes to KB (Laravel's file|max expects KB)
            ],
        ]);

        DB::beginTransaction();
        try {
            // Create reply message (always public for tenant replies)
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'body' => $validated['body'],
                'is_internal' => false,
            ]);

            // Upload attachments if provided
            if ($request->hasFile('attachments')) {
                $currentCount = $ticket->attachments()->count();
                foreach ($request->file('attachments') as $file) {
                    // Validate plan limits before uploading
                    $this->attachmentService->validateAttachmentPlan($tenant, $currentCount, $file->getSize());
                    $this->attachmentService->uploadAttachment($ticket, $file, $message);
                    $currentCount++;
                }
            }

            // Update ticket status to waiting_on_support
            $ticket->update(['status' => TicketStatus::WAITING_ON_SUPPORT]);

            DB::commit();

            return redirect()->route('tickets.show', $ticket)
                ->with('success', 'Reply sent successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()
                ->withErrors(['error' => 'Failed to send reply: ' . $e->getMessage()])
                ->withInput($request->only(['body']));
        }
    }

    /**
     * Close a ticket.
     */
    public function close(Request $request, Ticket $ticket)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        // Policy check
        $this->authorize('view', $ticket);

        // Check if ticket is already closed or resolved
        if ($ticket->status === TicketStatus::CLOSED || $ticket->status === TicketStatus::RESOLVED) {
            return back()
                ->withErrors(['error' => 'This ticket is already ' . $ticket->status->value . '.']);
        }

        DB::beginTransaction();
        try {
            // Update ticket status to closed
            $ticket->update(['status' => TicketStatus::CLOSED]);

            DB::commit();

            return redirect()->route('tickets.show', $ticket)
                ->with('success', 'Ticket closed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()
                ->withErrors(['error' => 'Failed to close ticket: ' . $e->getMessage()]);
        }
    }

    /**
     * Format status for tenant display.
     * Hide internal statuses (waiting_on_user, blocked).
     */
    protected function formatStatusForTenant(TicketStatus $status): string
    {
        // Map internal statuses to tenant-friendly statuses
        return match ($status) {
            TicketStatus::WAITING_ON_USER, TicketStatus::BLOCKED => 'in_progress', // Show as in_progress to tenants
            default => $status->value,
        };
    }

    /**
     * Format timestamp in tenant timezone.
     */
    protected function formatTimestamp($timestamp, string $timezone): string
    {
        if (!$timestamp) {
            return '';
        }

        return Carbon::parse($timestamp)
            ->setTimezone($timezone)
            ->format('M j, Y g:i A T');
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
