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
use App\Services\Prostaff\ResolveCreatorsDashboardAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProstaffDashboardController extends Controller
{
    /**
     * GET /app/brands/{brand}/creators — Inertia shell; dashboard rows load client-side from JSON API.
     */
    public function page(Request $request, Brand $brand): Response
    {
        /** @var User $user */
        $user = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this workspace.');
        }

        $this->authorize('view', $brand);

        $access = app(ResolveCreatorsDashboardAccess::class);
        if (! $access->canView($user, $tenant, $brand)) {
            abort(403, 'You do not have permission to view the Creators dashboard.');
        }

        return Inertia::render('Prostaff/CreatorsDashboard', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
            'canManageCreators' => $access->canManage($user, $tenant, $brand),
        ]);
    }

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

        $access = app(ResolveCreatorsDashboardAccess::class);
        if (! $access->canView($user, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to view the prostaff dashboard.'], 403);
        }

        $rows = app(GetProstaffDashboardData::class)->managerDashboardRows($brand);
        if (! $access->canManage($user, $tenant, $brand)) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === (int) $user->id
            ));
        }

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

        /** @var User $user */
        $user = Auth::user();
        if (! app(ResolveCreatorsDashboardAccess::class)->canManage($user, $tenant, $brand)) {
            return response()->json(['error' => 'You do not have permission to load prostaff filter options.'], 403);
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

}
