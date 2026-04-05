<?php

namespace App\Http\Controllers\Prostaff;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Exceptions\CreatorModuleInactiveException;
use App\Services\Prostaff\EnsureCreatorModuleEnabled;
use App\Services\Prostaff\GetProstaffDamFilterOptions;
use App\Services\Prostaff\GetProstaffDashboardData;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProstaffDashboardController extends Controller
{
    /**
     * GET /app/api/brands/{brand}/prostaff/dashboard
     */
    public function index(Request $request, Brand $brand): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        $this->authorize('view', $brand);

        if ($blocked = $this->json403UnlessCreatorModule($tenant)) {
            return $blocked;
        }

        if (! $this->userCanViewManagerProstaffDashboard($user, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to view the prostaff dashboard.'], 403);
        }

        $rows = app(GetProstaffDashboardData::class)->managerDashboardRows($brand);

        return response()->json($rows);
    }

    /**
     * GET /app/api/prostaff/me?brand_id=
     */
    public function me(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
        ]);

        $brand = Brand::query()->findOrFail($validated['brand_id']);
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        $this->authorize('view', $brand);

        if ($blocked = $this->json403UnlessCreatorModule($tenant)) {
            return $blocked;
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->isProstaffForBrand($brand)) {
            return response()->json(['error' => 'You are not an active prostaff member for this brand.'], 403);
        }

        $payload = app(GetProstaffDashboardData::class)->currentUserDashboard($user, $brand);

        return response()->json($payload);
    }

    /**
     * GET /app/api/brands/{brand}/prostaff/options
     *
     * @return JsonResponse list<array{user_id: int, name: string}>
     */
    public function filterOptions(Brand $brand): JsonResponse
    {
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            return response()->json(['error' => 'Brand does not belong to this tenant.'], 403);
        }

        $this->authorize('view', $brand);

        if ($empty = $this->jsonEmptyUnlessCreatorModule($tenant)) {
            return $empty;
        }

        $options = app(GetProstaffDamFilterOptions::class)->activeMemberOptionsForBrand($brand);

        return response()->json($options);
    }

    private function json403UnlessCreatorModule(Tenant $tenant): ?JsonResponse
    {
        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (CreatorModuleInactiveException $e) {
            return response()->json($e->clientPayload(), 403);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 403);
        }

        return null;
    }

    /**
     * @return JsonResponse|null Empty JSON array when module is off; null to continue.
     */
    private function jsonEmptyUnlessCreatorModule(Tenant $tenant): ?JsonResponse
    {
        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (DomainException) {
            return response()->json([]);
        }

        return null;
    }

    private function userCanViewManagerProstaffDashboard(User $user, Tenant $tenant, Brand $brand): bool
    {
        $membership = $user->activeBrandMembership($brand);
        if ($membership === null) {
            return false;
        }

        $brandRole = $membership['role'];
        $tenantRole = $user->getRoleForTenant($tenant);
        $isTenantOwnerOrAdmin = in_array($tenantRole, ['owner', 'admin'], true);
        $isBrandManager = $brandRole === 'brand_manager';
        $isContributor = $brandRole === 'contributor';

        if ($isContributor && ! $isTenantOwnerOrAdmin && ! $isBrandManager) {
            return false;
        }

        return true;
    }
}
