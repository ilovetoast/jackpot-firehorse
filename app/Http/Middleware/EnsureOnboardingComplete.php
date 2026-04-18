<?php

namespace App\Http\Middleware;

use App\Services\OnboardingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    public function __construct(
        protected OnboardingService $onboarding,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return $next($request);
        }

        $path = $request->path();
        $exemptPrefixes = [
            'app/onboarding',
            'app/verify-email',
            'app/overview',
            'app/profile',
            'app/companies',
            'app/admin',
            'email/verify',
            'email/resend',
            'logout',
        ];
        foreach ($exemptPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($request);
            }
        }

        // Activation gate: redirect to onboarding when not yet activated AND not dismissed.
        // After "Finish later", dismissed_at is set and the middleware stops blocking.
        $brand = app()->bound('brand') ? app('brand') : null;

        // Email verification gate — only blocks account owners and members of
        // free-plan tenants (bot-signup / free-storage abuse mitigation).
        if ($this->onboarding->shouldShowVerificationGate($user, $brand)) {
            return $request->header('X-Inertia')
                ? inertia()->location('/app/verify-email')
                : redirect('/app/verify-email');
        }

        if (! $brand) {
            return $next($request);
        }

        if ($this->onboarding->isBlocking($brand)) {
            return $request->header('X-Inertia')
                ? inertia()->location('/app/onboarding')
                : redirect('/app/onboarding');
        }

        return $next($request);
    }
}
