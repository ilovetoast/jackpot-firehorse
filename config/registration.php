<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public self-service registration
    |--------------------------------------------------------------------------
    |
    | When false, marketing and the gateway hide "Sign up" / "Create account" for
    | guests. POST /gateway/register is rejected unless a bypass session was granted
    | (see registration_key below). Invitation-based signup is unchanged.
    |
    | Set REGISTRATION_ENABLED=false to block public signup (set this on staging / private previews).
    | When disabled, optional REGISTRATION_BYPASS_SECRET unlocks /gateway?mode=register for your team.
    |
    */
    'enabled' => filter_var(env('REGISTRATION_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Bypass secret (optional)
    |--------------------------------------------------------------------------
    |
    | When registration is disabled and this is non-empty, visiting:
    |   /gateway?mode=register&registration_key=<secret>
    | sets a short-lived session flag so your team can still create accounts.
    | Keep this long and random; do not expose it in the marketing UI.
    |
    */
    'bypass_secret' => env('REGISTRATION_BYPASS_SECRET', ''),

];
