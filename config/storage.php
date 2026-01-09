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
    | Pattern for generating company-specific bucket names in production.
    | Available placeholders:
    |   {company_id} - The tenant ID
    |   {company_slug} - The tenant slug
    |   {env} - Current environment (prod, staging, etc.)
    |
    | Default: '{env}-dam-{company_slug}'
    |
    */

    'bucket_name_pattern' => env('STORAGE_BUCKET_NAME_PATTERN', '{env}-dam-{company_slug}'),

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
        | Lifecycle rules for bucket.
        | Set to null to disable lifecycle management.
        */
        'lifecycle_rules' => [
            [
                'id' => 'delete-old-versions',
                'status' => 'Enabled',
                'noncurrentVersionExpiration' => [
                    'NoncurrentDays' => 90,
                ],
            ],
            [
                'id' => 'abort-incomplete-uploads',
                'status' => 'Enabled',
                'abortIncompleteMultipartUpload' => [
                    'DaysAfterInitiation' => 7,
                ],
            ],
        ],
    ],

];
