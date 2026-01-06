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
        ],
        'features' => [
            'basic_asset_types',
        ],
        'notes' => [
            'No "All" button in Assets/Marketing Assets category sidebar',
        ],
    ],

    'starter' => [
        'name' => 'Starter',
        'stripe_price_id' => env('STRIPE_PRICE_STARTER', 'price_1Slzw9BF7ZSvskYAxVgeMPlz'),
        'limits' => [
            'max_brands' => 3,
            'max_categories' => 2,
            'max_storage_mb' => 1024, // 1GB
            'max_upload_size_mb' => 50,
            'max_users' => 5,
        ],
        'features' => [
            'all_asset_types',
        ],
        'notes' => [
            '"All" button in Assets/Marketing Assets category sidebar to view all assets across categories',
        ],
    ],

    'pro' => [
        'name' => 'Pro',
        'stripe_price_id' => env('STRIPE_PRICE_PRO', 'price_1SlzwcBF7ZSvskYAAAZScLWz'),
        'limits' => [
            'max_brands' => 5, // Unlimited
            'max_categories' => 5, // Unlimited
            'max_storage_mb' => 999999, // 10GB
            'max_upload_size_mb' => 999999,
            'max_users' => 20,
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'access_to_more_roles',
        ],
        'notes' => [
            '"All" button in Assets/Marketing Assets category sidebar to view all assets across categories',
        ],
    ],

    'enterprise' => [
        'name' => 'Enterprise',
        'stripe_price_id' => env('STRIPE_PRICE_ENTERPRISE', 'price_1SlzxCBF7ZSvskYAigcdiKKj'),
        'limits' => [
            'max_brands' => 25, // Unlimited
            'max_categories' => 10, // Unlimited
            'max_storage_mb' => 999999, // Unlimited
            'max_upload_size_mb' => 999999, // Unlimited
            'max_users' => PHP_INT_MAX, // Unlimited
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
            'access_to_more_roles',
        ],
        'notes' => [
            '"All" button in Assets/Marketing Assets category sidebar to view all assets across categories',
        ],
    ],
];
