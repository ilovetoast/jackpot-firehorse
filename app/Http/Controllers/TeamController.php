<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\InviteMember;
use App\Models\Brand;
use App\Models\CollectionUser;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\PlanService;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function __construct(
        protected PlanService $planService
    ) {
    }

    /**
     * Show the team management page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'team' => 'You must select a company to view team members.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'team' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to manage team
        if (! $user->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can access team management.');
        }

        // Get all team members
        $firstUserId = $tenant->users()->orderBy('created_at')->first()?->id;
        $tenantBrandIds = $tenant->brands()->pluck('id')->toArray();
        
        \Log::info('TeamController::index() - Loading team members', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'brand_ids' => $tenantBrandIds,
        ]);
        
        $members = $tenant->users()->orderBy('created_at')->get()->map(function ($member) use ($tenant, $firstUserId, $tenantBrandIds) {
            // Get role from pivot table
            $role = $member->pivot->role;
            
            // If no role in pivot, fall back to: first user (oldest) is owner, others are members
            if (empty($role)) {
                $isOwner = $firstUserId && $firstUserId === $member->id;
                $role = $isOwner ? 'owner' : 'member';
            }
            
            // Capitalize first letter for display
            $roleDisplay = ucfirst($role);
            
            // Phase MI-1: Get brand assignments for this user in this tenant (active memberships only)
            // Query directly from brand_user table - filter to active memberships (removed_at IS NULL)
            $brandUserRecords = DB::table('brand_user')
                ->where('user_id', $member->id)
                ->whereIn('brand_id', $tenantBrandIds)
                ->whereNull('removed_at') // Phase MI-1: Only active memberships
                ->get();
            
            \Log::info('TeamController - Querying brand_user for member', [
                'user_id' => $member->id,
                'user_email' => $member->email,
                'tenant_id' => $tenant->id,
                'brand_ids_to_check' => $tenantBrandIds,
                'records_found' => $brandUserRecords->count(),
                'records' => $brandUserRecords->map(fn($r) => ['pivot_id' => $r->id, 'brand_id' => $r->brand_id, 'role' => $r->role])->toArray(),
            ]);
            
            $brandAssignments = collect($brandUserRecords)->map(function ($pivot) use ($member, $tenant) {
                $brand = \App\Models\Brand::find($pivot->brand_id);
                
                // Skip if brand was deleted
                if (!$brand) {
                    \Log::warning('TeamController - Brand not found for pivot record', [
                        'pivot_id' => $pivot->id,
                        'brand_id' => $pivot->brand_id,
                        'user_id' => $member->id,
                    ]);
                    return null;
                }
                
                // Verify brand belongs to this tenant (safety check)
                if ($brand->tenant_id !== $tenant->id) {
                    \Log::warning('TeamController - Brand does not belong to tenant', [
                        'pivot_id' => $pivot->id,
                        'brand_id' => $brand->id,
                        'brand_tenant_id' => $brand->tenant_id,
                        'current_tenant_id' => $tenant->id,
                        'user_id' => $member->id,
                    ]);
                    return null;
                }
                
                $brandRole = $pivot->role ?? 'viewer';
                // Validate brand role - if invalid, default to viewer
                // NO automatic conversion - invalid roles should be fixed manually
                if (!RoleRegistry::isValidBrandRole($brandRole)) {
                    \Log::warning('[TeamController] Invalid brand role detected, defaulting to viewer', [
                        'user_id' => $member->id,
                        'brand_id' => $brand->id,
                        'invalid_role' => $brandRole,
                    ]);
                    $brandRole = 'viewer';
                    // Update the database to fix invalid role
                    $member->setRoleForBrand($brand, 'viewer');
                }
                
                \Log::info('TeamController - Brand assignment found', [
                    'user_id' => $member->id,
                    'user_email' => $member->email,
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                    'role' => $brandRole,
                    'pivot_id' => $pivot->id,
                ]);
                
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'role' => $brandRole,
                    'pivot_id' => $pivot->id, // Include for debugging/cleanup
                ];
            })->filter()->values()->toArray(); // Convert to array for proper JSON serialization
            
            \Log::info('TeamController - Final brand assignments for member', [
                'user_id' => $member->id,
                'user_email' => $member->email,
                'assignments_count' => count($brandAssignments),
                'assignments' => $brandAssignments,
            ]);
            
            // C12: Collection-only users have no brand assignments but have collection_user grants for this tenant
            $hasCollectionGrants = $member->collectionAccessGrants()
                ->whereNotNull('accepted_at')
                ->whereHas('collection', fn ($q) => $q->where('tenant_id', $tenant->id))
                ->exists();
            $collectionOnly = empty($brandAssignments) && $hasCollectionGrants;
            $collectionGrantNames = $collectionOnly
                ? $member->collectionAccessGrants()
                    ->whereNotNull('accepted_at')
                    ->whereHas('collection', fn ($q) => $q->where('tenant_id', $tenant->id))
                    ->with('collection:id,name')
                    ->get()
                    ->pluck('collection.name')
                    ->unique()
                    ->values()
                    ->toArray()
                : [];

            return [
                'id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'avatar_url' => $member->avatar_url,
                'role' => $collectionOnly ? 'Collection access' : $roleDisplay,
                'role_value' => $collectionOnly ? 'collection_only' : strtolower($role), // C12: Never show Owner/Admin for collection-only
                'joined_at' => $member->pivot->created_at ?? $member->created_at,
                'brand_assignments' => $brandAssignments, // Already converted to array on line 144
                'is_orphaned' => false, // Current tenant members are not orphaned
                'collection_only' => $collectionOnly,
                'collection_grants' => $collectionGrantNames,
            ];
        });

        // Find orphaned brand_user records (users not in tenant but have brand assignments)
        // This catches cases where user was removed from tenant but brand_user records remain
        $tenantBrandIds = $tenant->brands()->pluck('id')->toArray();
        $orphanedBrandUsers = \DB::table('brand_user')
            ->whereIn('brand_id', $tenantBrandIds)
            ->whereNotIn('user_id', $tenant->users()->pluck('users.id')->toArray())
            ->get()
            ->map(function ($pivot) use ($tenant) {
                $user = \App\Models\User::find($pivot->user_id);
                $brand = \App\Models\Brand::find($pivot->brand_id);
                
                // Skip if user or brand was deleted (shouldn't happen due to CASCADE, but safety check)
                if (!$user || !$brand) {
                    return null;
                }
                
                $brandRole = $pivot->role ?? 'viewer';
                // Validate brand role - if invalid, default to viewer
                // NO automatic conversion - invalid roles should be fixed manually
                if (!RoleRegistry::isValidBrandRole($brandRole)) {
                    \Log::warning('[TeamController] Invalid brand role detected in orphaned record, defaulting to viewer', [
                        'user_id' => $pivot->user_id,
                        'brand_id' => $pivot->brand_id,
                        'invalid_role' => $brandRole,
                    ]);
                    $brandRole = 'viewer';
                }
                
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'role' => 'N/A', // No tenant role since they're not in tenant
                    'role_value' => null,
                    'joined_at' => $pivot->created_at,
                    'brand_assignments' => [
                        [
                            'id' => $brand->id,
                            'name' => $brand->name,
                            'role' => $brandRole,
                            'pivot_id' => $pivot->id, // Include pivot ID for cleanup
                        ],
                    ],
                    'is_orphaned' => true, // Mark as orphaned
                ];
            })
            ->filter() // Remove null entries
            ->values();
        
        // C12: Find collection-only orphans (users with collection_user for this tenant but not in tenant and not already in orphanedBrandUsers)
        $tenantUserIds = $tenant->users()->pluck('users.id')->toArray();
        $orphanedBrandUserIds = $orphanedBrandUsers->pluck('id')->unique()->values()->toArray();
        $tenantCollectionIds = \App\Models\Collection::where('tenant_id', $tenant->id)->pluck('id')->toArray();
        $collectionOnlyOrphans = collect();
        if (! empty($tenantCollectionIds)) {
            $collectionUserUserIds = CollectionUser::whereIn('collection_id', $tenantCollectionIds)
                ->pluck('user_id')
                ->unique()
                ->diff($tenantUserIds)
                ->diff($orphanedBrandUserIds)
                ->values()
                ->toArray();
            foreach ($collectionUserUserIds as $uid) {
                $user = User::find($uid);
                if (! $user) {
                    continue;
                }
                $grants = CollectionUser::where('user_id', $uid)
                    ->whereIn('collection_id', $tenantCollectionIds)
                    ->with('collection:id,name')
                    ->get();
                $collectionGrantNames = $grants->map(fn ($g) => $g->collection?->name)->filter()->values()->toArray();
                $collectionOnlyOrphans->push([
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'role' => 'N/A',
                    'role_value' => null,
                    'joined_at' => $grants->min('created_at'),
                    'brand_assignments' => [],
                    'is_orphaned' => true,
                    'collection_grants' => $collectionGrantNames,
                ]);
            }
        }

        // Merge orphaned records with regular members
        $allMembers = $members->merge($orphanedBrandUsers)->merge($collectionOnlyOrphans)->values();

        // Get plan limits
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxUsers = $planLimits['max_users'] ?? PHP_INT_MAX;
        $currentUserCount = $members->count();
        $userLimitReached = $currentUserCount >= $maxUsers;

        // Get brands for brand selection in invite form
        $brands = $tenant->brands()->orderBy('is_default', 'desc')->orderBy('name')->get()->map(function ($brand) {
            return [
                'id' => $brand->id,
                'name' => $brand->name,
                'is_default' => $brand->is_default,
            ];
        });

        // Get assignable tenant roles from RoleRegistry (excludes owner)
        $tenantRoles = collect(RoleRegistry::assignableTenantRoles())->map(function ($role) {
            return [
                'value' => $role,
                'label' => ucfirst($role),
            ];
        })->values()->toArray();

        // Convert to array and ensure proper serialization for Inertia
        $membersArray = $allMembers->map(function ($member) {
            // Ensure brand_assignments is an array, not a Collection
            $brandAssignments = $member['brand_assignments'] ?? [];
            if ($brandAssignments instanceof \Illuminate\Support\Collection) {
                $brandAssignments = $brandAssignments->toArray();
            }
            
            return [
                'id' => $member['id'],
                'first_name' => $member['first_name'],
                'last_name' => $member['last_name'],
                'email' => $member['email'],
                'avatar_url' => $member['avatar_url'],
                'role' => $member['role'],
                'role_value' => $member['role_value'],
                'joined_at' => $member['joined_at'],
                'brand_assignments' => $brandAssignments,
                'is_orphaned' => $member['is_orphaned'] ?? false,
                'collection_only' => $member['collection_only'] ?? false,
                'collection_grants' => $member['collection_grants'] ?? [],
            ];
        })->values()->toArray();
        
        // Log final response for debugging
        \Log::info('TeamController::index() - Final response', [
            'total_members' => count($membersArray),
            'members_data' => array_map(function ($member) {
                return [
                    'id' => $member['id'],
                    'email' => $member['email'],
                    'brand_assignments_count' => count($member['brand_assignments'] ?? []),
                    'brand_assignments' => $member['brand_assignments'] ?? [],
                ];
            }, $membersArray),
        ]);
        
        return Inertia::render('Companies/Team', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'members' => $membersArray,
            'brands' => $brands,
            'tenant_roles' => $tenantRoles,
            'current_user_count' => $currentUserCount,
            'max_users' => $maxUsers,
            'user_limit_reached' => $userLimitReached,
        ]);
    }

    /**
     * Remove a team member.
     */
    public function remove(Request $request, Tenant $tenant, User $user)
    {
        $authUser = Auth::user();

        // Verify user belongs to this company
        if (! $authUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to manage team
        if (! $authUser->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can manage team members.');
        }

        // Verify the user to be removed belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Prevent removing yourself
        if ($user->id === $authUser->id) {
            return back()->withErrors([
                'remove' => 'You cannot remove yourself from the company.',
            ]);
        }

        // Prevent removing the owner (first user)
        $owner = $tenant->users()->orderBy('created_at')->first();
        if ($user->id === $owner->id) {
            return back()->withErrors([
                'remove' => 'You cannot remove the company owner.',
            ]);
        }

        // Get user role before removal for logging
        $userRole = $user->pivot->role ?? null;
        
        // Remove user from tenant
        $tenant->users()->detach($user->id);

        // Log activity - company admin removing user
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_REMOVED_FROM_COMPANY,
            subject: $user,
            actor: $authUser, // Company admin removing user
            brand: null,
            metadata: [
                'removed_by' => $authUser->name,
                'removed_by_email' => $authUser->email,
                'user_role' => $userRole,
            ]
        );

        return redirect()->route('companies.team')->with('success', 'Team member removed successfully.');
    }

    /**
     * Delete a user from the company entirely: remove from tenant, all brand assignments, and all collection access.
     * Use for full cleanup (including orphans). Requires team.manage.
     */
    public function deleteFromCompany(Request $request, Tenant $tenant, User $user)
    {
        $authUser = Auth::user();

        if (! $authUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }
        if (! $authUser->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can remove users from the company.');
        }
        if ($user->id === $authUser->id) {
            return back()->withErrors(['delete' => 'You cannot remove yourself from the company.']);
        }

        $tenantBrandIds = $tenant->brands()->pluck('id')->toArray();
        $tenantCollectionIds = \App\Models\Collection::where('tenant_id', $tenant->id)->pluck('id')->toArray();
        $wasInTenant = $user->tenants()->where('tenants.id', $tenant->id)->exists();
        $owner = $tenant->owner();
        if ($wasInTenant && $owner && $owner->id === $user->id) {
            return back()->withErrors(['delete' => 'You cannot remove the company owner. Transfer ownership first.']);
        }

        DB::transaction(function () use ($user, $tenant, $tenantBrandIds, $tenantCollectionIds, $wasInTenant) {
            if ($wasInTenant) {
                $tenant->users()->detach($user->id);
            }
            if (! empty($tenantBrandIds)) {
                DB::table('brand_user')
                    ->where('user_id', $user->id)
                    ->whereIn('brand_id', $tenantBrandIds)
                    ->delete();
            }
            if (! empty($tenantCollectionIds)) {
                CollectionUser::where('user_id', $user->id)
                    ->whereIn('collection_id', $tenantCollectionIds)
                    ->delete();
            }
        });

        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_REMOVED_FROM_COMPANY,
            subject: $user,
            actor: $authUser,
            brand: null,
            metadata: [
                'deleted_from_company' => true,
                'was_tenant_member' => $wasInTenant,
            ]
        );

        return redirect()->route('companies.team')->with('success', 'User has been removed from the company and all access revoked.');
    }

    /**
     * Update a user's tenant-level role.
     */
    public function updateTenantRole(Request $request, Tenant $tenant, User $user)
    {
        $authUser = Auth::user();

        // Verify user belongs to this company
        if (! $authUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to manage team
        if (! $authUser->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can manage team members.');
        }

        // Verify the user to be updated belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        // Prevent changing the owner role (must always be one owner)
        $owner = $tenant->owner();
        if ($owner && $owner->id === $user->id) {
            return back()->withErrors([
                'role' => 'Cannot change the company owner role.',
            ]);
        }

        // Validate using RoleRegistry
        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        RoleRegistry::validateTenantRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        // Get old role for logging
        $oldRole = $user->getRoleForTenant($tenant);
        
        try {
            // Update role in pivot table
            // This will throw CannotAssignOwnerRoleException if trying to assign owner role
            $user->setRoleForTenant($tenant, $validated['role']);

            // Log activity
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::USER_ROLE_UPDATED,
                subject: $user,
                actor: $authUser,
                brand: null,
                metadata: [
                    'old_role' => $oldRole,
                    'new_role' => $validated['role'],
                ]
            );

            return back()->with('success', 'User role updated successfully.');
        } catch (\App\Exceptions\CannotAssignOwnerRoleException $e) {
            // Return error response with ownership transfer information
            $settingsLink = route('companies.settings') . '#ownership-transfer';
            
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'cannot_assign_owner_role',
                    'error_type' => 'owner_assignment_attempt',
                    'tenant_id' => $tenant->id,
                    'target_user_id' => $user->id,
                    'target_user_name' => $user->name,
                    'target_user_email' => $user->email,
                    'ownership_transfer_route' => route('ownership-transfer.initiate', ['tenant' => $tenant->id]),
                    'settings_link' => $settingsLink,
                    'requires_ownership_transfer' => true,
                ], 422);
            }

            return back()->withErrors([
                'role' => $e->getMessage(),
                'requires_ownership_transfer' => true,
                'target_user_id' => $user->id,
                'ownership_transfer_route' => route('ownership-transfer.initiate', ['tenant' => $tenant->id]),
                'settings_link' => $settingsLink,
            ]);
        }
    }

    /**
     * Update a user's brand-level role.
     */
    public function updateBrandRole(Request $request, Tenant $tenant, User $user, Brand $brand)
    {
        $authUser = Auth::user();

        // Verify user belongs to this company
        if (! $authUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to manage team
        if (! $authUser->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can manage team members.');
        }

        // Verify brand belongs to tenant
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this company.');
        }

        // Verify the user is assigned to this brand
        // Phase MI-1: Check active brand membership
        if (! $user->activeBrandMembership($brand)) {
            abort(404, 'User is not assigned to this brand.');
        }

        // Validate using RoleRegistry - no automatic conversion
        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        RoleRegistry::validateBrandRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        $brandRole = $validated['role'];

        // Get old role for logging
        $oldRole = $user->getRoleForBrand($brand);

        // Update role
        $user->setRoleForBrand($brand, $brandRole);

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ROLE_UPDATED,
            subject: $user,
            actor: $authUser,
            brand: $brand,
            metadata: [
                'old_role' => $oldRole,
                'new_role' => $validated['role'],
                'scope' => 'brand',
            ]
        );

        return back()->with('success', 'Brand role updated successfully.');
    }

    /**
     * C12: Add an existing tenant user (e.g. collection-only) to a brand. Gives them brand access without email invite.
     */
    public function addToBrand(Request $request, Tenant $tenant, User $user)
    {
        $authUser = Auth::user();

        if (! $authUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }
        if (! $authUser->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can add users to brands.');
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'role' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        RoleRegistry::validateBrandRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        $brand = Brand::where('id', $validated['brand_id'])->where('tenant_id', $tenant->id)->first();
        if (! $brand) {
            abort(403, 'Brand does not belong to this company.');
        }

        if ($user->activeBrandMembership($brand)) {
            return back()->withErrors(['brand_id' => 'User already has access to this brand. Use the role dropdown to change their role.']);
        }

        $user->setRoleForBrand($brand, $validated['role']);

        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_ROLE_UPDATED,
            subject: $user,
            actor: $authUser,
            brand: $brand,
            metadata: [
                'old_role' => null,
                'new_role' => $validated['role'],
                'scope' => 'brand',
                'added_from_collection_only' => true,
            ]
        );

        return redirect()->route('companies.team')->with('success', "{$user->name} has been added to {$brand->name}.");
    }

    /**
     * Invite a new team member.
     */
    public function invite(Request $request, Tenant $tenant)
    {
        $authUser = Auth::user();

        // Verify user belongs to this company
        if (! $authUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to manage team
        if (! $authUser->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'Only administrators and owners can invite team members.');
        }

        // Validate using RoleRegistry - no automatic conversion
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value === null) {
                        return; // Nullable, skip validation
                    }
                    try {
                        RoleRegistry::validateTenantRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
            'brands' => 'required|array|min:1',
            'brands.*.brand_id' => 'required|exists:brands,id',
            'brands.*.role' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        RoleRegistry::validateBrandRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        // Verify all brands belong to this tenant
        $brandIds = collect($validated['brands'])->pluck('brand_id')->unique();
        $tenantBrandIds = $tenant->brands()->pluck('id')->toArray();
        $invalidBrands = $brandIds->diff($tenantBrandIds);
        
        if ($invalidBrands->isNotEmpty()) {
            return back()->withErrors([
                'brands' => 'One or more selected brands do not belong to this company.',
            ]);
        }

        // Check plan limits
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxUsers = $planLimits['max_users'] ?? PHP_INT_MAX;
        $currentUserCount = $tenant->users()->count();

        if ($currentUserCount >= $maxUsers) {
            return back()->withErrors([
                'email' => "User limit reached ({$currentUserCount} of {$maxUsers}). Please upgrade your plan to invite more members.",
            ]);
        }

        // Check if user already exists
        $existingUser = User::where('email', $validated['email'])->first();

        if ($existingUser && $existingUser->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return back()->withErrors([
                'email' => 'This user is already a member of this company.',
            ]);
        }

        // Generate invite token
        $inviteToken = Str::random(64);
        $inviteUrl = route('invite.accept', [
            'token' => $inviteToken,
            'tenant' => $tenant->id,
        ]);

        // Determine tenant role (use provided role or default to 'member')
        // Default to 'member' if no role provided
        $tenantRole = $validated['role'] ?? 'member';
        
        // Ensure tenant role is valid and assignable (RoleRegistry validation already ensured this)
        if (!RoleRegistry::isAssignableTenantRole($tenantRole)) {
            $tenantRole = 'member'; // Fallback to member if somehow invalid
        }

        // Store invitation in database
        $invitation = TenantInvitation::create([
            'tenant_id' => $tenant->id,
            'email' => $validated['email'],
            'role' => $tenantRole,
            'token' => $inviteToken,
            'invited_by' => $authUser->id,
            'brand_assignments' => $validated['brands'],
            'sent_at' => now(),
        ]);

        if ($existingUser) {
            // User exists, add them to tenant
            $tenant->users()->attach($existingUser->id, ['role' => $tenantRole]);
            
            // Assign user to brands with roles
            foreach ($validated['brands'] as $brandAssignment) {
                $brand = $tenant->brands()->find($brandAssignment['brand_id']);
                if ($brand) {
                    $existingUser->setRoleForBrand($brand, $brandAssignment['role']);
                }
            }
            
            // Send notification email
            Mail::to($validated['email'])->send(new InviteMember($tenant, $authUser, $inviteUrl));

            // Log activity
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::USER_INVITED,
                subject: $existingUser,
                actor: $authUser,
                brand: null,
                metadata: [
                    'email' => $validated['email'],
                    'role' => $tenantRole,
                    'brands' => $validated['brands'],
                ]
            );

            return redirect()->route('companies.team')->with('success', 'Invitation sent successfully.');
        } else {
            // User doesn't exist - create them with a temporary password that needs to be changed
            // We'll use a special marker to indicate password needs to be set
            $tempPassword = Str::random(64); // Long random password that user cannot guess
            $newUser = User::create([
                'email' => $validated['email'],
                'password' => bcrypt($tempPassword), // Temporary password - user must set new one
                'first_name' => '',
                'last_name' => '',
            ]);

            // Add to tenant
            $tenant->users()->attach($newUser->id, ['role' => $tenantRole]);

            // Assign user to brands with roles
            foreach ($validated['brands'] as $brandAssignment) {
                $brand = $tenant->brands()->find($brandAssignment['brand_id']);
                if ($brand) {
                    $newUser->setRoleForBrand($brand, $brandAssignment['role']);
                }
            }

            // Send invitation email
            Mail::to($validated['email'])->send(new InviteMember($tenant, $authUser, $inviteUrl));

            // Log activity
            ActivityRecorder::record(
                tenant: $tenant,
                eventType: EventType::USER_INVITED,
                subject: $newUser,
                actor: $authUser,
                brand: null,
                metadata: [
                    'email' => $validated['email'],
                    'role' => $tenantRole,
                    'brands' => $validated['brands'],
                ]
            );

            return redirect()->route('companies.team')->with('success', 'Invitation sent successfully.');
        }
    }

    /**
     * Accept an invitation.
     */
    public function acceptInvite(Request $request, string $token, Tenant $tenant)
    {
        // Find the invitation by token
        $invitation = TenantInvitation::where('token', $token)
            ->where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->first();

        if (!$invitation) {
            return redirect()->route('login')->withErrors([
                'invitation' => 'Invalid or expired invitation link.',
            ]);
        }

        // Check if user is already authenticated
        if (Auth::check()) {
            $user = Auth::user();
            // Verify the authenticated user matches the invitation email
            if ($user->email !== $invitation->email) {
                Auth::logout();
                return redirect()->route('login')->withErrors([
                    'invitation' => 'This invitation is for a different email address. Please log in with the correct account.',
                ]);
            }
            // User is logged in and matches - mark invitation as accepted
            $invitation->update(['accepted_at' => now()]);
            // Set tenant and brand in session
            $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();
            if ($defaultBrand) {
                session([
                    'tenant_id' => $tenant->id,
                    'brand_id' => $defaultBrand->id,
                ]);
            }
            return redirect()->route('dashboard');
        }

        // Check if user exists
        $user = User::where('email', $invitation->email)->first();
        
        if (!$user) {
            // User doesn't exist yet (shouldn't happen based on current flow, but handle it)
            return redirect()->route('login')->withErrors([
                'invitation' => 'No account found for this invitation. Please contact support.',
            ]);
        }

        // Check if user needs to complete registration
        // If first_name/last_name are empty or password hasn't been set properly
        $needsRegistration = empty($user->first_name) || empty($user->last_name);
        
        if ($needsRegistration) {
            // Get brand information for display
            $brands = collect($invitation->brand_assignments ?? [])->map(function ($assignment) use ($tenant) {
                $brand = $tenant->brands()->find($assignment['brand_id'] ?? null);
                if (!$brand) {
                    return null;
                }
                return [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'role' => $assignment['role'] ?? 'member',
                ];
            })->filter()->values();

            // Show registration form
            return Inertia::render('Auth/InviteRegistration', [
                'invitation' => [
                    'token' => $token,
                    'tenant' => [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                    ],
                    'email' => $invitation->email,
                    'brands' => $brands,
                    'inviter' => $invitation->inviter ? [
                        'name' => $invitation->inviter->name,
                        'email' => $invitation->inviter->email,
                    ] : null,
                ],
            ]);
        }

        // User exists and has completed registration - redirect to login
        return redirect()->route('login')->with('info', 'You have been invited to join ' . $tenant->name . '. Please log in to access your account.');
    }

    /**
     * Complete invitation registration.
     */
    public function completeInviteRegistration(Request $request, string $token, Tenant $tenant)
    {
        // Find the invitation by token
        $invitation = TenantInvitation::where('token', $token)
            ->where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->first();

        if (!$invitation) {
            return back()->withErrors([
                'invitation' => 'Invalid or expired invitation link.',
            ])->withInput($request->only(['first_name', 'last_name', 'country', 'timezone']));
        }

        // Find the user
        $user = User::where('email', $invitation->email)->first();

        if (!$user) {
            return back()->withErrors([
                'email' => 'No account found for this invitation.',
            ])->withInput($request->only(['first_name', 'last_name', 'country', 'timezone']));
        }

        // Validate registration data
        // Manually handle validation to ensure Inertia works correctly
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'password' => ['required', 'confirmed', PasswordRule::defaults()],
                'country' => 'nullable|string|max:255',
                'timezone' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            // Manually preserve old input for Inertia
            // Extract input directly from request since $request->old() may be empty at this point
            // Exclude passwords for security
            $inputToPreserve = $request->only(['first_name', 'last_name', 'country', 'timezone']);
            
            // Redirect back to the invite acceptance page with errors and old input
            // Inertia will automatically pick up errors and old input from the session
            return redirect()->route('invite.accept', ['token' => $token, 'tenant' => $tenant->id])
                ->withErrors($e->errors())
                ->withInput($inputToPreserve);
        }

        // Update user information
        $user->update([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'password' => bcrypt($validated['password']),
            'country' => $validated['country'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
        ]);

        // Mark invitation as accepted
        $invitation->update(['accepted_at' => now()]);

        // Auto-login the user
        Auth::login($user);

        // Set tenant and brand in session
        $defaultBrand = $tenant->defaultBrand ?? $tenant->brands()->first();
        if ($defaultBrand) {
            session([
                'tenant_id' => $tenant->id,
                'brand_id' => $defaultBrand->id,
            ]);
        }

        // Log activity
        ActivityRecorder::record(
            tenant: $tenant,
            eventType: EventType::USER_INVITED,
            subject: $user,
            actor: $invitation->inviter,
            brand: null,
            metadata: [
                'action' => 'registration_completed',
                'invitation_token' => $token,
            ]
        );

        return redirect()->route('dashboard')->with('success', 'Welcome to ' . $tenant->name . '! Your account has been set up successfully.');
    }
}
