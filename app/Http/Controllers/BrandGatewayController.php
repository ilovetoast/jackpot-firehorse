<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BrandGateway\BrandContextResolver;
use App\Services\BrandGateway\BrandThemeBuilder;
use App\Support\GatewayIntendedUrl;
use App\Support\GatewayResumeCookie;
use App\Services\Prostaff\ApplyProstaffAfterBrandInvitationAccept;
use App\Mail\EmailVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Public gateway (login, company/brand pickers, cinematic enter).
 * Product defaults and deferred brand-level controls: docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md
 */
class BrandGatewayController extends Controller
{
    public function __construct(
        protected BrandContextResolver $contextResolver,
        protected BrandThemeBuilder $themeBuilder
    ) {}

    /**
     * Invitation emails are stored as-entered; {@see User} emails may differ only by case.
     * Pending-invite UIs (e.g. no-companies) match with LOWER(); accept flow must do the same.
     */
    protected function invitationEmailMatchesUser(User $user, TenantInvitation|BrandInvitation $invitation): bool
    {
        return strcasecmp((string) $user->email, (string) $invitation->email) === 0;
    }

    protected function findUserByInvitationEmail(string $invitationEmail): ?User
    {
        $normalized = strtolower(trim($invitationEmail));

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalized])
            ->first();
    }

    /**
     * Main gateway entry point.
     * Works for authenticated users, guests, and invite flows.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->query('switch')) {
            GatewayResumeCookie::queueForget();
        }

        $context = $this->contextResolver->resolve($request);

        if (Auth::check()
            && ($context['gateway_resume_active'] ?? false)
            && ($context['tenant']['id'] ?? null)
            && ($context['brand']['id'] ?? null)
            && ! in_array($request->query('mode'), ['login', 'register'], true)) {
            session([
                'tenant_id' => $context['tenant']['id'],
                'brand_id' => $context['brand']['id'],
            ]);
        }

        if (Auth::check() && count($context['available_companies']) === 0) {
            session()->forget(['tenant_id', 'brand_id', 'collection_id']);

            return redirect()->route('errors.no-companies');
        }

        if (Auth::check()
            && ! $request->query('switch')
            && ($context['tenant']['id'] ?? null)
            && count($context['available_brands']) === 0) {
            $landing = $this->redirectExternalCollectionUserToLanding(Auth::user(), (int) $context['tenant']['id']);
            if ($landing) {
                return $landing;
            }
        }

        $theme = $this->buildThemeFromContext($context);
        $mode = $this->determineMode($request, $context);

        if ($mode === 'enter') {
            $theme['portal']['entry']['style'] = 'cinematic';
        }

        // Product default: cinematic enter is automatic whenever mode is enter (portal_settings.entry.auto_enter is deferred — see docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md).
        $autoEnter = $mode === 'enter'
            && ! $request->query('switch')
            && ! in_array($request->query('mode'), ['login', 'register'], true);

        if (Auth::check()
            && ($context['tenant_member_without_brands'] ?? false)
            && $mode === 'brand_select') {
            $this->trackGatewayEvent(EventType::GATEWAY_BRAND_LIST_EMPTY, $context, [
                'tenant_id' => $context['tenant']['id'] ?? null,
                'tenant_name' => $context['tenant']['name'] ?? null,
                'user_id' => Auth::id(),
                'mode' => $mode,
            ]);
        }

        $this->trackGatewayEvent(
            $autoEnter ? EventType::GATEWAY_AUTO_ENTER : EventType::GATEWAY_VIEWED,
            $context,
            ['mode' => $mode, 'auto_enter' => $autoEnter]
        );

        return Inertia::render('Gateway/Index', [
            'context' => $context,
            'theme' => $theme,
            'mode' => $mode,
            'auto_enter' => $autoEnter,
        ]);
    }

    /**
     * Gateway with invite token pre-resolved.
     */
    public function invite(Request $request, string $token): Response
    {
        $context = $this->contextResolver->resolve($request, $token);
        $theme = $this->buildThemeFromContext($context);

        if (! $context['invitation']) {
            return Inertia::render('Gateway/Index', [
                'context' => $context,
                'theme' => $theme,
                'mode' => 'login',
                'flash_error' => 'This invitation link is invalid or has already been used.',
            ]);
        }

        $email = $context['invitation']['email'] ?? null;
        $userExists = $email && User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim((string) $email))])
            ->exists();

        if (Auth::check()) {
            $mode = 'invite_accept';
        } elseif ($userExists) {
            $mode = 'invite_login';
        } else {
            $mode = 'invite_register';
        }

        return Inertia::render('Gateway/Index', [
            'context' => $context,
            'theme' => $theme,
            'mode' => $mode,
            'invite_token' => $token,
        ]);
    }

    /**
     * Handle login via the gateway.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
            'invite_token' => ['nullable', 'string', 'max:128'],
        ]);

        if (! Auth::attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password']],
            $request->boolean('remember')
        )) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        if (! empty($credentials['invite_token'])) {
            $invitation = $this->findPendingInvitation($credentials['invite_token']);
            if (! $invitation) {
                Auth::logout();
                throw ValidationException::withMessages([
                    'email' => 'This invitation link is invalid or has already been used.',
                ]);
            }
            if (! $this->invitationEmailMatchesUser($user, $invitation)) {
                Auth::logout();
                throw ValidationException::withMessages([
                    'email' => 'This invitation is for a different email address.',
                ]);
            }

            return redirect()->route('gateway.invite', ['token' => $credentials['invite_token']]);
        }

        if ($user->isSuspended()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended. Please contact support.',
            ]);
        }

        $intendedUrl = session()->get('url.intended');
        if ($intendedUrl && static::isCollectionInviteAcceptUrl($intendedUrl)) {
            session()->forget('url.intended');

            return redirect()->to($intendedUrl);
        }

        $tenants = $user->tenants()->with('defaultBrand')->get();

        if ($tenants->isEmpty()) {
            return redirect()->route('errors.no-companies');
        }

        $context = $this->contextResolver->resolve($request);
        $tenantId = $context['tenant']['id'] ?? null;
        $tenant = $tenantId ? $tenants->firstWhere('id', $tenantId) : null;

        if (! $tenant) {
            $tenant = $tenants->first();
        }

        if ($tenants->count() > 1 && ! $tenantId) {
            return redirect()->route('gateway');
        }

        $collectionLanding = $this->redirectExternalCollectionUserToLanding($user, (int) $tenant->id);
        if ($collectionLanding) {
            $this->trackGatewayEvent(EventType::GATEWAY_LOGIN, [
                'tenant' => ['id' => $tenant->id],
                'brand' => null,
                'is_authenticated' => true,
            ]);

            return $collectionLanding;
        }

        $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();

        if (! $defaultBrand) {
            abort(500, 'Tenant must have at least one brand');
        }

        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand->id,
        ]);

        $this->trackGatewayEvent(EventType::GATEWAY_LOGIN, [
            'tenant' => ['id' => $tenant->id],
            'brand' => ['id' => $defaultBrand->id],
            'is_authenticated' => true,
        ]);

        return $this->redirectToGatewayIntended();
    }

    /**
     * Handle registration via the gateway.
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
            'company_name' => 'required|string|max:255',
        ]);

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
        ]);

        $slug = \Illuminate\Support\Str::slug($validated['company_name']);
        $originalSlug = $slug;
        $counter = 1;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter++;
        }

        $tenant = Tenant::create([
            'name' => $validated['company_name'],
            'slug' => $slug,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Tenant::created boot hook already creates a default brand — reuse it
        // instead of creating a duplicate.
        $brand = $tenant->brands()->where('is_default', true)->first();
        $user->brands()->syncWithoutDetaching([
            $brand->id => ['role' => 'brand_manager'],
        ]);

        Auth::login($user);

        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
        Mail::to($user->email)->send(new EmailVerification($verifyUrl));

        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
        ]);

        $this->trackGatewayEvent(EventType::GATEWAY_REGISTER, [
            'tenant' => ['id' => $tenant->id],
            'brand' => ['id' => $brand->id],
            'is_authenticated' => true,
        ]);

        return $this->redirectToGatewayIntended();
    }

    /**
     * Accept an invite via the gateway (authenticated user).
     */
    public function acceptInvite(Request $request, string $token)
    {
        $invitation = $this->findPendingInvitation($token);

        if (! $invitation) {
            return redirect()->route('gateway')->with('error', 'Invalid or expired invitation.');
        }

        $user = Auth::user();

        if (! $this->invitationEmailMatchesUser($user, $invitation)) {
            return redirect()->route('gateway')->with('error', 'This invitation is for a different email address.');
        }

        if ($invitation instanceof TenantInvitation) {
            return $this->acceptTenantInvitation($invitation, $user, $token);
        }

        return $this->acceptBrandInvitation($invitation, $user, $token);
    }

    /**
     * Complete invite registration (new user) via the gateway.
     */
    public function completeInviteRegistration(Request $request, string $token)
    {
        $invitation = $this->findPendingInvitation($token);

        if (! $invitation) {
            throw ValidationException::withMessages([
                'invitation' => 'Invalid or expired invitation link.',
            ]);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], [
            'first_name.required' => 'Please enter your first name.',
            'last_name.required' => 'Please enter your last name.',
            'password.required' => 'Please enter a password.',
            'password.confirmed' => 'The password confirmation does not match.',
        ]);

        if ($invitation instanceof TenantInvitation) {
            return $this->completeTenantInviteRegistration($invitation, $validated);
        }

        return $this->completeBrandInviteRegistration($invitation, $validated);
    }

    protected function findPendingInvitation(string $token): TenantInvitation|BrandInvitation|null
    {
        $tenantInv = TenantInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with(['tenant', 'inviter'])
            ->first();

        if ($tenantInv) {
            return $tenantInv;
        }

        return BrandInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with(['brand.tenant', 'inviter'])
            ->first();
    }

    protected function acceptTenantInvitation(TenantInvitation $invitation, User $user, string $token): \Illuminate\Http\RedirectResponse
    {
        $tenant = $invitation->tenant;
        $invitation->update(['accepted_at' => now()]);

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => $invitation->role ?? 'member']);
        }

        if ($invitation->brand_assignments) {
            foreach ($invitation->brand_assignments as $assignment) {
                $brandId = $assignment['brand_id'] ?? null;
                $role = $assignment['role'] ?? 'member';
                if ($brandId && ! $user->brands()->where('brands.id', $brandId)->exists()) {
                    $user->brands()->attach($brandId, ['role' => $role]);
                }
            }
        }

        $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand?->id,
        ]);

        $this->trackGatewayEvent(EventType::GATEWAY_INVITE_ACCEPTED, [
            'tenant' => ['id' => $tenant->id],
            'brand' => ['id' => $defaultBrand?->id],
            'is_authenticated' => true,
        ], ['invite_token' => substr($token, 0, 8).'...']);

        return redirect()->route('overview')->with('success', "Welcome to {$tenant->name}!");
    }

    protected function acceptBrandInvitation(BrandInvitation $invitation, User $user, string $token): \Illuminate\Http\RedirectResponse
    {
        $brand = $invitation->brand;
        if (! $brand) {
            return redirect()->route('gateway')->with('error', 'Invalid or expired invitation.');
        }

        $tenant = $brand->tenant;
        $invitation->update(['accepted_at' => now()]);

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => 'member']);
        }

        $this->applyBrandInviteRole($user, $brand, $invitation->role ?? 'viewer');

        $this->applyProstaffFromBrandInvitationMetadata($user, $invitation, $brand);

        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
        ]);

        $this->trackGatewayEvent(EventType::GATEWAY_INVITE_ACCEPTED, [
            'tenant' => ['id' => $tenant->id],
            'brand' => ['id' => $brand->id],
            'is_authenticated' => true,
        ], ['invite_token' => substr($token, 0, 8).'...']);

        return redirect()->route('overview')->with('success', "Welcome to {$brand->name}!");
    }

    protected function applyProstaffFromBrandInvitationMetadata(User $user, BrandInvitation $invitation, Brand $brand): void
    {
        app(ApplyProstaffAfterBrandInvitationAccept::class)->apply($user, $invitation, $brand);
    }

    /**
     * @param  array{first_name: string, last_name: string, password: string}  $validated
     */
    protected function completeTenantInviteRegistration(TenantInvitation $invitation, array $validated): \Illuminate\Http\RedirectResponse
    {
        $user = $this->findUserByInvitationEmail((string) $invitation->email);

        if (! $user) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $invitation->email,
                'password' => bcrypt($validated['password']),
            ]);
        } else {
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'password' => bcrypt($validated['password']),
            ]);
        }

        $tenant = $invitation->tenant;
        $invitation->update(['accepted_at' => now()]);

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => $invitation->role ?? 'member']);
        }

        if ($invitation->brand_assignments) {
            foreach ($invitation->brand_assignments as $assignment) {
                $brandId = $assignment['brand_id'] ?? null;
                $role = $assignment['role'] ?? 'member';
                if ($brandId && ! $user->brands()->where('brands.id', $brandId)->exists()) {
                    $user->brands()->attach($brandId, ['role' => $role]);
                }
            }
        }

        Auth::login($user);

        $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand?->id,
        ]);

        return redirect()->route('overview')->with('success', "Welcome to {$tenant->name}!");
    }

    /**
     * @param  array{first_name: string, last_name: string, password: string}  $validated
     */
    protected function completeBrandInviteRegistration(BrandInvitation $invitation, array $validated): \Illuminate\Http\RedirectResponse
    {
        $brand = $invitation->brand;
        if (! $brand) {
            throw ValidationException::withMessages([
                'invitation' => 'Invalid or expired invitation link.',
            ]);
        }

        $tenant = $brand->tenant;

        $user = $this->findUserByInvitationEmail((string) $invitation->email);

        if (! $user) {
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $invitation->email,
                'password' => bcrypt($validated['password']),
            ]);
        } else {
            $user->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'password' => bcrypt($validated['password']),
            ]);
        }

        $invitation->update(['accepted_at' => now()]);

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            $user->tenants()->attach($tenant->id, ['role' => 'member']);
        }

        $this->applyBrandInviteRole($user, $brand, $invitation->role ?? 'viewer');

        $this->applyProstaffFromBrandInvitationMetadata($user, $invitation, $brand);

        Auth::login($user);

        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
        ]);

        return redirect()->route('overview')->with('success', "Welcome to {$brand->name}!");
    }

    protected function applyBrandInviteRole(User $user, Brand $brand, string $role): void
    {
        try {
            $user->setRoleForBrand($brand, $role);
        } catch (\InvalidArgumentException) {
            $user->setRoleForBrand($brand, 'viewer');
        }
    }

    /**
     * Handle company selection from the gateway.
     */
    public function selectCompany(Request $request)
    {
        $validated = $request->validate([
            'tenant_id' => 'required|integer|exists:tenants,id',
        ]);

        $user = Auth::user();
        $tenant = Tenant::findOrFail($validated['tenant_id']);

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        $collectionLanding = $this->redirectExternalCollectionUserToLanding($user, (int) $tenant->id);
        if ($collectionLanding) {
            $this->trackGatewayEvent(EventType::GATEWAY_SWITCH_USED, [
                'tenant' => ['id' => $tenant->id],
                'brand' => null,
                'is_authenticated' => true,
            ], ['switch_type' => 'company']);

            return $collectionLanding;
        }

        $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();

        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand?->id,
        ]);

        $this->trackGatewayEvent(EventType::GATEWAY_SWITCH_USED, [
            'tenant' => ['id' => $tenant->id],
            'brand' => ['id' => $defaultBrand?->id],
            'is_authenticated' => true,
        ], ['switch_type' => 'company']);

        $context = $this->contextResolver->resolve($request);

        if ($context['is_multi_brand']) {
            return redirect()->route('gateway', ['company' => $tenant->slug]);
        }

        return $this->redirectToGatewayIntended();
    }

    /**
     * Handle brand selection from the gateway.
     */
    public function selectBrand(Request $request)
    {
        $validated = $request->validate([
            'brand_id' => 'required|integer|exists:brands,id',
        ]);

        $user = Auth::user();
        $tenantId = session('tenant_id');

        if (! $tenantId) {
            return redirect()->route('gateway');
        }

        $tenant = Tenant::findOrFail($tenantId);
        $brand = Brand::where('id', $validated['brand_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $tenantRole = $user->getRoleForTenant($tenant);
        $isElevatedTenantUser = in_array($tenantRole, ['owner', 'admin', 'agency_admin'], true);
        if (! $isElevatedTenantUser && ! $user->hasActiveBrandUserAssignment($brand)) {
            abort(403, 'You do not have access to this brand.');
        }

        $planService = app(\App\Services\PlanService::class);

        if ($planService->isBrandDisabledByPlanLimit($brand, $tenant)) {
            $enabledBrand = $planService->findFirstEnabledBrand($tenant, $user);

            if ($enabledBrand) {
                session(['brand_id' => $enabledBrand->id]);
                session()->flash('warning', "The brand \"{$brand->name}\" is unavailable on your current plan. You've been redirected to \"{$enabledBrand->name}\".");

                return $this->redirectToGatewayIntended();
            }

            return redirect()->route('errors.brand-disabled');
        }

        session(['brand_id' => $brand->id]);

        $this->trackGatewayEvent(EventType::GATEWAY_ENTER_CLICKED, [
            'tenant' => ['id' => $tenantId],
            'brand' => ['id' => $brand->id],
            'is_authenticated' => true,
        ], ['switch_type' => 'brand']);

        return $this->redirectToGatewayIntended();
    }

    /**
     * Redirect to the URL the user originally requested, or the portal default destination.
     * Consumes the intended_url stored by EnsureGatewayEntry middleware.
     */
    protected function redirectToGatewayIntended(): RedirectResponse|SymfonyResponse
    {
        GatewayResumeCookie::queueFromSession();

        $intended = session()->pull('intended_url');

        if ($intended) {
            $path = parse_url($intended, PHP_URL_PATH) ?? '';
            if ($path !== '' && GatewayIntendedUrl::shouldDiscardPath($path)) {
                $intended = null;
            }
        }

        if (! $intended) {
            $intended = $this->resolveDefaultDestination();
        }

        // Inertia POST+redirect: following 302 with XHR can end on a non-Inertia response (e.g. empty body),
        // which triggers router "invalid" and surfaces as a misleading 204 in the global error modal.
        if (request()->header('X-Inertia')) {
            return Inertia::location($intended);
        }

        return redirect()->to($intended);
    }

    /**
     * Default landing after gateway entry. Always Overview (tasks); intended_url still wins in redirectToGatewayIntended.
     * portal_settings.entry.default_destination is deferred — see docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md.
     */
    protected function resolveDefaultDestination(): string
    {
        return '/app/overview';
    }

    /**
     * Build theme from the serialized context array.
     * Loads actual Eloquent models from context IDs so the theme builder
     * can access relationships (default brand, brand DNA, etc.).
     */
    protected function buildThemeFromContext(array $context): array
    {
        return $this->themeBuilder->buildFromGatewayContext($context);
    }

    /**
     * Fire a lightweight gateway analytics event.
     * Non-blocking: failures are silently swallowed.
     */
    protected function trackGatewayEvent(string $eventType, array $context, array $extra = []): void
    {
        try {
            $tenantId = $context['tenant']['id'] ?? session('tenant_id');
            $tenant = $tenantId ? Tenant::find($tenantId) : null;
            $brandId = $context['brand']['id'] ?? session('brand_id');
            $brand = $brandId ? Brand::find($brandId) : null;

            ActivityRecorder::record(
                tenant: $tenant,
                eventType: $eventType,
                subject: null,
                actor: Auth::user(),
                brand: $brand,
                metadata: array_merge([
                    'is_authenticated' => $context['is_authenticated'] ?? Auth::check(),
                    'theme_mode' => $context['tenant'] ? ($context['brand'] ? 'brand' : 'tenant') : 'default',
                ], $extra),
            );
        } catch (\Throwable) {
            // Gateway analytics are non-critical — never block the user flow
        }
    }

    /**
     * Collection-only external users: tenant membership + collection_user grant, no brand_user row.
     * Prefer session collection_id when it matches an accepted grant; otherwise the first grant in this tenant.
     */
    protected function redirectExternalCollectionUserToLanding(User $user, int $tenantId): ?RedirectResponse
    {
        if ($user->brands()->where('tenant_id', $tenantId)->whereNull('brand_user.removed_at')->exists()) {
            return null;
        }

        $grantCollectionIds = $user->collectionAccessGrants()
            ->whereNotNull('accepted_at')
            ->whereHas('collection', fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderBy('collection_id')
            ->pluck('collection_id');

        if ($grantCollectionIds->isEmpty()) {
            return null;
        }

        $sessionCollectionId = session('collection_id');
        $collectionId = ($sessionCollectionId && $grantCollectionIds->contains((int) $sessionCollectionId))
            ? (int) $sessionCollectionId
            : (int) $grantCollectionIds->first();

        session([
            'tenant_id' => $tenantId,
            'collection_id' => $collectionId,
        ]);
        session()->forget('brand_id');

        return redirect()->route('collection-invite.landing', ['collection' => $collectionId]);
    }

    /**
     * Determine which UI mode the gateway should display.
     *
     * Key behavior: if the user already has a brand in context (from session),
     * we go straight to 'enter' instead of forcing brand selection again.
     * The user can still switch via ?switch=1 or the switch modal.
     */
    protected function determineMode(Request $request, array $context): string
    {
        $isAuthenticated = $context['is_authenticated'];

        $requestedMode = $request->query('mode');
        if ($requestedMode && in_array($requestedMode, ['login', 'register'])) {
            return $requestedMode;
        }

        if (! $isAuthenticated) {
            return 'login';
        }

        if ($context['gateway_resume_active'] ?? false) {
            return 'enter';
        }

        if ($context['is_multi_company'] && ! $context['tenant']) {
            return 'company_select';
        }

        // When user has multiple brands, show brand selector unless gateway_resume_active (handled above).
        if ($context['is_multi_brand']) {
            return 'brand_select';
        }

        // Single brand: if tenant + brand resolved, go to enter.
        if ($context['tenant'] && $context['brand']) {
            return 'enter';
        }

        if ($context['tenant'] && ! $context['brand']) {
            return 'brand_select';
        }

        return 'company_select';
    }
}
