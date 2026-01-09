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

];
