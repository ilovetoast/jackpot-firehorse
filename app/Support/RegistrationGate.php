<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Controls public self-service signup (marketing CTAs + /gateway?mode=register + POST /gateway/register).
 * Invitation flows ({@see BrandGatewayController::invite}, invite_register) are not gated here.
 */
class RegistrationGate
{
    public const SESSION_KEY = 'registration_gate_ok';

    public static function isEnabled(): bool
    {
        return (bool) config('registration.enabled', true);
    }

    public static function bypassSecret(): string
    {
        return trim((string) config('registration.bypass_secret', ''));
    }

    /** Marketing + gateway "Create account" — only when public signup is enabled. */
    public static function isSignupAdvertised(): bool
    {
        return self::isEnabled();
    }

    /**
     * If registration is disabled and the query carries the correct bypass secret, set the session flag.
     * Safe to call on every gateway request.
     */
    public static function maybeGrantBypassFromRequest(Request $request): void
    {
        if (self::isEnabled()) {
            return;
        }

        $secret = self::bypassSecret();
        if ($secret === '') {
            return;
        }

        $key = (string) $request->query('registration_key', '');
        if ($key !== '' && hash_equals($secret, $key)) {
            $request->session()->put(self::SESSION_KEY, true);
        }
    }

    /**
     * True when public signup is allowed: either globally enabled, or disabled with a valid bypass session.
     */
    public static function allowsPublicSignup(Request $request): bool
    {
        if (self::isEnabled()) {
            return true;
        }

        if (self::bypassSecret() === '') {
            return false;
        }

        return (bool) $request->session()->get(self::SESSION_KEY, false);
    }

    public static function forgetBypass(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
