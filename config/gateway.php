<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway resume cookie (last workspace)
    |--------------------------------------------------------------------------
    |
    | After a user picks a company + brand, a short-lived encrypted cookie
    | lets plain GET /gateway resume cinematic enter without the picker when
    | they still have multiple brands. Cleared with ?switch=1 or on logout.
    | See docs/GATEWAY_ENTRY_CONTROLS_DEFERRED.md.
    |
    */
    'resume_ttl_minutes' => (int) env('GATEWAY_RESUME_TTL_MINUTES', 240),

    /*
    |--------------------------------------------------------------------------
    | Legacy brand-level entry controls (Public Gateway tab)
    |--------------------------------------------------------------------------
    |
    | When true, Brand settings → Public Gateway shows entry style, default
    | destination, and auto-enter again. Product defaults are enforced in code
    | when this is false.
    |
    */
    'show_legacy_entry_controls' => (bool) env('GATEWAY_SHOW_LEGACY_ENTRY_CONTROLS', false),

];
