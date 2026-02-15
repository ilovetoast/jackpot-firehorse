<?php

namespace App\Http\Controllers;

use App\Services\MetadataAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Metadata Analytics Controller
 *
 * Phase 7: Provides read-only analytics dashboard for metadata quality and usage.
 */
class MetadataAnalyticsController extends Controller
{
    public function __construct(
        protected MetadataAnalyticsService $analyticsService
    ) {
    }

    /**
     * Display the metadata analytics dashboard.
     *
     * GET /app/analytics/metadata
     */
    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $brand = app('brand');

        if (!$tenant || !$brand) {
            abort(403, 'Tenant and brand must be selected.');
        }

        $user = Auth::user();
        if (!$user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            abort(403, 'You do not have permission to view analytics.');
        }
        $userRole = $user->getRoleForTenant($tenant);
        $isAdmin = in_array(strtolower($userRole ?? ''), ['owner', 'admin']);

        // Get filter parameters
        $brandId = $request->input('brand_id', $brand->id);
        $categoryId = $request->input('category_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $includeInternal = $isAdmin && $request->boolean('include_internal', false);

        // Ensure brand belongs to tenant
        if ($brandId && $brandId != $brand->id) {
            $brandExists = \App\Models\Brand::where('id', $brandId)
                ->where('tenant_id', $tenant->id)
                ->exists();

            if (!$brandExists) {
                abort(403, 'Brand does not belong to tenant.');
            }
        }

        // Get analytics
        $analytics = $this->analyticsService->getAnalytics(
            $tenant->id,
            $brandId,
            $categoryId,
            $startDate,
            $endDate,
            $includeInternal
        );

        return Inertia::render('Analytics/Metadata', [
            'analytics' => $analytics,
            'filters' => [
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'include_internal' => $includeInternal,
            ],
            'is_admin' => $isAdmin,
        ]);
    }

    /**
     * Get analytics data as JSON (for AJAX updates).
     *
     * GET /app/analytics/metadata/data
     */
    public function data(Request $request)
    {
        $tenant = app('tenant');
        $brand = app('brand');

        if (!$tenant || !$brand) {
            return response()->json(['error' => 'Tenant and brand must be selected.'], 403);
        }

        $user = Auth::user();
        if (!$user->hasPermissionForTenant($tenant, 'brand_settings.manage')) {
            return response()->json(['error' => 'You do not have permission to view analytics.'], 403);
        }
        $userRole = $user->getRoleForTenant($tenant);
        $isAdmin = in_array(strtolower($userRole ?? ''), ['owner', 'admin']);

        // Get filter parameters
        $brandId = $request->input('brand_id', $brand->id);
        $categoryId = $request->input('category_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $includeInternal = $isAdmin && $request->boolean('include_internal', false);

        // Ensure brand belongs to tenant
        if ($brandId && $brandId != $brand->id) {
            $brandExists = \App\Models\Brand::where('id', $brandId)
                ->where('tenant_id', $tenant->id)
                ->exists();

            if (!$brandExists) {
                return response()->json(['error' => 'Brand does not belong to tenant.'], 403);
            }
        }

        // Get analytics
        $analytics = $this->analyticsService->getAnalytics(
            $tenant->id,
            $brandId,
            $categoryId,
            $startDate,
            $endDate,
            $includeInternal
        );

        return response()->json($analytics);
    }
}
