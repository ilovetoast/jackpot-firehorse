<?php

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;

if (! function_exists('privacy_region_country_code')) {
    /**
     * Cloudflare (or compatible) country hint — ISO 3166-1 alpha-2 or null.
     */
    function privacy_region_country_code(?Request $request = null): ?string
    {
        $request = $request ?? request();
        $cf = $request->header('CF-IPCountry');
        if (is_string($cf) && strlen($cf) === 2) {
            return strtoupper($cf);
        }

        return null;
    }
}

if (! function_exists('privacy_needs_strict_opt_in')) {
    /**
     * True when the country requires opt-in for non-essential cookies (ePrivacy / GDPR).
     */
    function privacy_needs_strict_opt_in(?string $iso2): bool
    {
        if ($iso2 === null || strlen($iso2) !== 2) {
            return false;
        }

        $codes = config('privacy.strict_opt_in_countries', []);

        return in_array(strtoupper($iso2), $codes, true);
    }
}

if (! function_exists('privacy_global_gpc')) {
    /**
     * Global Privacy Control (W3C). When true, analytics and marketing should be off.
     */
    function privacy_global_gpc(?Request $request = null): bool
    {
        $request = $request ?? request();

        return $request->header('Sec-GPC') === '1';
    }
}

if (! function_exists('jackpot_privacy_bootstrap_array')) {
    /**
     * Inline privacy flags for app.blade.php (no container resolution — survives partial deploys).
     *
     * @return array{cookie_policy_version: string, strict_opt_in_region: bool, gpc: bool}
     */
    function jackpot_privacy_bootstrap_array(Request $request): array
    {
        $country = privacy_region_country_code($request);

        return [
            'cookie_policy_version' => config('privacy.cookie_policy_version', '1'),
            'strict_opt_in_region' => privacy_needs_strict_opt_in($country),
            'gpc' => privacy_global_gpc($request),
        ];
    }
}

if (! function_exists('impersonation')) {
    function impersonation(): ImpersonationService
    {
        return app(ImpersonationService::class);
    }
}

if (! function_exists('acting_user')) {
    /**
     * Effective user for authorization and UI while impersonating (target user),
     * otherwise the authenticated user.
     */
    function acting_user(): ?User
    {
        return impersonation()->actingUser();
    }
}

if (! function_exists('initiator_user')) {
    /**
     * The real signed-in user who started impersonation, or the current user when not impersonating.
     */
    function initiator_user(): ?User
    {
        return impersonation()->initiatorUser();
    }
}

if (! function_exists('cdn_url')) {
    /**
     * Build a CDN URL for the given path.
     *
     * Returns https://{cloudfront_domain}/{path}. In local environment,
     * returns the Storage/S3 URL (no signing; existing logic unchanged).
     *
     * @param  string  $path  Path relative to CDN root (e.g. "assets/tenant/123/file.jpg")
     */
    function cdn_url(string $path): string
    {
        return \App\Support\CdnUrl::url($path);
    }
}
