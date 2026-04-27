<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ClientOnboardingCatalogReviewService;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only review of default category templates and field visibility for new accounts.
 */
class AdminOnboardingCatalogController extends Controller
{
    protected function checkSiteOwnerAccess(): void
    {
        $user = Auth::user();
        if (! $user || ($user->id !== 1 && ! $user->can('site owner') && ! $user->can('site admin'))) {
            abort(403, 'Only site owners can view onboarding defaults review.');
        }
    }

    public function show(ClientOnboardingCatalogReviewService $service): Response
    {
        $this->checkSiteOwnerAccess();

        $payload = $service->buildPayload();

        return Inertia::render('Admin/Onboarding/DefaultsReview', $payload);
    }
}
