<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\BrandGateway\BrandContextResolver;
use App\Services\BrandGateway\BrandThemeBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BrandGatewayController extends Controller
{
    public function __construct(
        protected BrandContextResolver $contextResolver,
        protected BrandThemeBuilder $themeBuilder
    ) {
    }

    /**
     * Main gateway entry point.
     * Works for authenticated users, guests, and invite flows.
     */
    public function index(Request $request): Response
    {
        $context = $this->contextResolver->resolve($request);
        $theme = $this->buildThemeFromContext($context);
        $mode = $this->determineMode($request, $context);

        $portalAutoEnter = $theme['portal']['entry']['auto_enter'] ?? true;
        $autoEnter = $mode === 'enter'
            && $portalAutoEnter
            && ! $request->query('switch')
            && ! $request->query('mode');

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

        $mode = Auth::check() ? 'invite_accept' : 'invite_register';

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
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        if ($user->isSuspended()) {
            Auth::logout();
            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended. Please contact support.',
            ]);
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

        if ($tenants->count() > 1 && ! $tenantId) {
            return redirect()->route('gateway');
        }

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
            $slug = $originalSlug . '-' . $counter++;
        }

        $tenant = Tenant::create([
            'name' => $validated['company_name'],
            'slug' => $slug,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        $brand = $tenant->brands()->create([
            'name' => $validated['company_name'],
            'slug' => $slug,
            'is_default' => true,
        ]);

        $user->brands()->attach($brand->id, ['role' => 'brand_manager']);

        Auth::login($user);

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
        $invitation = TenantInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with('tenant')
            ->first();

        if (! $invitation) {
            return redirect()->route('gateway')->with('error', 'Invalid or expired invitation.');
        }

        $user = Auth::user();

        if ($user->email !== $invitation->email) {
            return redirect()->route('gateway')->with('error', 'This invitation is for a different email address.');
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

        $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand?->id,
        ]);

        $this->trackGatewayEvent(EventType::GATEWAY_INVITE_ACCEPTED, [
            'tenant' => ['id' => $tenant->id],
            'brand' => ['id' => $defaultBrand?->id],
            'is_authenticated' => true,
        ], ['invite_token' => substr($token, 0, 8) . '...']);

        return redirect()->route('overview')->with('success', "Welcome to {$tenant->name}!");
    }

    /**
     * Complete invite registration (new user) via the gateway.
     */
    public function completeInviteRegistration(Request $request, string $token)
    {
        $invitation = TenantInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with('tenant')
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages([
                'invitation' => 'Invalid or expired invitation link.',
            ]);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $user = User::where('email', $invitation->email)->first();

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
    protected function redirectToGatewayIntended(): \Illuminate\Http\RedirectResponse
    {
        $intended = session()->pull('intended_url');

        if ($intended) {
            $path = parse_url($intended, PHP_URL_PATH) ?? '';
            if ($path !== '' && str_starts_with($path, '/app/api')) {
                $intended = null;
            }
        }

        if (! $intended) {
            $intended = $this->resolveDefaultDestination();
        }

        return redirect()->to($intended);
    }

    /**
     * Resolve the default landing route from portal_settings.entry.default_destination.
     */
    protected function resolveDefaultDestination(): string
    {
        $brandId = session('brand_id');
        if (! $brandId) {
            return '/app/overview';
        }

        $brand = Brand::find($brandId);
        $destination = $brand?->getPortalSetting('entry.default_destination', 'assets');

        return match ($destination) {
            'guidelines' => '/app/brand-guidelines',
            'collections' => '/app/collections',
            default => '/app/overview',
        };
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
     * Determine which UI mode the gateway should display.
     */
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

        if ($context['is_multi_company'] && ! $context['tenant']) {
            return 'company_select';
        }

        // When user has multiple brands, ALWAYS show brand selector — never auto-enter.
        // Ensures they can switch brands/accounts before entering.
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
