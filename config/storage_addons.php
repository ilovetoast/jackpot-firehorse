<?php

return [
    'packages' => [
        [
            'id' => 'storage_100gb',
            'storage_mb' => 102400,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_100GB', 'price_storage_100gb'),
            'label' => '100 GB',
            'monthly_price' => 19.00,
            'available_plans' => ['starter', 'pro', 'business'],
        ],
        [
            'id' => 'storage_500gb',
            'storage_mb' => 512000,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_500GB', 'price_storage_500gb'),
            'label' => '500 GB',
            'monthly_price' => 69.00,
            'available_plans' => ['pro', 'business'],
        ],
        [
            'id' => 'storage_1tb',
            'storage_mb' => 1048576,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_1TB', 'price_storage_1tb'),
            'label' => '1 TB',
            'monthly_price' => 129.00,
            'available_plans' => ['pro', 'business'],
        ],
    ],
];
