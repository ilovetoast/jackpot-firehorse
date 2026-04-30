<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Support hub — entry to tickets and engineering queue (no business logic here).
 */
class AdminSupportHubController extends Controller
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
        $canStaff = $user->can('tickets.view_staff') || $user->can('tickets.view_any');
        $canEng = $user->can('tickets.view_engineering');
        if (! $isSiteOwner && ! $isSiteAdmin && ! $canStaff && ! $canEng) {
            abort(403, 'Only system administrators or support staff can open the Support hub.');
        }
    }

    public function index(): Response
    {
        $this->authorizeAdmin();

        return Inertia::render('Admin/Support/HubIndex');
    }
}
