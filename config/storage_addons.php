<?php

return [
    'packages' => [
        [
            'id' => 'storage_50gb',
            'storage_mb' => 51200,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_50GB'),
            'label' => '50 GB',
            'monthly_price' => 4.00,
        ],
        [
            'id' => 'storage_100gb',
            'storage_mb' => 102400,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_100GB'),
            'label' => '100 GB',
            'monthly_price' => 7.00,
        ],
        [
            'id' => 'storage_250gb',
            'storage_mb' => 256000,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_250GB'),
            'label' => '250 GB',
            'monthly_price' => 15.00,
        ],
        [
            'id' => 'storage_500gb',
            'storage_mb' => 512000,
            'stripe_price_id' => env('STRIPE_PRICE_STORAGE_500GB'),
            'label' => '500 GB',
            'monthly_price' => 25.00,
        ],
    ],
];
