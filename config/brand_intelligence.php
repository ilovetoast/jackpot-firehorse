<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Overview “What needs attention” JSON cache (seconds)
    |--------------------------------------------------------------------------
    |
    | Deferred /app/overview/insights payload (signals, momentum, LLM insights).
    | On cache miss, BrandInsightLLM may call your AI provider (multi-second TTFB) before
    | the response is cached; repeat visits within TTL are fast. Raise TTL to reduce cold hits.
    |
    */
    'overview_insights_ttl_seconds' => max(60, (int) env('BRAND_OVERVIEW_INSIGHTS_TTL', 300)),

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
