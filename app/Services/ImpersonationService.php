<?php

namespace App\Services;

use App\Enums\ImpersonationMode;
use App\Models\Brand;
use App\Models\ImpersonationAudit;
use App\Models\ImpersonationSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ImpersonationService
{
    public const SESSION_KEY = 'impersonation_session_id';

    public const SESSION_INITIATOR_KEY = 'impersonation_initiator_user_id';

    protected ?ImpersonationSession $resolvedSession = null;

    protected bool $resolved = false;

    protected ?User $targetUser = null;

    protected ?User $initiatorUser = null;

    /**
     * Clear memoized resolution so each HTTP request re-reads the session.
     * The service is a singleton; PHPUnit and long-lived workers otherwise keep stale flags across requests.
     */
    public function resetPerRequestResolution(): void
    {
        $this->resolved = false;
        $this->resolvedSession = null;
        $this->targetUser = null;
        $this->initiatorUser = null;
    }

    /**
     * Load session from storage and validate (no Auth mutation).
     *
     * @param  User|null  $sessionUser  Authenticated user from the session guard (initiator).
     */
    public function primeFromSession(?User $sessionUser): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;
        $this->resolvedSession = null;
        $this->targetUser = null;
        $this->initiatorUser = null;

        if (! $sessionUser) {
            return;
        }

        $id = session(self::SESSION_KEY);
        if (! $id) {
            return;
        }

        $row = ImpersonationSession::query()->find((int) $id);
        if (! $row || $row->ended_at !== null) {
            $this->forgetSessionKeys();

            return;
        }

        if ($row->expires_at->isPast()) {
            $this->terminateExpiredSession($row, request());

            return;
        }

        $initiatorId = (int) session(self::SESSION_INITIATOR_KEY);
        if ($initiatorId <= 0 || (int) $row->initiator_user_id !== $initiatorId) {
            $this->forgetSessionKeys();

            return;
        }

        $uid = (int) $sessionUser->id;
        if (! in_array($uid, [(int) $row->initiator_user_id, (int) $row->target_user_id], true)) {
            $this->forgetSessionKeys();

            return;
        }

        $tenantId = session('tenant_id');
        if ($tenantId === null || (int) $row->tenant_id !== (int) $tenantId) {
            $this->forgetSessionKeys();

            return;
        }

        $target = User::query()->find($row->target_user_id);
        if (! $target || ! $target->belongsToTenant($row->tenant_id)) {
            $this->forgetSessionKeys();

            return;
        }

        $this->resolvedSession = $row;
        $this->targetUser = $target;
        $this->initiatorUser = User::query()->find($row->initiator_user_id);
    }

    public function applyAuthSwap(Request $request): void
    {
        $sessionUser = Auth::user();
        $this->primeFromSession($sessionUser instanceof User ? $sessionUser : null);

        if (! $this->resolvedSession || ! $this->targetUser) {
            return;
        }

        Auth::guard('web')->setUser($this->targetUser);
        $request->setUserResolver(fn () => $this->targetUser);

        app()->instance('impersonation.session', $this->resolvedSession);
        if ($this->initiatorUser) {
            app()->instance('impersonation.initiator', $this->initiatorUser);
        }
    }

    /**
     * Internal Command Center support impersonation only. The initiator does not need tenant membership.
     *
     * @throws ValidationException
     */
    public function startSession(
        User $initiator,
        User $targetUser,
        ImpersonationMode $mode,
        string $reason,
        Request $request,
        int $workspaceTenantId,
        int $workspaceBrandId,
        ?string $ticketId = null,
    ): ImpersonationSession {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required to start impersonation.']);
        }

        $ticketId = $ticketId !== null ? trim($ticketId) : '';
        $ticketId = $ticketId === '' ? null : $ticketId;

        if (session(self::SESSION_KEY)) {
            throw ValidationException::withMessages(['impersonation' => 'You are already impersonating another user. End that session first.']);
        }

        if (! $this->initiatorMayStartInternalImpersonation($initiator)) {
            abort(403, 'Only internal support staff can start a support session.');
        }

        if ($mode === ImpersonationMode::Assisted) {
            throw ValidationException::withMessages(['mode' => 'Assisted impersonation is not available yet.']);
        }

        if ($mode === ImpersonationMode::Full && ! $this->initiatorMayUseFullMode($initiator)) {
            throw ValidationException::withMessages(['mode' => 'Full sessions are limited to site administrators.']);
        }

        $tenant = Tenant::query()->find($workspaceTenantId);
        if (! $tenant) {
            throw ValidationException::withMessages(['tenant_id' => 'Invalid company.']);
        }

        $brand = Brand::query()
            ->where('id', $workspaceBrandId)
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $brand) {
            throw ValidationException::withMessages(['brand_id' => 'That brand does not belong to the selected company.']);
        }

        if ((int) $initiator->id === (int) $targetUser->id) {
            throw ValidationException::withMessages(['target_user_id' => 'You cannot impersonate yourself.']);
        }

        if (! $targetUser->belongsToTenant($tenant->id)) {
            throw ValidationException::withMessages(['target_user_id' => 'That user is not a member of this company.']);
        }

        if (! $this->targetUserMayAccessBrand($targetUser, $tenant, $brand)) {
            throw ValidationException::withMessages(['brand_id' => 'That user cannot access the selected brand in this company.']);
        }

        session([
            'tenant_id' => (int) $tenant->id,
            'brand_id' => (int) $brand->id,
        ]);

        $ttl = max(1, (int) config('impersonation.ttl_minutes', 30));
        $now = now();

        $auditMeta = array_filter([
            'mode' => $mode->value,
            'reason' => $reason,
            'ticket_id' => $ticketId,
            'entry' => 'internal_support',
        ], fn ($v) => $v !== null && $v !== '');

        $session = DB::transaction(function () use ($initiator, $targetUser, $tenant, $mode, $reason, $ticketId, $request, $now, $ttl, $auditMeta): ImpersonationSession {
            $row = ImpersonationSession::query()->create([
                'initiator_user_id' => $initiator->id,
                'target_user_id' => $targetUser->id,
                'tenant_id' => $tenant->id,
                'mode' => $mode,
                'reason' => $reason,
                'ticket_id' => $ticketId,
                'started_at' => $now,
                'expires_at' => $now->copy()->addMinutes($ttl),
                'ended_at' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            ImpersonationAudit::query()->create([
                'impersonation_session_id' => $row->id,
                'event' => ImpersonationAudit::EVENT_STARTED,
                'http_method' => $request->method(),
                'path' => (string) $request->path(),
                'route_name' => $request->route()?->getName(),
                'initiator_user_id' => $initiator->id,
                'acting_user_id' => $targetUser->id,
                'meta' => $auditMeta,
            ]);

            return $row;
        });

        session([
            self::SESSION_KEY => $session->id,
            self::SESSION_INITIATOR_KEY => $initiator->id,
        ]);

        $this->resolved = false;
        $this->primeFromSession($initiator);

        return $session;
    }

    /**
     * Target can use this brand within the tenant (tenant admin/owner or active brand membership).
     */
    protected function targetUserMayAccessBrand(User $targetUser, Tenant $tenant, Brand $brand): bool
    {
        $tenantRole = $targetUser->tenants()
            ->where('tenants.id', $tenant->id)
            ->first()?->pivot?->role;
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);

        if ($isTenantOwnerOrAdmin) {
            return true;
        }

        return $brand->users()
            ->where('users.id', $targetUser->id)
            ->wherePivotNull('removed_at')
            ->exists();
    }

    public function endSession(?Request $request = null): void
    {
        $request ??= request();
        $sessionUser = Auth::user();

        $id = session(self::SESSION_KEY);
        if (! $id) {
            return;
        }

        $row = ImpersonationSession::query()->find((int) $id);
        if (! $row) {
            $this->forgetSessionKeys();

            return;
        }

        if ($row->ended_at !== null) {
            $this->forgetSessionKeys();

            return;
        }

        if ($sessionUser instanceof User
            && (int) $sessionUser->id !== (int) $row->target_user_id
            && (int) $sessionUser->id !== (int) $row->initiator_user_id) {
            return;
        }

        $this->finalizeEndedSession($row, $request);
        $this->forgetSessionKeys();

        if ($row->initiator) {
            Auth::guard('web')->setUser($row->initiator);
            $request->setUserResolver(fn () => $row->initiator);
        }

        $this->resolved = false;
        $this->resolvedSession = null;
        $this->targetUser = null;
        $this->initiatorUser = null;
    }

    public function currentSession(): ?ImpersonationSession
    {
        $u = Auth::user();
        $this->primeFromSession($u instanceof User ? $u : null);

        return $this->resolvedSession;
    }

    public function isActive(): bool
    {
        return $this->currentSession() !== null;
    }

    public function isReadOnly(): bool
    {
        $s = $this->currentSession();

        return $s && ($s->mode === ImpersonationMode::ReadOnly || $s->mode === ImpersonationMode::Assisted);
    }

    public function isFullAccess(): bool
    {
        $s = $this->currentSession();

        return $s && $s->mode === ImpersonationMode::Full;
    }

    public function actingUser(): ?User
    {
        $sessionUser = Auth::user();
        $this->primeFromSession($sessionUser instanceof User ? $sessionUser : null);

        if ($this->targetUser) {
            return $this->targetUser;
        }

        return $sessionUser instanceof User ? $sessionUser : null;
    }

    public function initiatorUser(): ?User
    {
        $sessionUser = Auth::user();
        $this->primeFromSession($sessionUser instanceof User ? $sessionUser : null);

        if ($this->resolvedSession) {
            return $this->resolvedSession->initiator;
        }

        return $sessionUser instanceof User ? $sessionUser : null;
    }

    public function logRequestIfActive(Request $request): void
    {
        $id = session(self::SESSION_KEY);
        if (! $id) {
            return;
        }

        $row = ImpersonationSession::query()->find((int) $id);
        if (! $row || $row->ended_at !== null || $row->expires_at->isPast()) {
            return;
        }

        ImpersonationAudit::query()->create([
            'impersonation_session_id' => $row->id,
            'event' => ImpersonationAudit::EVENT_REQUEST,
            'http_method' => $request->method(),
            'path' => mb_substr((string) $request->path(), 0, 2000),
            'route_name' => $request->route()?->getName(),
            'initiator_user_id' => $row->initiator_user_id,
            'acting_user_id' => $row->target_user_id,
            'meta' => null,
        ]);
    }

    /**
     * When the DB session is past expiry, end it and restore the initiator to the guard.
     * Returns a redirect response for HTTP middleware when expiry is handled.
     */
    public function enforceExpiration(Request $request): ?Response
    {
        $id = session(self::SESSION_KEY);
        if (! $id) {
            return null;
        }

        $row = ImpersonationSession::query()->find((int) $id);
        if (! $row || $row->ended_at !== null) {
            $this->forgetSessionKeys();

            return null;
        }

        if ($row->expires_at->isFuture()) {
            return null;
        }

        $this->terminateExpiredSession($row, $request);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'View-as session has expired.'], 403);
        }

        return redirect()->route('app')->with('warning', 'Your view-as session has expired.');
    }

    public function terminateExpiredSession(ImpersonationSession $row, Request $request): void
    {
        $request ??= request();
        $row->forceFill(['ended_at' => now()])->save();
        ImpersonationAudit::query()->create([
            'impersonation_session_id' => $row->id,
            'event' => ImpersonationAudit::EVENT_ENDED,
            'http_method' => $request->method(),
            'path' => (string) $request->path(),
            'route_name' => $request->route()?->getName(),
            'initiator_user_id' => $row->initiator_user_id,
            'acting_user_id' => $row->target_user_id,
            'meta' => ['cause' => 'expired'],
        ]);
        $this->forgetSessionKeys();
        $this->resolved = false;
        $this->resolvedSession = null;
        $this->targetUser = null;
        $this->initiatorUser = null;
        if ($row->initiator) {
            Auth::guard('web')->setUser($row->initiator);
            $request->setUserResolver(fn () => $row->initiator);
        }
    }

    protected function finalizeEndedSession(ImpersonationSession $row, Request $request): void
    {
        if ($row->ended_at !== null) {
            return;
        }

        $row->forceFill(['ended_at' => now()])->save();

        ImpersonationAudit::query()->create([
            'impersonation_session_id' => $row->id,
            'event' => ImpersonationAudit::EVENT_ENDED,
            'http_method' => $request->method(),
            'path' => (string) $request->path(),
            'route_name' => $request->route()?->getName(),
            'initiator_user_id' => $row->initiator_user_id,
            'acting_user_id' => $row->target_user_id,
            'meta' => ['cause' => 'manual_end'],
        ]);
    }

    protected function forgetSessionKeys(): void
    {
        session()->forget([self::SESSION_KEY, self::SESSION_INITIATOR_KEY]);
    }

    public function initiatorMayUseFullMode(User $initiator): bool
    {
        if ((int) $initiator->id === 1) {
            return true;
        }

        $siteRoles = $initiator->getSiteRoles();
        $allowed = RoleRegistry::siteRolesThatMayUseFullImpersonationMode();

        return count(array_intersect($siteRoles, $allowed)) > 0;
    }

    /**
     * See {@see RoleRegistry::siteRolesThatMayStartInternalImpersonation()} (excludes site_engineering until assisted mode).
     */
    public function initiatorMayStartInternalImpersonation(User $user): bool
    {
        if ((int) $user->id === 1) {
            return true;
        }

        $siteRoles = $user->getSiteRoles();
        $allowed = RoleRegistry::siteRolesThatMayStartInternalImpersonation();

        return count(array_intersect($siteRoles, $allowed)) > 0;
    }

    public function initiatorMayForceEndImpersonationSession(User $user): bool
    {
        return $this->initiatorMayUseFullMode($user);
    }

    public function initiatorMayViewAllImpersonationSessions(User $user): bool
    {
        return $this->initiatorMayUseFullMode($user);
    }
}
