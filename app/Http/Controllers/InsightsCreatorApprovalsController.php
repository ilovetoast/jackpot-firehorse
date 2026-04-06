<?php

namespace App\Http\Controllers;

use App\Services\FeatureGate;
use App\Support\Roles\PermissionMap;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class InsightsCreatorApprovalsController extends Controller
{
    public function index(Request $request): Response|SymfonyResponse
    {
        $user = $request->user();
        $tenant = app('tenant');
        $brand = app()->bound('brand') ? app('brand') : null;

        if (! $user || ! $tenant || ! $brand) {
            abort(403);
        }

        $featureGate = app(FeatureGate::class);
        if (! $featureGate->creatorModuleEnabled($tenant)) {
            return redirect()->route('insights.review', ['workspace' => 'uploads']);
        }

        if (! $featureGate->approvalsEnabled($tenant)) {
            abort(403, 'Approval workflows are not available on your current plan.');
        }

        $brandRole = $user->getRoleForBrand($brand);
        if (! $brandRole || ! PermissionMap::canApproveAssets($brandRole)) {
            abort(403, 'You do not have permission to view the approval queue.');
        }

        return Inertia::render('Insights/CreatorApprovals');
    }
}
