<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AssetProcessingIssuesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Full-width report for assets in a failed-thumbnail or promotion-failed state (same cohort as system status).
 */
class AdminAssetProcessingIssuesController extends Controller
{
    public function index(Request $request, AssetProcessingIssuesService $issues): Response
    {
        $this->authorizeSiteAdmin();

        $limit = min(500, max(10, (int) $request->query('limit', 150)));

        return Inertia::render('Admin/AssetProcessingIssues/Index', [
            'assets' => $issues->list($limit),
            'limit' => $limit,
        ]);
    }

    protected function authorizeSiteAdmin(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403);
        }
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles, true) || in_array('site_owner', $siteRoles, true);
        if (! $isSiteOwner && ! $isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }
    }
}
