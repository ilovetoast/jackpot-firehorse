<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use App\Support\RegistrationGate;
use Illuminate\Http\Request;
use App\Mail\EmailVerification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class SignupController extends Controller
{
    /**
     * Legacy URL — registration UI is on /gateway?mode=register (POST /gateway/register).
     */
    public function show(Request $request): RedirectResponse
    {
        RegistrationGate::maybeGrantBypassFromRequest($request);

        if (! RegistrationGate::allowsPublicSignup($request) && RegistrationGate::bypassSecret() === '') {
            return redirect()->route('gateway', ['mode' => 'login'])
                ->with('error', 'Signup is not available on this environment.');
        }

        $query = array_filter([
            'mode' => 'register',
            'company' => $request->query('company'),
            'tenant' => $request->query('tenant'),
            'brand' => $request->query('brand'),
        ], fn ($v) => $v !== null && $v !== '');

        return redirect()->route('gateway', $query);
    }

    /**
     * Handle signup form submission.
     */
    public function store(Request $request)
    {
        RegistrationGate::maybeGrantBypassFromRequest($request);

        if (! RegistrationGate::allowsPublicSignup($request)) {
            return redirect()->route('gateway', ['mode' => 'login'])
                ->with('error', 'Signup is not available on this environment.');
        }

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
            $user->id => ['role' => 'admin'], // Owners have admin role on their default brand
        ]);

        // Send verification email
        $verifyUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())]
        );
        Mail::to($user->email)->send(new EmailVerification($verifyUrl));

        // Log the user in
        Auth::login($user);

        // Set session for tenant and brand
        $request->session()->regenerate();
        session([
            'tenant_id' => $tenant->id,
            'brand_id' => $defaultBrand->id,
        ]);

        return $this->redirectToIntendedApp('/app/overview');
    }
}
