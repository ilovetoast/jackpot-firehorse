<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Collection TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | TTL for CloudFront signed URLs on public collection pages.
    | Thumbnails and preview URLs expire after this many seconds.
    |
    */

    'public_collection_ttl' => (int) env('CDN_PUBLIC_COLLECTION_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | Public Download TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | TTL for CloudFront signed URLs on public download landing pages.
    | Uses DownloadExpirationPolicy when available; otherwise this default.
    |
    */

    'public_download_ttl' => (int) env('CDN_PUBLIC_DOWNLOAD_TTL', 900),

];
