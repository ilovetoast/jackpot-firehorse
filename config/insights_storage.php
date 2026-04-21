<?php

/**
 * Insights-only assumptions for comparing archived (Standard-IA) vs active (Standard) footprint.
 *
 * Defaults mirror common AWS S3 **list** US East (N. Virginia) per-GB-month **storage** prices for the
 * first monthly tier (order-of-magnitude; verify against https://aws.amazon.com/s3/pricing/ ).
 * Override via .env if your region/tier differs.
 */
return [
    'aws_s3_list_usd_per_gb_month' => [
        'standard' => (float) env('INSIGHTS_S3_STANDARD_USD_PER_GB_MO', 0.023),
        'standard_ia' => (float) env('INSIGHTS_S3_STANDARD_IA_USD_PER_GB_MO', 0.0125),
    ],

    'disclaimer' => 'Illustrative AWS S3 list storage prices (USD per GB-month, first tier). Archived files are moved to S3 Standard-IA in-app. Excludes requests, retrieval, data transfer, minimum billable size, and minimum storage duration charges.',

    'archive_storage_class' => 'STANDARD_IA',
];
