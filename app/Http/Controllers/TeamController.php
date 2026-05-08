<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\InviteMember;
use App\Models\Brand;
use App\Models\BrandInvitation;
use App\Models\CollectionUser;
use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Demo\DemoTenantService;
use App\Services\PlanService;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
    ) {}

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

        // Check if user has permission to manage team (unified AuthPermissionService)
        if (! $user->canForContext('team.manage', $tenant, null)) {
            abort(403, 'Only administrators and owners can access team management.');
        }

        // Refactored: Users list loaded via API (GET /api/companies/users) for pagination/scalability.
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxUsers = $planLimits['max_users'] ?? PHP_INT_MAX;
        $currentUserCount = $tenant->users()->count();
        $userLimitReached = $currentUserCount >= $maxUsers;

        $brands = $tenant->brands()->orderBy('is_default', 'desc')->orderBy('name')->get()->map(function ($brand) {
            return [
                'id' => $brand->id,
                'name' => $brand->name,
                'is_default' => $brand->is_default,
            ];
        });

        $assignableForInvite = $tenant->is_agency
            ? RoleRegistry::assignableAgencyWorkspaceRolesForInviter($user, $tenant)
            : RoleRegistry::directAssignableTenantRolesForInviter($user, $tenant);
        $order = ['member' => 0, 'admin' => 1];
        $tenantRoles = collect($assignableForInvite)
            ->sortBy(fn ($role) => $order[$role] ?? 99)
            ->values()
            ->map(fn ($role) => [
                'value' => $role,
                'label' => RoleRegistry::tenantRoleDisplayLabel($role),
            ])
            ->all();

        return Inertia::render('Companies/Team', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'brands' => $brands,
            'tenant_roles' => $tenantRoles,
            'invite_lock_company_role' => $assignableForInvite === ['member'],
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
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
            abort(403, 'Only administrators and owners can manage team members.');
        }

        // Verify the user to be removed belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        if ($response = $this->guardAgencyManagedUser($request, $tenant, $user)) {
            return $response;
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
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
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

        if ($response = $this->guardAgencyManagedUser($request, $tenant, $user)) {
            return $response;
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
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
            abort(403, 'Only administrators and owners can manage team members.');
        }

        // Verify the user to be updated belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        if ($user->isAgencyManagedMemberOf($tenant)) {
            $msg = 'Agency-managed members are controlled through Company Settings → Agencies.';

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => $msg, 'error' => 'agency_managed_user'], 422);
            }

            return back()->withErrors(['role' => $msg]);
        }

        // Prevent changing the owner role (must always be one owner)
        $owner = $tenant->owner();
        if ($owner && $owner->id === $user->id) {
            return back()->withErrors([
                'role' => 'Cannot change the company owner role.',
            ]);
        }

        $validated = $request->validate([
            'role' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    try {
                        RoleRegistry::validateDirectCompanyTenantRoleAssignment($value);
                    } catch (\InvalidArgumentException $e) {
                        $fail($e->getMessage());
                    }
                },
            ],
        ]);

        $assignable = RoleRegistry::directAssignableTenantRolesForInviter($authUser, $tenant);
        if (! in_array(strtolower($validated['role']), $assignable, true)) {
            $labels = array_map(fn ($r) => RoleRegistry::tenantRoleDisplayLabel($r), $assignable);

            return $this->teamRoleForbiddenResponse(
                $request,
                'role',
                'You do not have permission to assign this company role. Allowed roles: '.implode(', ', $labels).'.'
            );
        }

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
            $settingsLink = route('companies.settings').'#ownership-transfer';

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
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
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

        if ($response = $this->guardAgencyManagedUser($request, $tenant, $user)) {
            return $response;
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
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
            abort(403, 'Only administrators and owners can add users to brands.');
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(404, 'User is not a member of this company.');
        }

        if ($response = $this->guardAgencyManagedUser($request, $tenant, $user)) {
            return $response;
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
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
            abort(403, 'Only administrators and owners can invite team members.');
        }

        $demoInviteMessage = app(DemoTenantService::class)->demoRestrictionMessage(
            DemoTenantService::ACTION_INVITE_USERS,
            $tenant
        );
        if ($demoInviteMessage !== null) {
            return back()->withErrors(['email' => $demoInviteMessage]);
        }

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    try {
                        RoleRegistry::validateDirectCompanyTenantRoleAssignment($value);
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

        $linkedAgencyIds = TenantAgency::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('agency_tenant_id');
        if ($linkedAgencyIds->isNotEmpty() && $existingUser && $existingUser->tenants()->whereIn('tenants.id', $linkedAgencyIds)->exists()) {
            return back()->withErrors([
                'email' => 'This user belongs to a linked agency. Add them through the agency partnership, or invite them as a direct company member.',
            ]);
        }

        // Generate invite token
        $inviteToken = Str::random(64);
        $inviteUrl = route('gateway.invite', [
            'token' => $inviteToken,
        ]);

        $tenantRole = $validated['role'] ?? 'member';
        try {
            RoleRegistry::validateDirectCompanyTenantRoleAssignment($tenantRole);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        $assignable = RoleRegistry::directAssignableTenantRolesForInviter($authUser, $tenant);
        if (! in_array(strtolower($tenantRole), $assignable, true)) {
            $labels = array_map(fn ($r) => RoleRegistry::tenantRoleDisplayLabel($r), $assignable);

            return back()->withErrors([
                'role' => 'You do not have permission to assign this company role. Allowed roles: '.implode(', ', $labels).'.',
            ]);
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
     * Legacy URL: /invite/accept/{token}/{tenant} — forwards to the branded gateway invite flow.
     */
    public function acceptInvite(Request $request, string $token, Tenant $tenant)
    {
        $tenantInv = TenantInvitation::where('token', $token)
            ->where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')
            ->first();

        $brandInv = BrandInvitation::where('token', $token)
            ->whereNull('accepted_at')
            ->with('brand')
            ->first();

        $validBrandInvite = $brandInv
            && $brandInv->brand
            && (int) $brandInv->brand->tenant_id === (int) $tenant->id;

        if (! $tenantInv && ! $validBrandInvite) {
            return redirect()->route('gateway')->with('error', 'Invalid or expired invitation.');
        }

        return redirect()->route('gateway.invite', ['token' => $token]);
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

        if (! $invitation) {
            return back()->withErrors([
                'invitation' => 'Invalid or expired invitation link.',
            ])->withInput($request->only(['first_name', 'last_name', 'country', 'timezone']));
        }

        // Find the user
        $user = User::where('email', $invitation->email)->first();

        if (! $user) {
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
            return redirect()->route('gateway.invite', ['token' => $token])
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

        return redirect()->route('dashboard')->with('success', 'Welcome to '.$tenant->name.'! Your account has been set up successfully.');
    }

    /**
     * Block team mutations for users provisioned via an agency link (managed in Company Settings → Agencies).
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|null
     */
    protected function guardAgencyManagedUser(Request $request, Tenant $tenant, User $user)
    {
        if (! $user->isAgencyManagedMemberOf($tenant)) {
            return null;
        }

        $msg = 'Agency-managed members are controlled through Company Settings → Agencies.';

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => $msg, 'error' => 'agency_managed_user'], 422);
        }

        return back()->withErrors(['team' => $msg]);
    }

    /**
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    protected function teamRoleForbiddenResponse(Request $request, string $field, string $message)
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
        }

        return back()->withErrors([$field => $message]);
    }
}
