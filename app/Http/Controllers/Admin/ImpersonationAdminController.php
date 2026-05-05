<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImpersonationMode;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Models\ImpersonationAudit;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Command Center UI for internal support sessions (start, history, audits, operator force-end).
 *
 * Privacy: {@see ImpersonationAudit} rows store HTTP method, path, route name, and optional meta JSON only —
 * no request bodies, tokens, cookies, or signed URLs are persisted. If that contract widens in the future,
 * review this surface before exposing anything to tenants.
 *
 * Future enterprise (tenant-visible) support access log — intentionally not built here:
 * Tenants could receive a safe, summarized log only (occurrence, mode, reason/ticket ref, window, support label)
 * without raw routes or per-request detail by default. Defer until product/legal sign-off.
 *
 * Roadmap (not implemented): per-tenant support-access policy flags, e.g.
 * `allow_support_readonly_access`, `allow_full_admin_access`, `require_customer_approval_for_full` — would gate
 * which modes can be started for that workspace and whether customer approval is required.
 */
class ImpersonationAdminController extends Controller
{
    /**
     * See {@see \App\Support\Roles\RoleRegistry::siteRolesThatMayStartInternalImpersonation()} (site_engineering excluded until assisted mode).
     */
    protected function authorizeInternalSupportStarter(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }
        if (! app(ImpersonationService::class)->initiatorMayStartInternalImpersonation($user)) {
            abort(403, 'You do not have access to internal support sessions.');
        }

        return $user;
    }

    protected function authorizeForceEndSession(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }
        if (! app(ImpersonationService::class)->initiatorMayForceEndImpersonationSession($user)) {
            abort(403, 'Only site administrators can force-end support sessions.');
        }

        return $user;
    }

    protected function assertMayViewSession(User $viewer, ImpersonationSession $session): void
    {
        if (app(ImpersonationService::class)->initiatorMayViewAllImpersonationSessions($viewer)) {
            return;
        }
        if ((int) $session->initiator_user_id !== (int) $viewer->id) {
            abort(403, 'You can only review your own support sessions.');
        }
    }

    public function enter(): Response
    {
        $user = $this->authorizeInternalSupportStarter();
        $impersonation = app(ImpersonationService::class);

        return Inertia::render('Admin/Impersonation/Enter', [
            'can_start_full' => $impersonation->initiatorMayUseFullMode($user),
            // Cap at 500 for now; replace with searchable company API when tenant count grows.
            'company_options' => Tenant::query()->orderBy('name')->limit(500)->get(['id', 'name', 'slug'])->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
            ]),
        ]);
    }

    /**
     * Open tenant-scoped support tickets for autocomplete on the "Start support session" form.
     * Only returned when the company has in-app tickets (Jackpot {@see Ticket} rows); external systems stay free-text.
     */
    public function openTicketsForCompany(Tenant $tenant): JsonResponse
    {
        $this->authorizeInternalSupportStarter();

        $openStatuses = [
            TicketStatus::OPEN,
            TicketStatus::WAITING_ON_USER,
            TicketStatus::WAITING_ON_SUPPORT,
            TicketStatus::IN_PROGRESS,
            TicketStatus::BLOCKED,
        ];

        $tickets = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', [TicketType::TENANT, TicketType::TENANT_INTERNAL])
            ->whereIn('status', $openStatuses)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'ticket_number', 'status']);

        return response()->json([
            'tickets' => $tickets->map(fn (Ticket $t) => [
                'id' => $t->id,
                'ticket_number' => $t->ticket_number,
                'status' => $t->status->value,
                'status_label' => ucfirst(str_replace('_', ' ', $t->status->value)),
            ]),
        ]);
    }

    public function start(Request $request, ImpersonationService $impersonation): RedirectResponse
    {
        $admin = $this->authorizeInternalSupportStarter();

        $validated = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'tenant_id' => ['required', 'integer', 'exists:tenants,id'],
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'mode' => ['required', 'string', Rule::in([ImpersonationMode::ReadOnly->value, ImpersonationMode::Full->value])],
            'reason' => ['required', 'string', 'min:3', 'max:5000'],
            'ticket_id' => ['nullable', 'string', 'max:128'],
        ]);

        $target = User::query()->findOrFail((int) $validated['target_user_id']);

        if ($target->isSuspended()) {
            throw ValidationException::withMessages([
                'target_user_id' => 'That account is suspended and cannot be impersonated.',
            ]);
        }

        $mode = ImpersonationMode::from($validated['mode']);

        $ticketId = isset($validated['ticket_id']) ? trim((string) $validated['ticket_id']) : '';
        $ticketId = $ticketId === '' ? null : $ticketId;

        $impersonation->startSession(
            $admin,
            $target,
            $mode,
            $validated['reason'],
            $request,
            (int) $validated['tenant_id'],
            (int) $validated['brand_id'],
            $ticketId,
        );

        return redirect()->route('app');
    }

    public function index(Request $request, ImpersonationService $impersonation): Response
    {
        $user = $this->authorizeInternalSupportStarter();
        $viewAll = $impersonation->initiatorMayViewAllImpersonationSessions($user);
        $scopedInitiatorId = $viewAll ? null : (int) $user->id;

        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        $scopedQuery = function () use ($scopedInitiatorId) {
            $q = ImpersonationSession::query();
            if ($scopedInitiatorId !== null) {
                $q->where('initiator_user_id', $scopedInitiatorId);
            }

            return $q;
        };

        $activeCount = $scopedQuery()
            ->whereNull('ended_at')
            ->where('expires_at', '>', $now)
            ->count();

        $readOnly30d = $scopedQuery()
            ->where('mode', ImpersonationMode::ReadOnly->value)
            ->where('started_at', '>=', $thirtyDaysAgo)
            ->count();

        $full30d = $scopedQuery()
            ->where('mode', ImpersonationMode::Full->value)
            ->where('started_at', '>=', $thirtyDaysAgo)
            ->count();

        $closed30d = $scopedQuery()
            ->whereNotNull('ended_at')
            ->where('ended_at', '>=', $thirtyDaysAgo)
            ->count();

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:active,ended,expired,all'],
            'mode' => ['nullable', 'string', 'in:read_only,full,assisted,all'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'initiator_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'target_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:500'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $validated['status'] ?? 'all';
        $mode = $validated['mode'] ?? 'all';

        $query = ImpersonationSession::query()
            ->with([
                'initiator:id,first_name,last_name,email',
                'target:id,first_name,last_name,email',
                'tenant:id,name,slug',
            ])
            ->orderByDesc('started_at');

        if ($scopedInitiatorId !== null) {
            $query->where('initiator_user_id', $scopedInitiatorId);
        }

        if ($mode !== 'all') {
            $query->where('mode', $mode);
        }

        if (! empty($validated['tenant_id'])) {
            $query->where('tenant_id', (int) $validated['tenant_id']);
        }
        if ($viewAll && ! empty($validated['initiator_user_id'])) {
            $query->where('initiator_user_id', (int) $validated['initiator_user_id']);
        }
        if (! empty($validated['target_user_id'])) {
            $query->where('target_user_id', (int) $validated['target_user_id']);
        }
        if (! empty($validated['date_from'])) {
            $query->where('started_at', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->where('started_at', '<=', $validated['date_to'].' 23:59:59');
        }
        if (! empty($validated['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where('reason', 'like', $term)
                    ->orWhere('ticket_id', 'like', $term);
            });
        }

        if ($status === 'active') {
            $query->whereNull('ended_at')->where('expires_at', '>', $now);
        } elseif ($status === 'ended') {
            $query->whereNotNull('ended_at')->whereColumn('ended_at', '<', 'expires_at');
        } elseif ($status === 'expired') {
            $query->where(function ($q) use ($now) {
                $q->whereNull('ended_at')->where('expires_at', '<=', $now)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('ended_at')->whereColumn('ended_at', '>=', 'expires_at');
                    });
            });
        }

        $paginator = $query->paginate(25)->withQueryString();

        $rows = collect($paginator->items())
            ->map(fn (ImpersonationSession $s) => $this->sessionToListPayload($s))
            ->values()
            ->all();

        return Inertia::render('Admin/Impersonation/Index', [
            'capabilities' => [
                'view_all_sessions' => $viewAll,
                'force_end_sessions' => $impersonation->initiatorMayForceEndImpersonationSession($user),
            ],
            'stats' => [
                'active' => $activeCount,
                'read_only_30d' => $readOnly30d,
                'full_30d' => $full30d,
                'closed_30d' => $closed30d,
            ],
            'sessions' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'status' => $status,
                'mode' => $mode,
                'tenant_id' => $validated['tenant_id'] ?? null,
                'initiator_user_id' => $validated['initiator_user_id'] ?? null,
                'target_user_id' => $validated['target_user_id'] ?? null,
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'search' => $validated['search'] ?? null,
            ],
            // Same 500 cap as enter screen — searchable selector TBD.
            'tenant_options' => Tenant::query()->orderBy('name')->limit(500)->get(['id', 'name', 'slug'])->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
            ]),
        ]);
    }

    public function show(ImpersonationSession $impersonation_session): Response
    {
        $user = $this->authorizeInternalSupportStarter();
        $this->assertMayViewSession($user, $impersonation_session);

        $session = $impersonation_session->load([
            'initiator:id,first_name,last_name,email',
            'target:id,first_name,last_name,email',
            'tenant:id,name,slug',
            'audits' => fn ($q) => $q->orderBy('created_at'),
        ]);

        $audits = $session->audits;
        $timeline = $audits->filter(fn (ImpersonationAudit $a) => $a->event !== ImpersonationAudit::EVENT_REQUEST)->values();
        $requestAudits = $audits->filter(fn (ImpersonationAudit $a) => $a->event === ImpersonationAudit::EVENT_REQUEST)->values();

        $impersonation = app(ImpersonationService::class);

        return Inertia::render('Admin/Impersonation/Show', [
            'session' => $this->sessionToDetailPayload($session),
            'audit_timeline' => $timeline->map(fn (ImpersonationAudit $a) => $this->auditToPayload($a))->all(),
            'audit_requests' => $requestAudits->map(fn (ImpersonationAudit $a) => $this->auditToPayload($a))->all(),
            'capabilities' => [
                'force_end_sessions' => $impersonation->initiatorMayForceEndImpersonationSession($user),
            ],
        ]);
    }

    public function end(Request $request, ImpersonationSession $impersonation_session): RedirectResponse
    {
        $admin = $this->authorizeForceEndSession();

        $session = $impersonation_session;

        if ($session->ended_at !== null) {
            return redirect()
                ->route('admin.impersonation.show', $session)
                ->with('warning', 'That session is already ended.');
        }

        DB::transaction(function () use ($session, $admin, $request) {
            $session->forceFill(['ended_at' => now()])->save();

            ImpersonationAudit::query()->create([
                'impersonation_session_id' => $session->id,
                'event' => ImpersonationAudit::EVENT_ENDED,
                'http_method' => $request->method(),
                'path' => mb_substr((string) $request->path(), 0, 2000),
                'route_name' => $request->route()?->getName(),
                'initiator_user_id' => $session->initiator_user_id,
                'acting_user_id' => $session->target_user_id,
                'meta' => [
                    'cause' => 'admin_forced',
                    'admin_user_id' => $admin->id,
                ],
            ]);
        });

        return redirect()
            ->route('admin.impersonation.show', $session)
            ->with('success', 'Session was force-ended. The user’s next request will clear impersonation state.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionToListPayload(ImpersonationSession $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status(),
            'mode' => $s->mode->value,
            'mode_label' => $s->modeLabel(),
            'tenant' => $s->tenant ? [
                'id' => $s->tenant->id,
                'name' => $s->tenant->name,
                'slug' => $s->tenant->slug,
            ] : null,
            'target' => $s->target ? [
                'id' => $s->target->id,
                'name' => $s->target->name,
                'email' => $s->target->email,
            ] : null,
            'initiator' => $s->initiator ? [
                'id' => $s->initiator->id,
                'name' => $s->initiator->name,
                'email' => $s->initiator->email,
            ] : null,
            'started_at' => $s->started_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'ticket_id' => $s->ticket_id,
            'reason_preview' => $this->reasonPreview($s->reason ?? '', $s->ticket_id),
            'is_active' => $s->isActive(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionToDetailPayload(ImpersonationSession $s): array
    {
        $payload = $this->sessionToListPayload($s);
        $payload['reason'] = (string) ($s->reason ?? '');
        $payload['ticket_id'] = $s->ticket_id;
        $payload['ip_address'] = $s->ip_address;
        $payload['user_agent'] = $s->user_agent;
        $payload['remaining_seconds'] = $s->remainingSeconds();
        $payload['duration_seconds'] = $s->durationSeconds();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function auditToPayload(ImpersonationAudit $a): array
    {
        $meta = $a->meta ?? [];

        return [
            'id' => $a->id,
            'event' => $a->event,
            'created_at' => $a->created_at?->toIso8601String(),
            'http_method' => $a->http_method,
            'path' => $a->path,
            'route_name' => $a->route_name,
            'initiator_user_id' => $a->initiator_user_id,
            'acting_user_id' => $a->acting_user_id,
            'http_status' => $meta['http_status'] ?? null,
            'meta_cause' => $meta['cause'] ?? null,
            'meta_admin_user_id' => $meta['admin_user_id'] ?? null,
        ];
    }

    protected function reasonPreview(string $reason, ?string $ticketId = null): string
    {
        $prefix = $ticketId !== null && trim($ticketId) !== '' ? '['.trim($ticketId).'] ' : '';
        $t = trim($reason);
        if ($t === '' && $prefix === '') {
            return '—';
        }
        $combined = $prefix.$t;
        if (mb_strlen($combined) <= 120) {
            return $combined;
        }

        return mb_substr($combined, 0, 117).'…';
    }
}
