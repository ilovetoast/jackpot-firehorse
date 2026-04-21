<?php

namespace App\Services\Privacy;

use Illuminate\Http\Request;

/**
 * Resolves privacy-relevant region hints from request headers (e.g. Cloudflare)
 * and browser signals (GPC). Implementation lives in app/helpers.php (and
 * config/privacy.php for country lists) so Blade does not need to resolve
 * this class for first-paint bootstrap.
 */
class PrivacyRegionResolver
{
    public function countryCodeFromRequest(Request $request): ?string
    {
        return privacy_region_country_code($request);
    }

    public function needsStrictOptIn(?string $iso2): bool
    {
        return privacy_needs_strict_opt_in($iso2);
    }

    public function globalPrivacyControl(Request $request): bool
    {
        return privacy_global_gpc($request);
    }
}
