<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            
            return [
                'id' => $member->id,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'email' => $member->email,
                'role' => $roleDisplay,
                'joined_at' => $member->pivot->created_at ?? $member->created_at,
            ];
        });

        // Get plan limits
        $planLimits = $this->planService->getPlanLimits($tenant);
        $maxUsers = $planLimits['max_users'] ?? PHP_INT_MAX;
        $currentUserCount = $members->count();
        $userLimitReached = $currentUserCount >= $maxUsers;

        return Inertia::render('Companies/Team', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ],
            'members' => $members,
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

        // Remove user from tenant
        $tenant->users()->detach($user->id);

        return redirect()->route('companies.team')->with('success', 'Team member removed successfully.');
    }
}
