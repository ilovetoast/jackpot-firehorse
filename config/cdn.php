<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authenticated Cookie TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | TTL for CloudFront signed cookies (AUTHENTICATED context).
    | Cookies restrict access to /tenants/{tenant_uuid}/* only.
    |
    */
    'authenticated_cookie_ttl' => (int) env('CDN_AUTHENTICATED_COOKIE_TTL', 3600),

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

    /*
    |--------------------------------------------------------------------------
    | PDF Page Signed URL TTLs (seconds)
    |--------------------------------------------------------------------------
    |
    | Admin/internal viewers use shorter signed URLs.
    | Public viewers use a longer TTL to reduce regeneration churn.
    |
    */
    'pdf_page_admin_ttl' => (int) env('CDN_PDF_PAGE_ADMIN_TTL', 300),
    'pdf_page_public_ttl' => (int) env('CDN_PDF_PAGE_PUBLIC_TTL', 1800),

];
