<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SignupController extends Controller
{
    /**
     * Show the signup page.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/Signup');
    }

    /**
     * Handle signup form submission.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'company_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Create the user
        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        // Create the tenant (company)
        $tenant = Tenant::create([
            'name' => $validated['company_name'],
            'slug' => Str::slug($validated['company_name']),
        ]);

        // Attach user to tenant as owner
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Get the default brand (created automatically by Tenant boot method)
        $defaultBrand = $tenant->defaultBrand;

        if (! $defaultBrand) {
            abort(500, 'Tenant must have at least one brand');
        }
        
        // Ensure owner is automatically connected to default brand
        $defaultBrand->users()->syncWithoutDetaching([
            $user->id => ['role' => 'admin'] // Owners have admin role on their default brand
        ]);

        // Log the user in
        Auth::login($user);

        // Set session for tenant and brand
        $request->session()->regenerate();
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand->id,
        ]);

        return redirect()->intended('/app/dashboard');
    }
}
