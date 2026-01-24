<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TenantPortalController extends Controller
{
    /**
     * Show the tenant portal/landing page.
     * This is shown when users visit a company subdomain like: company-name.jackpot.local
     */
    public function show(Request $request): Response
    {
        // Get tenant from middleware (should already be resolved)
        $tenant = $request->get('subdomain_tenant');
        
        if (!$tenant) {
            return Inertia::render('TenantPortal/NotFound', [
                'slug' => 'unknown',
                'domain' => $request->getHost(),
            ]);
        }
        
        // Check if user is already authenticated
        $user = Auth::user();
        $userBelongsToTenant = false;
        $userRole = null;
        
        if ($user) {
            $userBelongsToTenant = $user->tenants()->where('tenants.id', $tenant->id)->exists();
            if ($userBelongsToTenant) {
                $userRole = $user->getRoleForTenant($tenant);
            }
        }
        
        // If user is authenticated and belongs to this tenant, redirect them to the main app
        if ($user && $userBelongsToTenant) {
            // Set the tenant in session and redirect to dashboard
            session([
                'tenant_id' => $tenant->id,
                'brand_id' => $tenant->defaultBrand?->id,
            ]);
            
            return redirect(config('app.url') . '/app/dashboard')
                ->with('success', "Welcome to {$tenant->name}!");
        }
        
        // Get tenant's default brand for branding
        $defaultBrand = $tenant->defaultBrand;
        
        return Inertia::render('TenantPortal/Landing', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'brand' => $defaultBrand ? [
                'id' => $defaultBrand->id,
                'name' => $defaultBrand->name,
                'logo_url' => $defaultBrand->logo_url,
                'nav_color' => $defaultBrand->nav_color,
                'icon_font_name' => $defaultBrand->icon_font_name,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'belongs_to_tenant' => $userBelongsToTenant,
                'role' => $userRole,
            ] : null,
            'subdomain_url' => $request->getSchemeAndHttpHost(),
            'main_app_url' => config('app.url'),
        ]);
    }
    
    /**
     * Handle tenant portal login redirect.
     */
    public function login(Request $request)
    {
        $tenantSlug = $request->get('slug') ?? $request->get('tenant_slug');
        $tenant = Tenant::where('slug', $tenantSlug)->first();
        
        if (!$tenant) {
            abort(404, 'Company not found');
        }
        
        // Store the intended tenant in session before redirecting to login
        session(['intended_tenant' => $tenant->id]);
        
        // Redirect to main app login with a special parameter
        return redirect(config('app.url') . '/login?tenant=' . $tenant->slug)
            ->with('info', "Please log in to access {$tenant->name}");
    }
}