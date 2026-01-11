<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asset Deletion Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for asset deletion lifecycle and grace period.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Grace Period
    |--------------------------------------------------------------------------
    |
    | Number of days to wait before permanently deleting soft-deleted assets.
    | During this period, assets can be restored.
    | After this period, assets are permanently deleted via async job.
    |
    */

    'deletion_grace_period_days' => env('ASSET_DELETION_GRACE_PERIOD_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Storage Calculation
    |--------------------------------------------------------------------------
    |
    | Configuration for how storage is calculated for plan limits.
    | Soft-deleted assets are excluded from storage calculations.
    |
    */

    'storage' => [
        /*
        | Include soft-deleted assets in storage calculations.
        | Set to false to exclude soft-deleted assets (default behavior).
        */
        'include_soft_deleted' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Styles
    |--------------------------------------------------------------------------
    |
    | Canonical thumbnail style definitions for asset preview generation.
    | These styles are used consistently across the application for:
    |   - Grid thumbnails (thumb)
    |   - Drawer previews (medium)
    |   - High-resolution previews (large)
    |
    | All thumbnails are generated atomically per asset via GenerateThumbnailsJob.
    | Thumbnails are stored in S3 alongside the original asset.
    |
    */

    'thumbnail_styles' => [
        /*
         * Small grid thumbnail.
         * Used for asset grid views and list previews.
         */
        'thumb' => [
            'width' => 320,
            'height' => 320,
            'quality' => 85,
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
        ],

        /*
         * Medium drawer preview.
         * Used for asset detail drawers and modal previews.
         */
        'medium' => [
            'width' => 1024,
            'height' => 1024,
            'quality' => 90,
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
        ],

        /*
         * Large high-resolution preview.
         * Used for full-screen previews and high-quality displays.
         * Maximum dimension capped at 4096px to prevent excessive processing.
         */
        'large' => [
            'width' => 4096,
            'height' => 4096,
            'quality' => 95,
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
        ],
    ],

];
