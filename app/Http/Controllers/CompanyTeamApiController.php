<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\TenantAgency;
use App\Models\User;
use App\Services\TenantAgencyService;
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
    public function __construct(
        protected TenantAgencyService $tenantAgencyService
    ) {}

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

        $perPage = min(200, max(1, (int) $request->get('per_page', 25)));

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
            ->when($request->filled('role'), function ($q) use ($request) {
                $role = strtolower($request->role);
                if ($role === 'owner' || $role === 'admin' || $role === 'member') {
                    $q->wherePivot('role', $role);
                }
            })
            ->orderByRaw('CASE WHEN COALESCE(tenant_user.is_agency_managed, 0) = 1 THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN tenant_user.agency_tenant_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('tenant_user.agency_tenant_id')
            ->orderBy('users.created_at');

        $paginator = $query->paginate($perPage);

        $collection = $paginator->getCollection();
        $agencyIds = $collection->pluck('pivot.agency_tenant_id')->filter()->unique()->values();
        $agencyNames = \App\Models\Tenant::whereIn('id', $agencyIds)->pluck('name', 'id');

        $data = $collection->map(function ($member) use ($firstUserId, $agencyNames) {
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

            $agencyTenantId = $member->pivot->agency_tenant_id ? (int) $member->pivot->agency_tenant_id : null;

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
                'is_agency_managed' => (bool) ($member->pivot->is_agency_managed ?? false),
                'agency_tenant_id' => $agencyTenantId,
                'agency_tenant_name' => $agencyTenantId ? ($agencyNames[$agencyTenantId] ?? null) : null,
            ];
        });

        $linkedAgencies = TenantAgency::query()
            ->where('tenant_id', $tenant->id)
            ->with(['agencyTenant:id,name,slug,is_agency'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (TenantAgency $row) => $row->toApiArray())
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'linked_agencies' => $linkedAgencies,
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

        if ($user->isAgencyManagedMemberOf($tenant)) {
            return response()->json([
                'error' => 'This user is managed by an agency link. Change access in Company Settings → Agencies, or remove the agency.',
                'code' => 'agency_managed_user',
            ], 422);
        }

        $validated = $request->validate([
            'brand_id' => 'required|exists:brands,id',
            'role' => 'required|string|in:'.implode(',', RoleRegistry::brandRoles()),
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

    /**
     * POST /api/companies/users/{user}/agency-managed
     *
     * Convert a direct member to agency-managed for a linked agency (single membership row).
     */
    public function convertToAgencyManaged(Request $request, User $user): JsonResponse
    {
        $tenant = app('tenant');
        if (! $tenant) {
            return response()->json(['error' => 'No company selected'], 400);
        }

        $authUser = Auth::user();
        if (! $authUser->canForContext('team.manage', $tenant, null)) {
            return response()->json(['error' => 'Access denied'], 403);
        }

        $validated = $request->validate([
            'agency_tenant_id' => 'required|integer|exists:tenants,id',
        ]);

        if (! TenantAgency::where('tenant_id', $tenant->id)->where('agency_tenant_id', $validated['agency_tenant_id'])->exists()) {
            return response()->json(['error' => 'That agency is not linked to this company.'], 422);
        }

        try {
            $this->tenantAgencyService->convertDirectMemberToAgencyManaged(
                $tenant,
                $user,
                (int) $validated['agency_tenant_id']
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json(['success' => true]);
    }
}
