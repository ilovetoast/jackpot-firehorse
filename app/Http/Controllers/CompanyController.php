<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\BillingService;
use App\Traits\HandlesFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    use HandlesFlashMessages;
    public function __construct(
        protected BillingService $billingService
    ) {
    }

    /**
     * Show the company management page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $companies = $user->tenants;
        $currentCompanyId = session('tenant_id');

        return Inertia::render('Companies/Index', [
            'companies' => $companies->map(function ($company) use ($currentCompanyId) {
                $currentPlan = $this->billingService->getCurrentPlan($company);
                // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
                $subscription = $company->subscriptions()
                    ->where('name', 'default')
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'timezone' => $company->timezone ?? 'UTC',
                    'is_active' => $company->id == $currentCompanyId,
                    'billing' => [
                        'current_plan' => $currentPlan,
                        'subscription_status' => $subscription ? $subscription->stripe_status : 'none',
                    ],
                ];
            }),
        ]);
    }

    /**
     * Switch to a different company.
     */
    public function switch(Request $request, Tenant $tenant)
    {
        $user = Auth::user();

        // Verify user belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        $defaultBrand = $tenant->defaultBrand;
        
        if (! $defaultBrand) {
            abort(500, 'Tenant must have at least one brand');
        }
        
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand->id,
        ]);

        return redirect()->intended('/app/dashboard');
    }

    /**
     * Show the company settings page.
     */
    public function settings()
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You must select a company to view settings.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view company settings
        // Check via tenant role permissions
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            abort(403, 'Only administrators and owners can access company settings.');
        }

        // Get billing information
        $currentPlan = $this->billingService->getCurrentPlan($tenant);
        // Query subscription directly instead of using Cashier's method (more reliable with Tenant model)
        $subscription = $tenant->subscriptions()
            ->where('name', 'default')
            ->orderBy('created_at', 'desc')
            ->first();
        $teamMembersCount = $tenant->users()->count();
        $brandsCount = $tenant->brands()->count();

        return Inertia::render('Companies/Settings', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone ?? 'UTC',
            ],
            'billing' => [
                'current_plan' => $currentPlan,
                'subscription_status' => $subscription ? $subscription->stripe_status : 'none',
            ],
            'team_members_count' => $teamMembersCount,
            'brands_count' => $brandsCount,
        ]);
    }

    /**
     * Update the company settings.
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You must select a company to update settings.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to view company settings (required for update too)
        // Check via tenant role permissions
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            abort(403, 'Only administrators and owners can update company settings.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'timezone' => 'required|string|max:255',
        ]);

        $tenant->update($validated);

        return $this->backWithSuccess('Updated');
    }

    /**
     * Show the company activity logs page.
     */
    public function activity(Request $request): Response
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active company from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'activity' => 'You must select a company to view activity logs.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'activity' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view activity logs
        if (! $user->hasPermissionForTenant($tenant, 'activity_logs.view')) {
            abort(403, 'You do not have permission to view activity logs.');
        }

        $query = \App\Models\ActivityEvent::query()
            ->where('tenant_id', $tenant->id); // Only show events for this company

        // Filter by event type
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        // Filter by actor type
        if ($request->filled('actor_type')) {
            $query->where('actor_type', $request->actor_type);
        }

        // Filter by subject type
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        // Filter by brand
        if ($request->filled('brand_id')) {
            if ($request->brand_id === 'null') {
                $query->whereNull('brand_id');
            } else {
                $query->where('brand_id', $request->brand_id);
            }
        }

        // Order by most recent first
        $query->orderBy('created_at', 'desc');

        // Paginate results
        $perPage = (int) $request->get('per_page', 50);
        // Don't eager load actor to avoid errors with string types (system, api, guest)
        // We'll load it manually in the formatting method
        $events = $query->with(['brand', 'subject'])
            ->paginate($perPage)
            ->appends($request->except('page'));

        // Get filter options (only for this company)
        $brands = $tenant->brands()->orderBy('name')->get(['id', 'name']);
        $eventTypes = \App\Enums\EventType::all();
        $actorTypes = ['user', 'system', 'api', 'guest'];
        
        // Get unique subject types from this company's events
        $subjectTypes = \App\Models\ActivityEvent::select('subject_type')
            ->where('tenant_id', $tenant->id)
            ->distinct()
            ->whereNotNull('subject_type')
            ->orderBy('subject_type')
            ->pluck('subject_type')
            ->toArray();

        // Format events for display
        $formattedEvents = $events->map(function ($event) use ($tenant) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'description' => $this->formatActivityDescription($event, $tenant),
                'created_at' => $event->created_at->toDateTimeString(),
                'created_at_human' => $event->created_at->diffForHumans(),
                'brand' => $event->brand ? [
                    'id' => $event->brand->id,
                    'name' => $event->brand->name,
                ] : null,
                'tenant' => $tenant->name,
                'actor' => $this->formatActor($event),
                'subject' => $this->formatSubject($event),
                'metadata' => $event->metadata,
                'ip_address' => $event->ip_address,
                'user_agent' => $event->user_agent,
            ];
        });

        return Inertia::render('Companies/Activity', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'events' => $formattedEvents,
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'filters' => [
                'event_type' => $request->event_type,
                'actor_type' => $request->actor_type,
                'subject_type' => $request->subject_type,
                'brand_id' => $request->brand_id,
                'date_from' => $request->date_from,
                'date_to' => $request->date_to,
                'per_page' => $perPage,
            ],
            'filter_options' => [
                'brands' => $brands,
                'event_types' => $eventTypes,
                'actor_types' => $actorTypes,
                'subject_types' => $subjectTypes,
            ],
        ]);
    }

    /**
     * Format actor for display.
     */
    private function formatActor($event): ?array
    {
        if (!$event->actor_type) {
            return [
                'type' => 'unknown',
                'name' => 'Unknown',
            ];
        }

        // Handle string actor types (system, api, guest) that aren't models
        $stringActorTypes = ['system', 'api', 'guest'];
        if (in_array($event->actor_type, $stringActorTypes, true)) {
            return [
                'type' => $event->actor_type,
                'name' => ucfirst($event->actor_type),
            ];
        }

        // For 'user' type, manually load the User model to avoid relationship errors
        if ($event->actor_type === 'user' && $event->actor_id) {
            try {
                $user = \App\Models\User::find($event->actor_id);
                if ($user) {
                    return [
                        'type' => 'user',
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                        'first_name' => $user->first_name,
                        'last_name' => $user->last_name,
                        'email' => $user->email,
                        'avatar_url' => $user->avatar_url,
                    ];
                }
            } catch (\Exception $e) {
                // If user not found, fall through to default
            }
        }

        return [
            'type' => $event->actor_type,
            'name' => ucfirst($event->actor_type),
        ];
    }

    /**
     * Format subject for display.
     */
    private function formatSubject($event): ?array
    {
        if (!$event->subject_type || !$event->subject_id) {
            return null;
        }

        if ($event->subject) {
            $name = match (true) {
                method_exists($event->subject, 'name') => $event->subject->name,
                method_exists($event->subject, 'title') => $event->subject->title,
                method_exists($event->subject, 'email') => $event->subject->email,
                default => '#' . $event->subject->id,
            };

            return [
                'type' => $event->subject_type,
                'id' => $event->subject_id,
                'name' => $name,
            ];
        }

        return [
            'type' => $event->subject_type,
            'id' => $event->subject_id,
            'name' => '#' . $event->subject_id,
        ];
    }

    /**
     * Format activity description for display with human-readable language.
     */
    private function formatActivityDescription($event, $tenant): string
    {
        $eventType = $event->event_type;
        $metadata = $event->metadata ?? [];
        
        // Get subject name if available
        $subjectName = null;
        if ($event->subject) {
            if (method_exists($event->subject, 'getNameAttribute')) {
                $subjectName = $event->subject->name;
            } elseif (isset($event->subject->name)) {
                $subjectName = $event->subject->name;
            }
        }
        if (!$subjectName && isset($metadata['subject_name'])) {
            $subjectName = $metadata['subject_name'];
        }
        if (!$subjectName && $event->tenant) {
            $subjectName = $event->tenant->name ?? null;
        }
        if (!$subjectName && $event->brand) {
            $subjectName = $event->brand->name ?? null;
        }
        
        // Format based on event type with human-readable language
        switch ($eventType) {
            case \App\Enums\EventType::TENANT_UPDATED:
                return $subjectName ? "{$subjectName} was updated" : ($tenant ? "{$tenant->name} was updated" : 'Company was updated');
            case \App\Enums\EventType::TENANT_CREATED:
                return $subjectName ? "{$subjectName} was created" : ($tenant ? "{$tenant->name} was created" : 'Company was created');
            case \App\Enums\EventType::TENANT_DELETED:
                return $subjectName ? "{$subjectName} was deleted" : 'Company was deleted';
            case \App\Enums\EventType::BRAND_CREATED:
                return $subjectName ? "{$subjectName} was created" : 'Brand was created';
            case \App\Enums\EventType::BRAND_UPDATED:
                return $subjectName ? "{$subjectName} was updated" : 'Brand was updated';
            case \App\Enums\EventType::BRAND_DELETED:
                return $subjectName ? "{$subjectName} was deleted" : 'Brand was deleted';
            case \App\Enums\EventType::USER_CREATED:
                return $subjectName ? "{$subjectName} account was created" : 'User account was created';
            case \App\Enums\EventType::USER_UPDATED:
                $action = $metadata['action'] ?? 'updated';
                if ($action === 'suspended') {
                    return $subjectName ? "{$subjectName} account was suspended" : 'Account was suspended';
                } elseif ($action === 'unsuspended') {
                    return $subjectName ? "{$subjectName} account was unsuspended" : 'Account was unsuspended';
                }
                return $subjectName ? "{$subjectName} account was updated" : 'User account was updated';
            case \App\Enums\EventType::USER_DELETED:
                return $subjectName ? "{$subjectName} account was deleted" : 'User account was deleted';
            case \App\Enums\EventType::USER_INVITED:
                return 'User was invited';
            case \App\Enums\EventType::USER_REMOVED_FROM_COMPANY:
                return 'User was removed from company';
            case \App\Enums\EventType::USER_ADDED_TO_BRAND:
                $role = $metadata['role'] ?? 'member';
                $brandName = $event->brand->name ?? null;
                return $brandName ? "Added to {$brandName} as {$role}" : "Added to brand as {$role}";
            case \App\Enums\EventType::USER_REMOVED_FROM_BRAND:
                $brandName = $event->brand->name ?? null;
                return $brandName ? "Removed from {$brandName}" : 'Removed from brand';
            case \App\Enums\EventType::USER_ROLE_UPDATED:
                $oldRole = $metadata['old_role'] ?? null;
                $newRole = $metadata['new_role'] ?? null;
                if ($oldRole && $newRole) {
                    return "Role changed from {$oldRole} to {$newRole}";
                }
                return 'Role was updated';
            case \App\Enums\EventType::CATEGORY_CREATED:
                return $subjectName ? "{$subjectName} category was created" : 'Category was created';
            case \App\Enums\EventType::CATEGORY_UPDATED:
                return $subjectName ? "{$subjectName} category was updated" : 'Category was updated';
            case \App\Enums\EventType::CATEGORY_DELETED:
                return $subjectName ? "{$subjectName} category was deleted" : 'Category was deleted';
            case \App\Enums\EventType::PLAN_UPDATED:
                $oldPlan = $metadata['old_plan'] ?? null;
                $newPlan = $metadata['new_plan'] ?? null;
                if ($oldPlan && $newPlan) {
                    return "Plan changed from {$oldPlan} to {$newPlan}";
                }
                return 'Plan was updated';
            default:
                // Fallback: format event type nicely
                return ucfirst(str_replace(['_', '.'], ' ', $eventType));
        }
    }
}
