<?php

namespace App\Http\Controllers;

use App\Enums\EventType;
use App\Mail\InviteMember;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        $members = $tenant->users()->orderBy('created_at')->get()->map(function ($member) use ($tenant, $firstUserId) {
            // Get role from pivot table
            $role = $member->pivot->role;
            
            // If no role in pivot, fall back to: first user (oldest) is owner, others are members
            if (empty($role)) {
                $isOwner = $firstUserId && $firstUserId === $member->id;
                $role = $isOwner ? 'owner' : 'member';
            }
            
            // Capitalize first letter for display
            $roleDisplay = ucfirst($role);
            
            // Get brand assignments for this user in this tenant
            $brandAssignments = $member->brands()
                ->where('tenant_id', $tenant->id)
                ->get()
                ->map(function ($brand) use ($member) {
                    $brandRole = $member->getRoleForBrand($brand) ?? 'member';
                    // Convert 'owner' to 'admin' for brand roles (owner is only for tenant-level)
                    if ($brandRole === 'owner') {
                        $brandRole = 'admin';
                        // Update the database to reflect this change
                        $member->setRoleForBrand($brand, 'admin');
                    }
                    return [
                        'id' => $brand->id,
                        'name' => $brand->name,
                        'role' => $brandRole,
                    ];
                });
            
            return [
                'id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'avatar_url' => $member->avatar_url,
                'role' => $roleDisplay,
                'role_value' => strtolower($role), // Raw role value for selectors
                'joined_at' => $member->pivot->created_at ?? $member->created_at,
                'brand_assignments' => $brandAssignments,
            ];
        });

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

        return Inertia::render('Companies/Team', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'members' => $members,
            'brands' => $brands,
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

        $validated = $request->validate([
            'role' => 'required|string|in:owner,admin,member,brand_manager',
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
        if (! $user->brands()->where('brands.id', $brand->id)->exists()) {
            abort(404, 'User is not assigned to this brand.');
        }

        $validated = $request->validate([
            'role' => 'required|string|in:admin,member,brand_manager',
        ]);

        // Prevent owner from being a brand role - convert to admin if owner is attempted
        $brandRole = $validated['role'];
        if ($brandRole === 'owner') {
            $brandRole = 'admin';
        }

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

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => 'nullable|string|in:owner,admin,member,brand_manager',
            'brands' => 'required|array|min:1',
            'brands.*.brand_id' => 'required|exists:brands,id',
            'brands.*.role' => 'required|string|in:admin,member,brand_manager',
        ]);

        // Prevent owner from being assigned as a brand role - convert to admin
        foreach ($validated['brands'] as &$brandAssignment) {
            if ($brandAssignment['role'] === 'owner') {
                $brandAssignment['role'] = 'admin';
            }
        }
        unset($brandAssignment);

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

        // Determine tenant role (use provided role or default to first brand role or 'member')
        $tenantRole = $validated['role'] ?? ($validated['brands'][0]['role'] ?? 'member');

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
