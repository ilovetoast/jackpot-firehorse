<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    | Toggle response timing middleware and optional persistence.
    | Set to false in production if not needed; enable for debugging.
    */
    'enabled' => filter_var(env('PERFORMANCE_MONITORING_ENABLED', env('APP_DEBUG', false)), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Persist Slow Logs
    |--------------------------------------------------------------------------
    | Store slow requests (> threshold) in performance_logs table.
    | Note: Only requests exceeding slow_threshold_ms are persisted.
    */
    'persist_slow_logs' => filter_var(env('PERFORMANCE_PERSIST_SLOW_LOGS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Persist All Requests
    |--------------------------------------------------------------------------
    | When true, persist every request (not just slow ones). Use for avg metrics.
    | Can be noisy; prefer persist_slow_logs for production.
    */
    'persist_all_requests' => filter_var(env('PERFORMANCE_PERSIST_ALL_REQUESTS', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Slow Request Threshold (ms)
    |--------------------------------------------------------------------------
    */
    'slow_threshold_ms' => (int) env('PERFORMANCE_SLOW_THRESHOLD_MS', 1000),

    /*
    |--------------------------------------------------------------------------
    | Client Metrics
    |--------------------------------------------------------------------------
    | Allow frontend to POST page load / image metrics.
    */
    'client_metrics_enabled' => filter_var(env('PERFORMANCE_CLIENT_METRICS_ENABLED', env('APP_DEBUG', false)), FILTER_VALIDATE_BOOLEAN),
];
