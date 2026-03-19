<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\User;
use App\Support\Roles\RoleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Company Team Management API — paginated users, add brand access.
 *
 * UI + orchestration layer. Does NOT modify brand-level permission logic.
 */
class CompanyTeamApiController extends Controller
{
    /**
     * GET /api/companies/users — paginated users with brand roles.
     *
     * Query params: page, search, brand_id, role
     */
    public function users(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant) {
            return response()->json(['error' => 'No company selected'], 400);
        }

        $user = Auth::user();
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return response()->json(['error' => 'Access denied'], 403);
        }
        if (! $user->canForContext('team.manage', $tenant, null)) {
            return response()->json(['error' => 'Only administrators and owners can access team management'], 403);
        }

        $tenantBrandIds = $tenant->brands()->pluck('id')->toArray();
        $firstUserId = $tenant->users()->orderBy('created_at')->first()?->id;

        $query = $tenant->users()
            ->with([
                'brands' => fn ($q) => $q->whereIn('brands.id', $tenantBrandIds)->wherePivotNull('removed_at'),
            ])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where(function ($q) use ($term) {
                    $q->where('users.first_name', 'like', "%{$term}%")
                        ->orWhere('users.last_name', 'like', "%{$term}%")
                        ->orWhere('users.email', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('brand_id'), function ($q) use ($request) {
                $q->whereHas('brands', fn ($b) => $b->where('brands.id', $request->brand_id)->wherePivotNull('removed_at'));
            })
            ->when($request->filled('role'), function ($q) use ($request, $tenant) {
                $role = strtolower($request->role);
                if ($role === 'owner' || $role === 'admin' || $role === 'member') {
                    $q->wherePivot('role', $role);
                }
            });

        $paginator = $query->orderBy('users.created_at')->paginate(25);

        $data = $paginator->getCollection()->map(function ($member) use ($tenant, $firstUserId, $tenantBrandIds) {
            $role = $member->pivot->role ?? null;
            if (empty($role)) {
                $role = ($firstUserId && $firstUserId === $member->id) ? 'owner' : 'member';
            }

            $brandRoles = collect($member->brands)->map(function ($brand) {
                return [
                    'brand_id' => $brand->id,
                    'brand_name' => $brand->name,
                    'role' => $brand->pivot->role ?? 'viewer',
                ];
            })->values()->toArray();

            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'avatar_url' => $member->avatar_url,
                'company_role' => strtolower($role),
                'brand_roles' => $brandRoles,
                'joined_at' => $member->pivot->created_at ?? $member->created_at,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * POST /api/companies/users/{user}/brands — add brand access.
     */
    public function addBrandAccess(Request $request, User $user): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant) {
            return response()->json(['error' => 'No company selected'], 400);
        }

        $authUser = Auth::user();
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $tenant->users()->where('users.id', $user->id)->firstOrFail();

        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'role' => 'required|string|in:' . implode(',', RoleRegistry::brandRoles()),
        ]);

        $brand = Brand::findOrFail($validated['brand_id']);
        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this company'], 422);
        }

        $existing = DB::table('brand_user')
            ->where('user_id', $user->id)
            ->where('brand_id', $brand->id)
            ->whereNull('removed_at')
            ->exists();

        if ($existing) {
            return response()->json(['error' => 'User already has access to this brand'], 422);
        }

        $user->setRoleForBrand($brand, $validated['role']);

        return response()->json([
            'success' => true,
            'brand_role' => [
                'brand_id' => $brand->id,
                'brand_name' => $brand->name,
                'role' => $validated['role'],
            ],
        ]);
    }
}
