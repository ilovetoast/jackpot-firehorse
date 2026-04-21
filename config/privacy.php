<?php

use App\Services\Privacy\PrivacyRegionResolver;

return [
    /*
    |--------------------------------------------------------------------------
    | Cookie / consent policy version
    |--------------------------------------------------------------------------
    |
    | Bump when the Privacy Policy cookie section or categories change. Clients
    | with a stored version below this must re-confirm.
    |
    */
    'cookie_policy_version' => env('COOKIE_POLICY_VERSION', '1'),

    /*
    |--------------------------------------------------------------------------
    | ISO 3166-1 alpha-2 codes that require opt-in consent before non-essential
    | cookies and similar technologies (EEA, UK, CH).
    |--------------------------------------------------------------------------
    */
    'strict_opt_in_countries' => PrivacyRegionResolver::STRICT_OPT_IN_COUNTRY_CODES,

    /*
    |--------------------------------------------------------------------------
    | Load OneSignal Web SDK only after the user grants functional consent.
    | When false, the SDK script tag is always emitted (legacy behaviour).
    |--------------------------------------------------------------------------
    */
    'gate_onesignal_behind_consent' => env('GATE_ONESIGNAL_BEHIND_CONSENT', true),
];
