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
}
