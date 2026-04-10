<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-asset action cooldown (minutes)
    |--------------------------------------------------------------------------
    |
    | Minimum time between the same processing action type on one asset.
    |
    */
    'cooldown_minutes' => (int) env('ASSET_PROCESSING_COOLDOWN_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Per-user hourly cap (all processing actions combined)
    |--------------------------------------------------------------------------
    */
    'max_dispatches_per_user_per_hour' => (int) env('ASSET_PROCESSING_MAX_PER_USER_HOUR', 40),

    /*
    |--------------------------------------------------------------------------
    | Inflight dedupe lock (seconds)
    |--------------------------------------------------------------------------
    |
    | Prevents overlapping dispatches of the same action for the same asset.
    |
    */
    'inflight_lock_seconds' => (int) env('ASSET_PROCESSING_INFLIGHT_LOCK_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Bulk site-pipeline limits
    |--------------------------------------------------------------------------
    */
    'max_bulk_pipeline_assets' => (int) env('ASSET_PROCESSING_MAX_BULK', 25),

    'bulk_pipeline_chunk_size' => (int) env('ASSET_PROCESSING_BULK_CHUNK', 10),
];
