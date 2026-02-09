<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subscription Plans
    |--------------------------------------------------------------------------
    |
    | Define subscription plans with their Stripe price IDs and feature limits.
    | Plans define feature limits, not access logic.
    |
    */

    'free' => [
        'name' => 'Free',
        'stripe_price_id' => env('STRIPE_PRICE_FREE', 'price_free'),
        'limits' => [
            'max_brands' => 1,
            'max_categories' => 0,
            'max_storage_mb' => 100,
            'max_upload_size_mb' => 10,
            'max_users' => 2,
            'max_downloads_per_month' => 50,
            'max_download_assets' => 50, // Phase D1: max assets per download
            'max_download_zip_mb' => 500, // Phase D1: max ZIP size (MB)
            'max_custom_metadata_fields' => 0, // Phase C3: No custom fields on free plan
            'max_tags_per_asset' => 1, // Maximum tags allowed per asset
            // AI Usage caps (per month)
            'max_ai_tagging_per_month' => 5, // 5 AI tagging calls per month (very low allowance for free plan)
            'max_ai_suggestions_per_month' => 10, // 10 AI suggestions per month (small allowance for free plan)
        ],
        'features' => [
            'basic_asset_types',
        ],
        'approval_features' => [
            'approvals.enabled' => false,
            'notifications.enabled' => false,
            'approval_summaries.enabled' => false,
        ],
        'public_collections_enabled' => false,
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 50,
            'custom_download_permissions' => false,
            'share_downloads_with_permissions' => false,
        ],
        'notes' => [
            'No "All" button in Assets/Executions category sidebar',
            'Download links limited to 50 per month',
            'Limited to 1 tag per asset for basic organization',
            '5 AI tagging operations and 10 AI suggestions per month',
        ],
    ],

    'starter' => [
        'name' => 'Starter',
        'stripe_price_id' => env('STRIPE_PRICE_STARTER', 'price_1Slzw9BF7ZSvskYAxVgeMPlz'),
        'fallback_monthly_price' => 29.00, // Fallback price if Stripe unavailable
        'limits' => [
            'max_brands' => 3,
            'max_categories' => 2,
            'max_storage_mb' => 1024, // 1GB
            'max_upload_size_mb' => 50,
            'max_users' => 5,
            'max_downloads_per_month' => 200,
            'max_download_assets' => 100,
            'max_download_zip_mb' => 1000,
            'max_custom_metadata_fields' => 5, // Phase C3: 5 custom fields on starter plan
            'max_tags_per_asset' => 5, // Maximum tags allowed per asset
            // AI Usage caps (per month)
            'max_ai_tagging_per_month' => 50, // 50 AI tagging calls per month
            'max_ai_suggestions_per_month' => 100, // 100 AI suggestions per month
        ],
        'features' => [
            'all_asset_types',
        ],
        'approval_features' => [
            'approvals.enabled' => false,
            'notifications.enabled' => false,
            'approval_summaries.enabled' => false,
        ],
        'public_collections_enabled' => false,
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 200,
            'custom_download_permissions' => false,
            'share_downloads_with_permissions' => false,
        ],
        'notes' => [
            '"All" button in Assets/Executions category sidebar to view all assets across categories',
            'Download links limited to 200 per month',
            'Up to 5 tags per asset for better organization',
            '50 AI tagging operations and 100 AI suggestions per month',
        ],
    ],

    'pro' => [
        'name' => 'Pro',
        'stripe_price_id' => env('STRIPE_PRICE_PRO', 'price_1SlzwcBF7ZSvskYAAAZScLWz'),
        'fallback_monthly_price' => 99.00, // Fallback price if Stripe unavailable
        'limits' => [
            'max_brands' => 5, // Unlimited
            'max_categories' => 5, // Unlimited
            'max_private_categories' => 5,
            'max_storage_mb' => 999999, // 10GB
            'max_upload_size_mb' => 999999,
            'max_users' => 20,
            'max_downloads_per_month' => 1000,
            'max_download_assets' => 500,
            'max_download_zip_mb' => 2048,
            'max_custom_metadata_fields' => 20, // Phase C3: 20 custom fields on pro plan
            'max_tags_per_asset' => 10, // Maximum tags allowed per asset
            // AI Usage caps (per month)
            'max_ai_tagging_per_month' => 500, // 500 AI tagging calls per month
            'max_ai_suggestions_per_month' => 1000, // 1000 AI suggestions per month
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'approval_features' => [
            'approvals.enabled' => true,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => true,
        ],
        'public_collections_enabled' => false,
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 1000,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'notes' => [
            '"All" button in Assets/Executions category sidebar to view all assets across categories',
            'Download links limited to 1,000 per month',
            'Share downloads with custom permissions',
            'Up to 10 tags per asset for comprehensive categorization',
            '500 AI tagging operations and 1,000 AI suggestions per month',
        ],
    ],

    'enterprise' => [
        'name' => 'Enterprise',
        'stripe_price_id' => env('STRIPE_PRICE_ENTERPRISE', 'price_1SlzxCBF7ZSvskYAigcdiKKj'),
        'fallback_monthly_price' => 299.00, // Fallback price if Stripe unavailable
        'limits' => [
            'max_brands' => 25, // Unlimited
            'max_categories' => 10, // Unlimited
            'max_private_categories' => 10,
            'max_storage_mb' => 999999, // Unlimited
            'max_upload_size_mb' => 999999, // Unlimited
            'max_users' => 200, // Unlimited
            'max_downloads_per_month' => 999999, // Unlimited
            'max_download_assets' => 2000,
            'max_download_zip_mb' => 5120,
            'max_custom_metadata_fields' => 100, // Phase C3: 100 custom fields on enterprise plan
            'max_tags_per_asset' => 15, // Maximum tags allowed per asset
            // AI Usage caps (per month)
            'max_ai_tagging_per_month' => 10000, // 10,000 AI tagging calls per month
            'max_ai_suggestions_per_month' => 10000, // 10,000 AI suggestions per month
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'approval_features' => [
            'approvals.enabled' => true,
            'notifications.enabled' => true,
            'approval_summaries.enabled' => true,
        ],
        'public_collections_enabled' => true, // C10: Enterprise only
        'download_features' => [
            'download_links_limited' => false,
            'download_links_limit' => 999999, // Unlimited
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'notes' => [
            '"All" button in Assets/Executions category sidebar to view all assets across categories',
            'Unlimited download links',
            'Share downloads with custom permissions',
            'Up to 15 tags per asset for maximum flexibility',
            '10,000 AI tagging operations and 10,000 AI suggestions per month',
        ],
    ],
];
