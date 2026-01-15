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
        ],
        'features' => [
            'basic_asset_types',
        ],
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 50,
            'custom_download_permissions' => false,
            'share_downloads_with_permissions' => false,
        ],
        'notes' => [
            'No "All" button in Assets/Marketing Assets category sidebar',
            'Download links limited to 50 per month',
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
        ],
        'features' => [
            'all_asset_types',
        ],
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 200,
            'custom_download_permissions' => false,
            'share_downloads_with_permissions' => false,
        ],
        'notes' => [
            '"All" button in Assets/Marketing Assets category sidebar to view all assets across categories',
            'Download links limited to 200 per month',
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
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'download_features' => [
            'download_links_limited' => true,
            'download_links_limit' => 1000,
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'notes' => [
            '"All" button in Assets/Marketing Assets category sidebar to view all assets across categories',
            'Download links limited to 1,000 per month',
            'Share downloads with custom permissions',
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
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
            'access_to_more_roles',
            'edit_system_categories',
        ],
        'download_features' => [
            'download_links_limited' => false,
            'download_links_limit' => 999999, // Unlimited
            'custom_download_permissions' => true,
            'share_downloads_with_permissions' => true,
        ],
        'notes' => [
            '"All" button in Assets/Marketing Assets category sidebar to view all assets across categories',
            'Unlimited download links',
            'Share downloads with custom permissions',
        ],
    ],
];
