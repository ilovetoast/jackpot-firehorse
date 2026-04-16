<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Creator Module Add-on
    |--------------------------------------------------------------------------
    |
    | The Creator (Prostaff) Module is included in the Business plan.
    | For Pro, it is available as a paid add-on via Stripe subscription item.
    |
    | Seat packs add active creator seats on top of the base included seats.
    | Business plan includes 50 seats; seat packs are available to both
    | Pro (with module add-on) and Business customers.
    |
    */

    'base' => [
        'stripe_price_id' => env('STRIPE_PRICE_CREATOR_MODULE'),
        'monthly_price' => 99,
        'included_seats' => 25,
        'available_plans' => ['pro'],
    ],

    'seat_packs' => [
        [
            'id' => 'creator_seats_25',
            'seats' => 25,
            'stripe_price_id' => env('STRIPE_PRICE_CREATOR_SEATS_25'),
            'monthly_price' => 49,
        ],
        [
            'id' => 'creator_seats_100',
            'seats' => 100,
            'stripe_price_id' => env('STRIPE_PRICE_CREATOR_SEATS_100'),
            'monthly_price' => 149,
        ],
    ],
];
