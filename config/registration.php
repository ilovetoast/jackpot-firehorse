<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public self-service registration
    |--------------------------------------------------------------------------
    |
    | When false, marketing and gateway still link to signup; guests must enter
    | REGISTRATION_BYPASS_SECRET on the register screen (or use ?registration_key= for a bookmark).
    | POST /gateway/register stays blocked until that session unlock. Invitation-based signup is unchanged.
    |
    | Set REGISTRATION_ENABLED=false on staging / private previews; set REGISTRATION_BYPASS_SECRET for team access.
    |
    */
    'enabled' => filter_var(env('REGISTRATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Bypass secret (optional)
    |--------------------------------------------------------------------------
    |
    | When registration is disabled and this is non-empty, guests see an access-code step before
    | the signup form. Optional URL shortcut (same value):
    |   /gateway?mode=register&registration_key=<secret>
    | Keep this long and random; do not expose it in the marketing UI.
    |
    */
    'bypass_secret' => env('REGISTRATION_BYPASS_SECRET', ''),

];
