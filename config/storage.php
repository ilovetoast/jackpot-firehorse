<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage Provisioning Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for S3 bucket provisioning per company/tenant.
    | Environment-specific behavior is controlled via config, not hardcoded.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Provision Strategy
    |--------------------------------------------------------------------------
    |
    | Defines how storage buckets are provisioned per environment:
    |
    | 'per_company' - Create one bucket per company (production only)
    | 'shared' - Use a single shared bucket for all companies (local/staging)
    |
    */

    'provision_strategy' => env('STORAGE_PROVISION_STRATEGY', 'shared'),

    /*
    |--------------------------------------------------------------------------
    | Shared Bucket Name
    |--------------------------------------------------------------------------
    |
    | When using 'shared' strategy, this bucket is used for all companies.
    | Must match AWS_BUCKET from .env for local/staging environments.
    |
    */

    'shared_bucket' => env('AWS_BUCKET'),

    /*
    |--------------------------------------------------------------------------
    | Bucket Naming Pattern
    |--------------------------------------------------------------------------
    |
    | Pattern for generating company-specific bucket names (per_company strategy).
    | Must match IAM resource pattern (e.g. jackpot-staging-*).
    |
    | Approved structure: {app-prefix}-{environment}-{tenant-slug}
    | Placeholders: {env}, {company_id}, {company_slug}
    |
    | Examples:
    |   jackpot-{env}-{company_slug}  → jackpot-staging-acme, jackpot-staging-velvet-hammer
    |   {env}-dam-{company_slug}      → staging-dam-velvethammerbranding (legacy)
    |
    */

    'bucket_name_pattern' => env('STORAGE_BUCKET_NAME_PATTERN', 'jackpot-{env}-{company_slug}'),

    /*
    |--------------------------------------------------------------------------
    | Default Region
    |--------------------------------------------------------------------------
    |
    | Default AWS region for bucket creation when not specified.
    |
    */

    'default_region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    /*
    |--------------------------------------------------------------------------
    | Bucket Configuration
    |--------------------------------------------------------------------------
    |
    | Default settings applied to all provisioned buckets.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | CORS Allowed Origins (for presigned uploads)
    |--------------------------------------------------------------------------
    |
    | Origins allowed in S3 bucket CORS. Browser uploads to presigned URLs
    | require the app origin to be allowed. Set STORAGE_CORS_ORIGINS to
    | override (comma-separated). Default: staging, production, localhost.
    |
    */
    'cors_allowed_origins' => (function () {
        $custom = env('STORAGE_CORS_ORIGINS');
        if ($custom !== null && $custom !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $custom))));
        }

        $origins = [
            'https://staging-jackpot.velvetysoft.com',
            'https://jackpot.velvetysoft.com',
            'http://localhost:3000',
            'http://localhost:5173',
        ];

        // Include APP_URL origin (e.g. http://jackpot.local) so cross-origin S3 requests work
        $appUrl = config('app.url');
        if ($appUrl) {
            $parsed = parse_url($appUrl);
            if (isset($parsed['scheme'], $parsed['host'])) {
                $origin = $parsed['scheme'] . '://' . $parsed['host'];
                if (isset($parsed['port']) && ! in_array($parsed['port'], [80, 443], true)) {
                    $origin .= ':' . $parsed['port'];
                }
                if (! in_array($origin, $origins, true)) {
                    $origins[] = $origin;
                }
            }
        }

        return $origins;
    })(),

    'cors_expose_headers' => ['ETag', 'x-amz-request-id', 'x-amz-id-2'],
    'cors_max_age_seconds' => 3000,

    'bucket_config' => [
        /*
        | Enable versioning on all buckets.
        */
        'versioning' => true,

        /*
        | Encryption settings for bucket.
        | Supported: 'AES256', 'aws:kms'
        */
        'encryption' => 'AES256',

        /*
        | KMS key ID (only used if encryption is 'aws:kms').
        */
        'kms_key_id' => env('AWS_KMS_KEY_ID'),

        /*
        | Lifecycle rules for bucket (keys must match S3 API: Status, ID, etc.).
        | Set to null to disable lifecycle management.
        */
        'lifecycle_rules' => [
            [
                'ID' => 'delete-old-versions',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'NoncurrentVersionExpiration' => [
                    'NoncurrentDays' => 90,
                ],
            ],
            [
                'ID' => 'abort-incomplete-uploads',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'AbortIncompleteMultipartUpload' => [
                    'DaysAfterInitiation' => 7,
                ],
            ],
        ],
    ],

];
