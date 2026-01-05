<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SiteAdminController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        // Only user ID 1 (Site Owner) can access
        $this->middleware(function ($request, $next) {
            if (Auth::id() !== 1) {
                abort(403, 'Only site owners can access this page.');
            }
            return $next($request);
        });
    }

    /**
     * Display the site admin dashboard.
     */
    public function index(): Response
    {
        $companies = Tenant::with(['brands', 'users'])->get();
        
        $stats = [
            'total_companies' => Tenant::count(),
            'total_brands' => Brand::count(),
            'total_users' => User::count(),
        ];

        return Inertia::render('Admin/Index', [
            'companies' => $companies->map(fn ($company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'brands_count' => $company->brands->count(),
                'users_count' => $company->users->count(),
                'brands' => $company->brands->map(fn ($brand) => [
                    'id' => $brand->id,
                    'name' => $brand->name,
                    'slug' => $brand->slug,
                    'is_default' => $brand->is_default,
                ]),
                'users' => $company->users->map(fn ($user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ]),
            ]),
            'stats' => $stats,
        ]);
    }
}
