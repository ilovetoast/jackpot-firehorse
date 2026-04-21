<?php

namespace App\Services\Privacy;

use Illuminate\Http\Request;

/**
 * Resolves privacy-relevant region hints from request headers (e.g. Cloudflare)
 * and browser signals (GPC, DNT).
 */
class PrivacyRegionResolver
{
    /**
     * ISO 3166-1 alpha-2 codes where non-essential cookies / similar tech require
     * opt-in consent before use (EEA, UK, CH).
     *
     * @var list<string>
     */
    public const STRICT_OPT_IN_COUNTRY_CODES = [
        // EU member states
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
        'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        // EEA (non-EU)
        'IS', 'LI', 'NO',
        // United Kingdom, Switzerland
        'GB', 'CH',
    ];

    public function countryCodeFromRequest(Request $request): ?string
    {
        $cf = $request->header('CF-IPCountry');
        if (is_string($cf) && strlen($cf) === 2) {
            return strtoupper($cf);
        }

        return null;
    }

    public function needsStrictOptIn(?string $iso2): bool
    {
        if ($iso2 === null || strlen($iso2) !== 2) {
            return false;
        }

        return in_array(strtoupper($iso2), self::STRICT_OPT_IN_COUNTRY_CODES, true);
    }

    /**
     * Global Privacy Control (W3C). When true, analytics and marketing should be off.
     */
    public function globalPrivacyControl(Request $request): bool
    {
        $gpc = $request->header('Sec-GPC');

        return $gpc === '1';
    }
}
