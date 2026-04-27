<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Worker profile & processing budgets
    |--------------------------------------------------------------------------
    |
    | Central limits for decode-heavy work (PSD, large rasters, video, PDF).
    | Set ASSET_WORKER_PROFILE=staging_small on small staging workers, heavy on dedicated media workers.
    |
    */
    'worker_profile' => env('ASSET_WORKER_PROFILE', 'normal'),

    /**
     * When true and classify() returns defer_to_heavy_worker, {@see ProcessAssetJob} re-dispatches
     * itself onto the pipeline queue {@see PipelineQueueResolver::forPipeline()} would use for the
     * file (e.g. images-heavy / images-psd) instead of marking the asset skipped on this machine.
     */
    'defer_heavy_to_queue' => env('ASSET_DEFER_HEAVY_TO_QUEUE', false),

    'profiles' => [
        'staging_small' => [
            'max_image_mb' => (float) env('ASSET_MAX_IMAGE_MB', 75),
            'max_psd_mb' => (float) env('ASSET_MAX_PSD_MB', 250),
            'max_video_mb' => (float) env('ASSET_MAX_VIDEO_MB', 250),
            'max_pdf_mb' => (float) env('ASSET_MAX_PDF_MB', 150),
            'max_pixels' => (int) env('ASSET_MAX_PIXELS', 80_000_000),
            'allow_huge_psd' => env('ASSET_ALLOW_HUGE_PSD', false),
        ],
        'normal' => [
            'max_image_mb' => 150,
            'max_psd_mb' => 250,
            'max_video_mb' => 500,
            'max_pdf_mb' => 250,
            'max_pixels' => 120_000_000,
            'allow_huge_psd' => false,
        ],
        'heavy' => [
            'max_image_mb' => 1000,
            'max_psd_mb' => 1500,
            'max_video_mb' => 2000,
            'max_pdf_mb' => 1000,
            'max_pixels' => 300_000_000,
            'allow_huge_psd' => true,
        ],
    ],

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
