<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\BillingService;
use App\Traits\HandlesFlashMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
        
        // Get current owner and all tenant users (excluding current owner) for ownership transfer
        $currentOwner = $tenant->owner();
        $isCurrentUserOwner = $currentOwner && $currentOwner->id === $user->id;
        $tenantUsers = $tenant->users()
            ->where('users.id', '!=', $currentOwner?->id ?? 0)
            ->select('users.id', 'users.first_name', 'users.last_name', 'users.email')
            ->get()
            ->map(function ($u) {
                $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                return [
                    'id' => $u->id,
                    'name' => $name ?: $u->email,
                    'email' => $u->email,
                ];
            });

        // Get pending ownership transfer if any
        $pendingTransfer = $tenant->ownershipTransfers()
            ->whereIn('status', [
                \App\Enums\OwnershipTransferStatus::PENDING,
                \App\Enums\OwnershipTransferStatus::CONFIRMED,
                \App\Enums\OwnershipTransferStatus::ACCEPTED,
            ])
            ->with(['fromUser', 'toUser', 'initiatedBy'])
            ->latest()
            ->first();

        $pendingTransferData = null;
        if ($pendingTransfer) {
            $pendingTransferData = [
                'id' => $pendingTransfer->id,
                'status' => $pendingTransfer->status->value,
                'status_label' => ucfirst($pendingTransfer->status->value),
                'from_user' => [
                    'id' => $pendingTransfer->fromUser->id,
                    'name' => $pendingTransfer->fromUser->name,
                    'email' => $pendingTransfer->fromUser->email,
                ],
                'to_user' => [
                    'id' => $pendingTransfer->toUser->id,
                    'name' => $pendingTransfer->toUser->name,
                    'email' => $pendingTransfer->toUser->email,
                ],
                'initiated_at' => $pendingTransfer->initiated_at?->toIso8601String(),
                'confirmed_at' => $pendingTransfer->confirmed_at?->toIso8601String(),
                'accepted_at' => $pendingTransfer->accepted_at?->toIso8601String(),
                'can_cancel' => $pendingTransfer->from_user_id === $user->id || $pendingTransfer->to_user_id === $user->id,
            ];
        }

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
            'is_current_user_owner' => $isCurrentUserOwner,
            'tenant_users' => $tenantUsers,
            'pending_transfer' => $pendingTransferData,
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
        // Eager load relationships
        $events = $query->with(['brand', 'subject', 'tenant'])
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
        
        // Ensure metadata is an array (it should be cast, but just in case)
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true) ?? [];
        }
        
        // Check if this is a tenant event first (check both enum constants and string values)
        $tenantEventTypes = [
            \App\Enums\EventType::TENANT_CREATED,
            \App\Enums\EventType::TENANT_UPDATED,
            \App\Enums\EventType::TENANT_DELETED,
            'tenant.created',
            'tenant.updated',
            'tenant.deleted',
        ];
        $isTenantEvent = in_array($eventType, $tenantEventTypes, true);
        
        // For tenant events, always use "Company" or tenant name, never show ID
        if ($isTenantEvent) {
            // For tenant events, the event is always about the current tenant
            // So we should use the current tenant's name, not try to load from subject
            // This is more reliable since we're viewing the activity log for a specific tenant
            $tenantName = $tenant ? $tenant->name : 'Company';
            
            // However, if metadata has subject_name, that might be more accurate (e.g., if name changed)
            if (isset($metadata['subject_name'])) {
                $tenantName = $metadata['subject_name'];
            }
            
            // Format tenant events
            if ($eventType === \App\Enums\EventType::TENANT_UPDATED || $eventType === 'tenant.updated') {
                return "Updated {$tenantName}";
            }
            if ($eventType === \App\Enums\EventType::TENANT_CREATED || $eventType === 'tenant.created') {
                return "Created {$tenantName}";
            }
            if ($eventType === \App\Enums\EventType::TENANT_DELETED || $eventType === 'tenant.deleted') {
                return "Deleted {$tenantName}";
            }
        }
        
        // Get subject name if available (for non-tenant events)
        $subjectName = null;
        
        // PRIORITY 1: Check metadata first (most reliable, always stored for new events)
        if (isset($metadata['subject_name']) && !empty($metadata['subject_name'])) {
            $subjectName = $metadata['subject_name'];
        }
        
        // PRIORITY 2: Try to get from subject relationship if metadata doesn't have it
        if (!$subjectName && $event->subject) {
            // Check if it's a Tenant model
            if ($event->subject instanceof \App\Models\Tenant) {
                $subjectName = $event->subject->name ?? null;
            } elseif (method_exists($event->subject, 'getNameAttribute')) {
                $subjectName = $event->subject->name;
            } elseif (isset($event->subject->name)) {
                $subjectName = $event->subject->name;
            } elseif (method_exists($event->subject, 'getTitleAttribute')) {
                $subjectName = $event->subject->title ?? null;
            }
        }
        
        // Try brand name for brand-related events
        if (!$subjectName && $event->brand) {
            $subjectName = $event->brand->name ?? null;
        }
        
        // If we still don't have a name but have subject_id, try to load it directly from database
        // This is important because the subject relationship might not be loaded or the model might be soft-deleted
        if (!$subjectName && $event->subject_id && $event->subject_type) {
            try {
                // Try to load the model based on subject_type
                $subjectClass = $event->subject_type;
                
                // Handle different class name formats
                if (str_contains($subjectClass, '\\')) {
                    // Full class name like "App\Models\Tenant"
                    if (class_exists($subjectClass)) {
                        $subjectModel = $subjectClass::find($event->subject_id);
                        if ($subjectModel) {
                            if (isset($subjectModel->name)) {
                                $subjectName = $subjectModel->name;
                            } elseif (isset($subjectModel->title)) {
                                $subjectName = $subjectModel->title;
                            } elseif (method_exists($subjectModel, 'getNameAttribute')) {
                                $subjectName = $subjectModel->name ?? null;
                            }
                        }
                    }
                } else {
                    // Try common model classes
                    $possibleClasses = [
                        "App\\Models\\{$subjectClass}",
                        "App\\Models\\" . ucfirst($subjectClass),
                    ];
                    
                    foreach ($possibleClasses as $class) {
                        if (class_exists($class)) {
                            $subjectModel = $class::find($event->subject_id);
                            if ($subjectModel) {
                                if (isset($subjectModel->name)) {
                                    $subjectName = $subjectModel->name;
                                    break;
                                } elseif (isset($subjectModel->title)) {
                                    $subjectName = $subjectModel->title;
                                    break;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors - model might be deleted or class doesn't exist
            }
        }
        
        // If no subject name but we have subject_id, use just the number with note
        $subjectIdentifier = $subjectName;
        if (!$subjectIdentifier && $event->subject_id) {
            $subjectIdentifier = "#{$event->subject_id} (no longer exists)";
        }
        
        // Format based on event type with human-readable language
        switch ($eventType) {
            case \App\Enums\EventType::BRAND_CREATED:
                return $subjectIdentifier ? "Created {$subjectIdentifier}" : 'Created brand';
            case \App\Enums\EventType::BRAND_UPDATED:
                return $subjectIdentifier ? "Updated {$subjectIdentifier}" : 'Updated brand';
            case \App\Enums\EventType::BRAND_DELETED:
                return $subjectIdentifier ? "Deleted {$subjectIdentifier}" : 'Deleted brand';
            case \App\Enums\EventType::USER_CREATED:
                return $subjectIdentifier ? "Created {$subjectIdentifier} account" : 'Created user account';
            case \App\Enums\EventType::USER_UPDATED:
                $action = $metadata['action'] ?? 'updated';
                if ($action === 'suspended') {
                    return $subjectIdentifier ? "Suspended {$subjectIdentifier} account" : 'Suspended account';
                } elseif ($action === 'unsuspended') {
                    return $subjectIdentifier ? "Unsuspended {$subjectIdentifier} account" : 'Unsuspended account';
                }
                return $subjectIdentifier ? "Updated {$subjectIdentifier} account" : 'Updated user account';
            case \App\Enums\EventType::USER_DELETED:
                return $subjectIdentifier ? "Deleted {$subjectIdentifier} account" : 'Deleted user account';
            case \App\Enums\EventType::USER_INVITED:
                return 'Invited user';
            case \App\Enums\EventType::USER_REMOVED_FROM_COMPANY:
                return 'Removed user from company';
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
                    return "Changed role from {$oldRole} to {$newRole}";
                }
                return 'Updated role';
            case \App\Enums\EventType::CATEGORY_CREATED:
                $brandContext = $event->brand ? " for {$event->brand->name}" : '';
                return $subjectIdentifier ? "Created {$subjectIdentifier} Category{$brandContext}" : 'Created category';
            case \App\Enums\EventType::CATEGORY_UPDATED:
                $brandContext = $event->brand ? " for {$event->brand->name}" : '';
                return $subjectIdentifier ? "Updated {$subjectIdentifier} Category{$brandContext}" : 'Updated category';
            case \App\Enums\EventType::CATEGORY_DELETED:
                $brandContext = $event->brand ? " for {$event->brand->name}" : '';
                return $subjectIdentifier ? "Deleted {$subjectIdentifier} Category{$brandContext}" : 'Deleted category';
            case \App\Enums\EventType::CATEGORY_SYSTEM_UPGRADED:
                $fieldsUpdated = $metadata['fields_updated'] ?? [];
                $oldVersion = $metadata['old_version'] ?? null;
                $newVersion = $metadata['new_version'] ?? null;
                $versionInfo = ($oldVersion && $newVersion) ? " (v{$oldVersion} → v{$newVersion})" : '';
                if ($subjectIdentifier) {
                    return "Upgraded {$subjectIdentifier}{$versionInfo}";
                }
                return "Upgraded category{$versionInfo}";
            case \App\Enums\EventType::PLAN_UPDATED:
                $oldPlan = $metadata['old_plan'] ?? null;
                $newPlan = $metadata['new_plan'] ?? null;
                $oldPlanName = $oldPlan ? ucfirst($oldPlan) : null;
                $newPlanName = $newPlan ? ucfirst($newPlan) : null;
                if ($oldPlanName && $newPlanName) {
                    return "Changed plan from {$oldPlanName} to {$newPlanName}";
                }
                return 'Updated plan';
            case \App\Enums\EventType::SUBSCRIPTION_CREATED:
                $planName = $metadata['plan'] ?? $metadata['new_plan'] ?? $metadata['plan_name'] ?? null;
                $planDisplayName = $planName ? ucfirst($planName) . ' plan' : 'subscription';
                return "Started {$planDisplayName} subscription";
            case \App\Enums\EventType::SUBSCRIPTION_UPDATED:
                $action = $metadata['action'] ?? null;
                $oldPlan = $metadata['old_plan'] ?? null;
                $newPlan = $metadata['new_plan'] ?? null;
                $oldPlanName = $oldPlan ? ucfirst($oldPlan) : null;
                $newPlanName = $newPlan ? ucfirst($newPlan) : null;
                
                if ($action === 'created' && $newPlanName) {
                    return "Started {$newPlanName} subscription";
                } elseif ($action === 'resumed' && $newPlanName) {
                    return "Resumed {$newPlanName} subscription";
                } elseif ($action === 'upgrade' && $oldPlanName && $newPlanName) {
                    return "Upgraded subscription from {$oldPlanName} to {$newPlanName}";
                } elseif ($action === 'downgrade' && $oldPlanName && $newPlanName) {
                    return "Downgraded subscription from {$oldPlanName} to {$newPlanName}";
                } elseif ($oldPlanName && $newPlanName) {
                    return "Changed subscription from {$oldPlanName} to {$newPlanName}";
                } elseif ($newPlanName) {
                    return "Updated subscription to {$newPlanName}";
                }
                return 'Updated subscription';
            case \App\Enums\EventType::SUBSCRIPTION_CANCELED:
                $planName = $metadata['plan'] ?? $metadata['old_plan'] ?? $metadata['plan_name'] ?? null;
                $planDisplayName = $planName ? ucfirst($planName) . ' plan' : '';
                return $planDisplayName ? "Canceled {$planDisplayName} subscription" : 'Canceled subscription';
            case \App\Enums\EventType::INVOICE_PAID:
                $amount = $metadata['amount'] ?? null;
                $currency = $metadata['currency'] ?? 'USD';
                if ($amount) {
                    $formattedAmount = is_numeric($amount) ? number_format($amount / 100, 2) : $amount;
                    return "Paid invoice ({$currency} {$formattedAmount})";
                }
                return 'Paid invoice';
            case \App\Enums\EventType::INVOICE_FAILED:
                $amount = $metadata['amount'] ?? null;
                $currency = $metadata['currency'] ?? 'USD';
                if ($amount) {
                    $formattedAmount = is_numeric($amount) ? number_format($amount / 100, 2) : $amount;
                    return "Invoice payment failed ({$currency} {$formattedAmount})";
                }
                return 'Invoice payment failed';
            default:
                // Fallback: format event type nicely
                return ucfirst(str_replace(['_', '.'], ' ', $eventType));
        }
    }

    /**
     * Delete the company (tenant).
     */
    public function destroy(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'error' => 'You must select a company to delete.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to delete the company
        $this->authorize('delete', $tenant);

        // Check if user is the owner (only owners can delete companies)
        $userRole = $user->getRoleForTenant($tenant);
        if ($userRole !== 'owner') {
            return back()->withErrors([
                'error' => 'Only the company owner can delete the company.',
            ]);
        }

        // Prevent deletion if there are other users in the company
        $otherUsersCount = $tenant->users()->where('users.id', '!=', $user->id)->count();
        if ($otherUsersCount > 0) {
            return back()->withErrors([
                'error' => 'Cannot delete company. Please remove all other team members first.',
            ]);
        }

        // Cancel any active subscriptions
        try {
            $subscription = $tenant->subscription('default');
            if ($subscription && $subscription->active()) {
                $subscription->cancel();
            }
        } catch (\Exception $e) {
            // Log but don't fail deletion if subscription cancellation fails
            \Log::warning('Failed to cancel subscription during company deletion', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Record activity before deletion
        \App\Services\ActivityRecorder::record(
            tenant: $tenant->id,
            eventType: \App\Enums\EventType::TENANT_DELETED,
            subject: $tenant,
            actor: $user,
            brand: null,
            metadata: [
                'subject_name' => $tenant->name,
            ]
        );

        $tenantName = $tenant->name;

        // Delete the tenant (cascade will handle related data)
        $tenant->delete();

        // Clear session if this was the active tenant
        if (session('tenant_id') == $tenant->id) {
            session()->forget(['tenant_id', 'brand_id']);
        }

        return redirect()->route('companies.index')
            ->with('success', "Company '{$tenantName}' has been permanently deleted.");
    }

    /**
     * Show the company permissions page.
     */
    public function permissions(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant');

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'permissions' => 'You must select a company to view permissions.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'permissions' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view permissions (owners/admins can view)
        $tenantRole = $user->getRoleForTenant($tenant);
        if (!in_array($tenantRole, ['owner', 'admin'])) {
            abort(403, 'Only administrators and owners can view permissions.');
        }

        // Get company roles
        $companyRoles = [
            ['id' => 'owner', 'name' => 'Owner', 'icon' => '👑'],
            ['id' => 'admin', 'name' => 'Admin', 'icon' => ''],
            ['id' => 'brand_manager', 'name' => 'Brand Manager', 'icon' => ''],
            ['id' => 'member', 'name' => 'Member', 'icon' => ''],
        ];

        // Get company permissions (all permissions that are NOT site permissions)
        $companyPermissions = Permission::where(function ($query) {
                $query->whereNotIn('name', ['company.manage', 'permissions.manage'])
                    ->where('name', 'not like', 'site.%');
            })
            ->orderBy('name')
            ->pluck('name')
            ->toArray();

        // Get current role permissions
        $companyRolePermissions = [];
        foreach ($companyRoles as $roleData) {
            $role = Role::where('name', $roleData['id'])->first();
            if ($role) {
                $permissions = $role->permissions->pluck('name')->toArray();
                $companyRolePermissions[$roleData['id']] = array_fill_keys($permissions, true);
            }
        }

        return Inertia::render('Companies/Permissions', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'company_roles' => $companyRoles,
            'company_permissions' => $companyPermissions,
            'company_role_permissions' => $companyRolePermissions,
        ]);
    }
}
