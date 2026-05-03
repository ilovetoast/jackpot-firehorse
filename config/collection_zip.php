<?php

/**
 * Public / guest collection ZIP builds (see CollectionZipBuilderService, HandlesGuestCollectionShare).
 *
 * Disk: builds stream S3 → temp files → ZIP → S3; peak disk ≈ sum(member sizes) + final ZIP size
 * until upload completes. Point temp_directory at a large writable mount on constrained app servers.
 *
 * Limits: plan caps (max_download_zip_mb, max_download_assets) always apply; server_max_estimated_zip_mb
 * optionally lowers the effective ceiling per environment (e.g. staging vs production).
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Writable temp directory for ZIP scratch files
    |--------------------------------------------------------------------------
    |
    | When empty, PHP's sys_get_temp_dir() is used. Set to an absolute path on a
    | volume with enough free space for your largest allowed collection ZIP build
    | (e.g. /var/jackpot/tmp-zips). Directory must exist and be writable by php-fpm.
    |
    */
    'temp_directory' => env('COLLECTION_ZIP_TEMP_DIRECTORY'),

    /*
    |--------------------------------------------------------------------------
    | Optional server-side ZIP size ceiling (MB)
    |--------------------------------------------------------------------------
    |
    | When > 0, the effective max download bytes for this request is
    | min(plan_limit, this value in bytes). Use on small staging hosts or shared
    | disks to refuse huge ZIPs even if the tenant plan allows more. 0 = disabled.
    |
    */
    'server_max_estimated_zip_mb' => (int) env('COLLECTION_ZIP_SERVER_MAX_ESTIMATED_ZIP_MB', 0),
];
