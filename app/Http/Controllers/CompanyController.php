<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function __construct(
        protected BillingService $billingService
    ) {
    }

    /**
     * Show the company management page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $companies = $user->tenants;
        $currentCompanyId = session('tenant_id');

        return Inertia::render('Companies/Index', [
            'companies' => $companies->map(function ($company) use ($currentCompanyId) {
                $currentPlan = $this->billingService->getCurrentPlan($company);
                $subscription = $company->subscription('default');
                
                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'timezone' => $company->timezone ?? 'UTC',
                    'is_active' => $company->id == $currentCompanyId,
                    'billing' => [
                        'current_plan' => $currentPlan,
                        'subscription_status' => $subscription ? $subscription->stripe_status : 'none',
                    ],
                ];
            }),
        ]);
    }

    /**
     * Switch to a different company.
     */
    public function switch(Request $request, Tenant $tenant)
    {
        $user = Auth::user();

        // Verify user belongs to this company
        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        $defaultBrand = $tenant->defaultBrand;
        
        if (! $defaultBrand) {
            abort(500, 'Tenant must have at least one brand');
        }
        
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand->id,
        ]);

        return redirect()->intended('/app/dashboard');
    }

    /**
     * Show the company settings page.
     */
    public function settings()
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You must select a company to view settings.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You do not have access to this company.',
            ]);
        }

        // Check if user has permission to view company settings
        // Check via tenant role permissions
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            abort(403, 'Only administrators and owners can access company settings.');
        }

        // Get billing information
        $currentPlan = $this->billingService->getCurrentPlan($tenant);
        $subscription = $tenant->subscription('default');
        $teamMembersCount = $tenant->users()->count();
        $brandsCount = $tenant->brands()->count();

        return Inertia::render('Companies/Settings', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone ?? 'UTC',
            ],
            'billing' => [
                'current_plan' => $currentPlan,
                'subscription_status' => $subscription ? $subscription->stripe_status : 'none',
            ],
            'team_members_count' => $teamMembersCount,
            'brands_count' => $brandsCount,
        ]);
    }

    /**
     * Update the company settings.
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();
        $tenant = app('tenant'); // Get the active tenant from middleware

        if (! $tenant) {
            return redirect()->route('companies.index')->withErrors([
                'settings' => 'You must select a company to update settings.',
            ]);
        }

        if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
            abort(403, 'You do not have access to this company.');
        }

        // Check if user has permission to view company settings (required for update too)
        // Check via tenant role permissions
        if (! $user->hasPermissionForTenant($tenant, 'company_settings.view')) {
            abort(403, 'Only administrators and owners can update company settings.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'timezone' => 'required|string|max:255',
        ]);

        $tenant->update($validated);

        return redirect()->route('companies.settings')->with('success', 'Company settings updated successfully.');
    }
}
