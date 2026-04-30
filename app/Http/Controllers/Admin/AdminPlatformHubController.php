<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform Configuration hub — links to existing admin tools (no business logic here).
 */
class AdminPlatformHubController extends Controller
{
    protected function authorizeAdmin(): void
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

    public function index(): Response
    {
        $this->authorizeAdmin();

        return Inertia::render('Admin/Platform/Index');
    }
}
