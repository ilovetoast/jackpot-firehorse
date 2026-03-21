<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantAgency;
use App\Services\TenantAgencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantAgencyController extends Controller
{
    public function __construct(
        protected TenantAgencyService $tenantAgencyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            abort(403);
        }
        $this->authorizeTenant($user, $tenant);

        $rows = TenantAgency::query()
            ->where('tenant_id', $tenant->id)
            ->with(['agencyTenant:id,name,slug,is_agency'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (TenantAgency $row) => $row->toApiArray());

        return response()->json(['agencies' => $rows]);
    }

    public function searchAgencies(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            abort(403);
        }
        $this->authorizeTenant($user, $tenant);

        $q = trim((string) $request->get('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['tenants' => []]);
        }

        $already = TenantAgency::where('tenant_id', $tenant->id)->pluck('agency_tenant_id');

        $tenants = Tenant::query()
            ->where('is_agency', true)
            ->where('id', '!=', $tenant->id)
            ->whereNotIn('id', $already)
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$q.'%');
            })
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'slug']);

        return response()->json(['tenants' => $tenants]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            abort(403);
        }
        $this->authorizeTenant($user, $tenant);

        $validated = $request->validate([
            'agency_tenant_id' => 'required|integer|exists:tenants,id',
            'role' => 'required|string|max:64',
            'brand_assignments' => 'nullable|array',
            'brand_assignments.*.brand_id' => 'required|integer',
            'brand_assignments.*.role' => 'required|string|max:64',
        ]);

        $agencyTenant = Tenant::findOrFail($validated['agency_tenant_id']);

        $brandAssignments = $validated['brand_assignments'] ?? [];
        foreach ($brandAssignments as $ba) {
            $exists = \App\Models\Brand::where('id', $ba['brand_id'])->where('tenant_id', $tenant->id)->exists();
            if (! $exists) {
                return response()->json(['message' => 'Invalid brand for this company.'], 422);
            }
        }

        $record = $this->tenantAgencyService->attach(
            $tenant,
            $agencyTenant,
            strtolower($validated['role']),
            $brandAssignments,
            $user
        );

        $record->load('agencyTenant:id,name,slug,is_agency');

        return response()->json([
            'success' => true,
            'tenant_agency' => $record->toApiArray(),
        ], 201);
    }

    public function destroy(TenantAgency $tenantAgency): JsonResponse
    {
        $tenant = app('tenant');
        $user = Auth::user();
        if (! $tenant || ! $user) {
            abort(403);
        }
        $this->authorizeTenant($user, $tenant);

        if ((int) $tenantAgency->tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        $this->tenantAgencyService->detach($tenantAgency);

        return response()->json(['success' => true]);
    }

    protected function authorizeTenant(\App\Models\User $user, Tenant $tenant): void
    {
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not belong to this company.');
        }
        if (! $user->hasPermissionForTenant($tenant, 'team.manage')) {
            abort(403, 'You do not have permission to manage team and agency links.');
        }
    }
}
