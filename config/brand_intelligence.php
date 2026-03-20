<?php

return [
    /*
    |--------------------------------------------------------------------------
    | EBI-only mode (legacy flag)
    |--------------------------------------------------------------------------
    |
    | Retained for env compatibility. Deterministic Brand Compliance scoring is
    | removed; Brand Intelligence is the only scoring path.
    |
    */
    'only_mode' => filter_var(env('BRAND_INTELLIGENCE_ONLY_MODE', true), FILTER_VALIDATE_BOOL),
];
