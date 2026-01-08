<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Show the login page.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    /**
     * Handle login form submission.
     */
    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            $user = Auth::user();
            
            // Check if user is suspended
            if ($user->isSuspended()) {
                Auth::logout();
                return back()->withErrors([
                    'email' => 'Your account has been suspended. Please contact support for assistance.',
                ])->onlyInput('email');
            }
            
            $tenants = $user->tenants;

            // Auto-select tenant if user has tenants
            if ($tenants->isNotEmpty()) {
                // If user has only one tenant, auto-select it
                // Otherwise, select the first one (we'll add tenant switching later)
                $tenant = $tenants->first();
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

            // User has no tenants - redirect back with error
            Auth::logout();
            return back()->withErrors([
                'email' => 'Your account is not associated with any organization.',
            ])->onlyInput('email');
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Handle logout.
     */
    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
