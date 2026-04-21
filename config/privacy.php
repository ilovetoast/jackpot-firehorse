<?php

/**
 * Privacy / cookie consent configuration.
 *
 * Country list is defined here (not loaded from a PHP class) so config can bootstrap
 * even if optional service classes are missing on a host during a partial deploy.
 */
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
    |
    | Must match the logic in app/helpers.php (privacy_needs_strict_opt_in).
    |
    */
    'strict_opt_in_countries' => [
        // EU member states
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU',
        'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        // EEA (non-EU)
        'IS', 'LI', 'NO',
        // United Kingdom, Switzerland
        'GB', 'CH',
    ],

    /*
    |--------------------------------------------------------------------------
    | Load OneSignal Web SDK only after the user grants functional consent.
    | When false, the SDK script tag is always emitted (legacy behaviour).
    |--------------------------------------------------------------------------
    */
    'gate_onesignal_behind_consent' => env('GATE_ONESIGNAL_BEHIND_CONSENT', true),
];
