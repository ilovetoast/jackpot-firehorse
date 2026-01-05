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
            'max_categories' => 10,
            'max_storage_mb' => 100,
            'max_upload_size_mb' => 10,
        ],
        'features' => [
            'basic_asset_types',
        ],
    ],

    'starter' => [
        'name' => 'Starter',
        'stripe_price_id' => env('price_1Slzw9BF7ZSvskYAxVgeMPlz', 'price_starter'),
        'limits' => [
            'max_brands' => 3,
            'max_categories' => 50,
            'max_storage_mb' => 1024, // 1GB
            'max_upload_size_mb' => 50,
        ],
        'features' => [
            'all_asset_types',
        ],
    ],

    'pro' => [
        'name' => 'Pro',
        'stripe_price_id' => env('price_1SlzwcBF7ZSvskYAAAZScLWz', 'price_pro'),
        'limits' => [
            'max_brands' => PHP_INT_MAX, // Unlimited
            'max_categories' => PHP_INT_MAX, // Unlimited
            'max_storage_mb' => 10240, // 10GB
            'max_upload_size_mb' => 500,
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
        ],
    ],

    'enterprise' => [
        'name' => 'Enterprise',
        'stripe_price_id' => env('price_1SlzxCBF7ZSvskYAigcdiKKj', 'price_enterprise'),
        'limits' => [
            'max_brands' => PHP_INT_MAX, // Unlimited
            'max_categories' => PHP_INT_MAX, // Unlimited
            'max_storage_mb' => PHP_INT_MAX, // Unlimited
            'max_upload_size_mb' => PHP_INT_MAX, // Unlimited
        ],
        'features' => [
            'all_asset_types',
            'advanced_features',
            'custom_integrations',
        ],
    ],
];
