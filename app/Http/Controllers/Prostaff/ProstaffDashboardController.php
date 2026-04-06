<?php

namespace App\Http\Controllers\Prostaff;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Exceptions\CreatorModuleInactiveException;
use App\Services\FeatureGate;
use App\Services\Prostaff\EnsureCreatorModuleEnabled;
use App\Services\Prostaff\GetProstaffDamFilterOptions;
use App\Services\Prostaff\GetProstaffDashboardData;
use App\Services\Prostaff\ResolveCreatorsDashboardAccess;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
            'creatorModuleEnabled' => app(FeatureGate::class)->creatorModuleEnabled($tenant),
            'creatorApproversConfigured' => $brand->hasConfiguredCreatorApprovers(),
        ]);
    }

    /**
     * GET /app/brands/{brand}/creators/{user}
     */
    public function creatorPage(Request $request, Brand $brand, User $creator): Response|RedirectResponse
    {
        /** @var User $authUser */
        $authUser = Auth::user();
        $tenant = app('tenant');

        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this workspace.');
        }

        $this->authorize('view', $brand);

        $access = app(ResolveCreatorsDashboardAccess::class);
        if (! $access->canManage($authUser, $tenant, $brand)) {
            if ((int) $authUser->id !== (int) $creator->id || ! $authUser->isProstaffForBrand($brand)) {
                abort(403, 'You do not have permission to view this creator profile.');
            }
        }

        if (! $creator->isProstaffForBrand($brand)) {
            abort(404);
        }

        // Do not require belongsToTenant() here: a row can still appear on the creators dashboard while the
        // tenant_user link was removed or is inconsistent; prostaff membership + brand tenant check above is enough.

        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (CreatorModuleInactiveException $e) {
            $fallback = $access->canManage($authUser, $tenant, $brand)
                ? redirect()->route('brands.creators', $brand)
                : redirect()->route('overview');

            return $fallback->with('warning', $e->getMessage() ?: 'Creator module is not active.');
        } catch (DomainException $e) {
            $fallback = $access->canManage($authUser, $tenant, $brand)
                ? redirect()->route('brands.creators', $brand)
                : redirect()->route('overview');

            return $fallback->with('warning', $e->getMessage());
        }

        $service = app(GetProstaffDashboardData::class);
        $performance = $service->creatorDashboardRowForUser($brand, (int) $creator->id);
        if ($performance === null) {
            abort(404);
        }

        $rejections = $service->rejectedProstaffUploadsForUser($brand, (int) $creator->id);
        $awaitingBrandReviewCount = $service->pendingProstaffApprovalCountForUser($brand, (int) $creator->id);
        $membership = $creator->activeProstaffMembership($brand);
        if ($membership === null) {
            abort(404);
        }

        return Inertia::render('Prostaff/CreatorProfile', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
            'creator' => [
                'id' => $creator->id,
                'name' => $creator->name,
                'email' => $creator->email,
            ],
            'performance' => $performance,
            'rejections' => $rejections,
            'awaiting_brand_review_count' => $awaitingBrandReviewCount,
            'canManageCreators' => $access->canManage($authUser, $tenant, $brand),
            'membership' => [
                'id' => $membership->id,
                'target_uploads' => $membership->target_uploads,
                'period_type' => $membership->period_type ?? 'month',
            ],
        ]);
    }

    /**
     * Cinematic self-service creator progress (overview quick link). Active brand from session.
     */
    public function creatorSelfProgress(Request $request): Response|RedirectResponse
    {
        /** @var User $authUser */
        $authUser = Auth::user();
        $tenant = app('tenant');
        $brand = app('brand');

        if (! $tenant || ! $brand) {
            return redirect()->route('assets.index')
                ->with('warning', 'Select a company and brand to view creator progress.');
        }

        if (! $authUser->isProstaffForBrand($brand)) {
            return redirect()->route('overview')
                ->with('warning', 'Creator progress is only available when you are enrolled as a creator on this brand.');
        }

        $this->authorize('view', $brand);

        try {
            app(EnsureCreatorModuleEnabled::class)->assertEnabled($tenant);
        } catch (CreatorModuleInactiveException $e) {
            return redirect()->route('overview')
                ->with('warning', $e->getMessage() ?: 'Creator module is not active.');
        } catch (DomainException $e) {
            return redirect()->route('overview')->with('warning', $e->getMessage());
        }

        $service = app(GetProstaffDashboardData::class);
        $performance = $service->creatorDashboardRowForUser($brand, (int) $authUser->id);
        if ($performance === null) {
            abort(404);
        }

        $rejections = $service->rejectedProstaffUploadsForUser($brand, (int) $authUser->id);
        $awaitingBrandReviewCount = $service->pendingProstaffApprovalCountForUser($brand, (int) $authUser->id);
        $pipeline = $service->pipelineCountsForProstaffUser($brand, (int) $authUser->id);
        $peerComparison = $service->anonymizedVolumeComparison($brand, (int) $authUser->id);

        $membership = $authUser->activeProstaffMembership($brand);
        if ($membership === null) {
            abort(404);
        }

        $access = app(ResolveCreatorsDashboardAccess::class);

        return Inertia::render('Overview/CreatorProgress', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'slug' => $brand->slug,
                'logo_path' => $brand->logo_path,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
            'creator' => [
                'id' => $authUser->id,
                'name' => $authUser->name,
                'email' => $authUser->email,
            ],
            'performance' => $performance,
            'rejections' => $rejections,
            'awaiting_brand_review_count' => $awaitingBrandReviewCount,
            'pipeline' => $pipeline,
            'peer_comparison' => $peerComparison,
            'canManageCreators' => $access->canManage($authUser, $tenant, $brand),
            'membership' => [
                'id' => $membership->id,
                'target_uploads' => $membership->target_uploads,
                'period_type' => $membership->period_type ?? 'month',
            ],
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

        $service = app(GetProstaffDashboardData::class);
        $active = $service->managerDashboardRows($brand);
        if (! $access->canManage($user, $tenant, $brand)) {
            $active = array_values(array_filter(
                $active,
                static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === (int) $user->id
            ));
        }

        $pendingInvitations = $access->canManage($user, $tenant, $brand)
            ? $service->pendingCreatorInvitesForBrand($brand)
            : [];

        return response()->json([
            'active' => $active,
            'pending_invitations' => $pendingInvitations,
        ]);
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
            // 200 (not 403): overview and other shells poll this for every user — avoids console noise and error UX.
            return response()->json([
                'eligible' => false,
                'prostaff' => false,
            ]);
        }

        $payload = app(GetProstaffDashboardData::class)->currentUserDashboard($user, $brand);

        return response()->json(array_merge($payload, [
            'eligible' => true,
            'prostaff' => true,
        ]));
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
