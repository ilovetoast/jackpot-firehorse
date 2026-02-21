<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ErrorController extends Controller
{
    /**
     * Display the no brand assignment error page.
     */
    public function noBrandAssignment(): Response
    {
        $tenant = app('tenant');
        $user = auth()->user();

        return Inertia::render('Errors/NoBrandAssignment', [
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
        ]);
    }

    /**
     * Display the user limit exceeded error page.
     */
    public function userLimitExceeded(): Response
    {
        $tenant = app('tenant');
        $user = auth()->user();

        // Get plan limit info
        $planService = app(\App\Services\PlanService::class);
        $limits = $planService->getPlanLimits($tenant);
        $currentUserCount = $tenant->users()->count();
        $maxUsers = $limits['max_users'] ?? PHP_INT_MAX;
        $planName = $planService->getCurrentPlan($tenant);

        return Inertia::render('Errors/UserLimitExceeded', [
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'plan_info' => [
                'current_user_count' => $currentUserCount,
                'max_users' => $maxUsers,
                'plan_name' => $planName,
            ],
        ]);
    }

    /**
     * Branded CDN access denied page (403).
     *
     * CloudFront custom error: 403 → /cdn-access-denied, response code 403, TTL 0.
     * Do not expose S3/AWS error details.
     */
    public function cdnAccessDenied(): Response
    {
        // Use app icon from public (no CDN) — CDN logo would fail when user hit 403
        $logoUrl = asset('icons/apple-touch-icon.png');

        return Inertia::render('Errors/CdnAccessDenied', [
            'logoUrl' => $logoUrl,
        ]);
    }

    /**
     * Display the no companies error page.
     * This is shown when a user has no companies/tenants associated with their account.
     */
    public function noCompanies(): Response
    {
        $user = auth()->user();

        return Inertia::render('Errors/NoCompanies', [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
        ]);
    }
}
