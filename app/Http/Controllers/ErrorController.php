<?php

namespace App\Http\Controllers;

use App\Models\BrandInvitation;
use App\Models\TenantInvitation;
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

        $pendingWorkspaceInvites = [];
        if ($user) {
            $email = $user->email;

            $tenantRows = TenantInvitation::query()
                ->whereNull('accepted_at')
                ->whereRaw('LOWER(email) = LOWER(?)', [$email])
                ->with('tenant:id,name')
                ->orderByDesc('sent_at')
                ->get()
                ->unique('tenant_id')
                ->values()
                ->map(fn (TenantInvitation $inv) => [
                    'company_name' => $inv->tenant?->name ?? 'Workspace',
                    'brand_name' => null,
                    'is_creator_invite' => false,
                ])
                ->all();

            $brandRows = BrandInvitation::query()
                ->whereNull('accepted_at')
                ->whereRaw('LOWER(email) = LOWER(?)', [$email])
                ->with('brand:id,name')
                ->orderByDesc('sent_at')
                ->get()
                ->unique('brand_id')
                ->values()
                ->map(function (BrandInvitation $inv) {
                    $meta = is_array($inv->metadata) ? $inv->metadata : [];

                    return [
                        'company_name' => null,
                        'brand_name' => $inv->brand?->name ?? 'Brand',
                        'is_creator_invite' => (bool) ($meta['assign_prostaff_after_accept'] ?? false),
                    ];
                })
                ->all();

            $pendingWorkspaceInvites = array_values(array_merge($tenantRows, $brandRows));
        }

        return Inertia::render('Errors/NoCompanies', [
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'pending_workspace_invites' => $pendingWorkspaceInvites,
        ]);
    }

    /**
     * Display brand disabled by plan limit error page.
     * Shown when a user only has access to brands that are beyond the plan limit.
     */
    public function brandDisabled(): Response
    {
        $tenant = null;
        $tenantId = session('tenant_id');
        if ($tenantId) {
            $tenant = \App\Models\Tenant::find($tenantId);
            if ($tenant) {
                app()->instance('tenant', $tenant);
            }
        }

        $user = auth()->user();

        $planInfo = null;
        if ($tenant) {
            $planService = app(\App\Services\PlanService::class);
            $brandLimitInfo = $planService->getBrandLimitInfo($tenant);
            $planInfo = [
                'plan_name' => $planService->getCurrentPlan($tenant),
                'max_brands' => $brandLimitInfo['max_brands'],
                'total_brands' => $brandLimitInfo['total_brands'],
            ];
        }

        return Inertia::render('Errors/BrandDisabled', [
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'name' => $tenant->name,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
            'plan_info' => $planInfo,
        ]);
    }
}
