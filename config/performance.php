<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Performance Monitoring
    |--------------------------------------------------------------------------
    | Toggle response timing middleware and optional persistence.
    | Set to false in production if not needed; enable for debugging.
    */
    'enabled' => env('PERFORMANCE_MONITORING_ENABLED', env('APP_DEBUG', false)),

    /*
    |--------------------------------------------------------------------------
    | Persist Slow Logs
    |--------------------------------------------------------------------------
    | Store slow requests (> threshold) in performance_logs table.
    */
    'persist_slow_logs' => env('PERFORMANCE_PERSIST_SLOW_LOGS', false),

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
    'client_metrics_enabled' => env('PERFORMANCE_CLIENT_METRICS_ENABLED', env('APP_DEBUG', false)),
];
