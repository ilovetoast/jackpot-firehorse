<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CloudFront Domain
    |--------------------------------------------------------------------------
    |
    | The CloudFront distribution domain (e.g. d1234.cloudfront.net or
    | cdn.example.com for custom domain).
    |
    */

    'domain' => env('CLOUDFRONT_DOMAIN', ''),

    /*
    |--------------------------------------------------------------------------
    | Key Pair ID
    |--------------------------------------------------------------------------
    |
    | The CloudFront public key ID from your key group.
    |
    */

    'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Private Key Path
    |--------------------------------------------------------------------------
    |
    | Path to the PEM file containing the private key (relative to project root
    | or absolute). Must correspond to the public key in your CloudFront key group.
    |
    */

    'private_key_path' => env('CLOUDFRONT_PRIVATE_KEY_PATH', 'storage/keys/cloudfront-private.pem'),

    /*
    |--------------------------------------------------------------------------
    | Cookie Expiry (seconds)
    |--------------------------------------------------------------------------
    |
    | Expiration for signed cookies per environment.
    | - staging: 1 hour (3600)
    | - production: 2–4 hours (e.g. 14400 = 4 hours)
    | - local: signing is skipped
    |
    */

    'cookie_expiry_staging' => (int) env('CLOUDFRONT_COOKIE_EXPIRY_STAGING', 3600),
    'cookie_expiry_production' => (int) env('CLOUDFRONT_COOKIE_EXPIRY_PRODUCTION', 14400),

    /*
    |--------------------------------------------------------------------------
    | Cookie Domain
    |--------------------------------------------------------------------------
    |
    | Domain for the signed cookies. For CloudFront default domain
    | (*.cloudfront.net), use the CloudFront domain. For custom domain
    | (e.g. cdn.example.com), use .example.com so cookies work for both
    | app and CDN. Null = current request host.
    |
    */

    'cookie_domain' => env('CLOUDFRONT_COOKIE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Refresh Threshold (seconds)
    |--------------------------------------------------------------------------
    |
    | Regenerate cookies when less than this many seconds remain.
    | Default 300 (5 min) prevents mid-session asset failures.
    |
    */

    'refresh_threshold' => (int) env('CLOUDFRONT_COOKIE_REFRESH_THRESHOLD', 300),

    /*
    |--------------------------------------------------------------------------
    | IP Restriction (optional)
    |--------------------------------------------------------------------------
    |
    | When true, signed cookie policy includes IpAddress condition.
    | Disabled by default. Future enterprise toggle.
    |
    */

    'cookie_restrict_ip' => (bool) env('CLOUDFRONT_COOKIE_RESTRICT_IP', false),

    /*
    |--------------------------------------------------------------------------
    | Authenticated Cookie TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | Primary config for signed cookie expiration. Used by CloudFrontSignedCookieService.
    | Fallback: 3600 (1 hour).
    |
    */
    'authenticated_cookie_ttl' => (int) env('CLOUDFRONT_AUTHENTICATED_COOKIE_TTL', env('CDN_AUTHENTICATED_COOKIE_TTL', 3600)),

    /*
    |--------------------------------------------------------------------------
    | Admin Signed URL TTL (seconds)
    |--------------------------------------------------------------------------
    |
    | TTL for CloudFront signed URLs used by admin routes (e.g. asset grid
    | thumbnails). Shorter than cookie-based tenant flows. Cache TTL for
    | signed URL generation should be less than this value.
    |
    */
    'admin_signed_url_ttl' => (int) env('CLOUDFRONT_ADMIN_SIGNED_URL_TTL', 300),

];
