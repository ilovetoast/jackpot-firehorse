<?php

declare(strict_types=1);

/**
 * Stripe Price ID map (test-mode defaults for local/dev).
 *
 * Feature limits stay in config/plans.php, config/storage_addons.php, etc.
 * This file only maps internal keys ↔ Stripe recurring Price IDs.
 *
 * Legacy env names (STRIPE_PRICE_STARTER, STRIPE_PRICE_CREDITS_500, …) are
 * fallbacks when the *_MONTHLY variables are unset, so existing deployments
 * keep working after introducing this file.
 *
 * Live mode requires different Price IDs — set env per environment; never
 * commit live secrets or live price IDs to the repo.
 *
 * TODO: Add `php artisan stripe:verify-prices` to fetch Prices from the Stripe
 * API (lookup_key, metadata) and diff against this map — CSV exports omit lookup keys.
 */
return [
    'stripe_prices' => [
        'plans' => [
            'starter' => env('STRIPE_PRICE_STARTER_MONTHLY')
                ?: env('STRIPE_PRICE_STARTER', 'price_1TTSKwGhqsHxkVRaaWo96XSI'),
            'pro' => env('STRIPE_PRICE_PRO_MONTHLY')
                ?: env('STRIPE_PRICE_PRO', 'price_1TTSLUGhqsHxkVRaE4YdWYB5'),
            'business' => env('STRIPE_PRICE_BUSINESS_MONTHLY')
                ?: env('STRIPE_PRICE_BUSINESS', 'price_1TTSLrGhqsHxkVRaFGdxKUrx'),
        ],

        'addons' => [
            'storage_100gb' => env('STRIPE_PRICE_STORAGE_100GB_MONTHLY')
                ?: env('STRIPE_PRICE_STORAGE_100GB', 'price_1TTSNMGhqsHxkVRaTwgpJSRA'),
            'storage_500gb' => env('STRIPE_PRICE_STORAGE_500GB_MONTHLY')
                ?: env('STRIPE_PRICE_STORAGE_500GB', 'price_1TTSPgGhqsHxkVRazr02RpzJ'),
            'storage_1tb' => env('STRIPE_PRICE_STORAGE_1TB_MONTHLY')
                ?: env('STRIPE_PRICE_STORAGE_1TB', 'price_1TTSPgGhqsHxkVRaX6yS53NP'),

            'ai_credits_500' => env('STRIPE_PRICE_AI_CREDITS_500_MONTHLY')
                ?: env('STRIPE_PRICE_CREDITS_500', 'price_1TTSQQGhqsHxkVRan55tWZOM'),
            'ai_credits_2000' => env('STRIPE_PRICE_AI_CREDITS_2000_MONTHLY')
                ?: env('STRIPE_PRICE_CREDITS_2000', 'price_1TTSRHGhqsHxkVRa4y5V0up6'),
            'ai_credits_10000' => env('STRIPE_PRICE_AI_CREDITS_10000_MONTHLY')
                ?: env('STRIPE_PRICE_CREDITS_10000', 'price_1TTSRHGhqsHxkVRa5sRekVmJ'),

            'creator_module' => env('STRIPE_PRICE_CREATOR_MODULE_MONTHLY')
                ?: env('STRIPE_PRICE_CREATOR_MODULE', 'price_1TTSS7GhqsHxkVRa1ccGlKls'),
            'creator_seats_25' => env('STRIPE_PRICE_CREATOR_SEATS_25_MONTHLY')
                ?: env('STRIPE_PRICE_CREATOR_SEATS_25', 'price_1TTSViGhqsHxkVRaF6BdK1Hm'),
            'creator_seats_100' => env('STRIPE_PRICE_CREATOR_SEATS_100_MONTHLY')
                ?: env('STRIPE_PRICE_CREATOR_SEATS_100', 'price_1TTSWCGhqsHxkVRajsP7Slku'),
        ],
    ],
];
