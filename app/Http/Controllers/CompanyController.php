<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    /**
     * Show the company management page.
     */
    public function index(): Response
    {
        $user = Auth::user();
        $companies = $user->tenants;
        $currentCompanyId = session('tenant_id');

        return Inertia::render('Companies/Index', [
            'companies' => $companies->map(fn ($company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'is_active' => $company->id == $currentCompanyId,
            ]),
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

        return redirect()->intended('/dashboard');
    }
}
