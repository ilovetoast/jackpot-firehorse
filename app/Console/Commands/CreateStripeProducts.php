<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\Price;
use Stripe\Product;
use Stripe\Stripe;

/**
 * Creates all Stripe products and prices for the new plan structure.
 *
 * Run once per Stripe environment (test / live).
 * Outputs env vars to paste into .env.
 *
 * Prefer setting the `STRIPE_PRICE_*_MONTHLY` variables documented in docs/billing-stripe-dev.md
 * (see config/billing_stripe.php). Legacy keys like STRIPE_PRICE_STARTER remain supported as fallbacks.
 *
 * Usage:
 *   php artisan stripe:create-products
 *   php artisan stripe:create-products --dry-run
 */
class CreateStripeProducts extends Command
{
    protected $signature = 'stripe:create-products {--dry-run : Show what would be created without calling Stripe}';

    protected $description = 'Create Stripe products and monthly prices for all plans, storage add-ons, AI credit add-ons, and Creator Module';

    private array $envVars = [];

    public function handle(): int
    {
        $stripeSecret = config('services.stripe.secret');
        $dryRun = $this->option('dry-run');

        if (! $dryRun) {
            if (empty($stripeSecret)) {
                $this->error('STRIPE_SECRET is not configured.');

                return self::FAILURE;
            }
            Stripe::setApiKey($stripeSecret);
        }

        $this->info($dryRun ? '=== DRY RUN ===' : '=== Creating Stripe Products ===');
        $this->newLine();

        $definitions = $this->getDefinitions();

        foreach ($definitions as $def) {
            if ($dryRun) {
                $this->line("Would create: {$def['product_name']} @ \${$def['price_cents'] / 100}/mo → {$def['env_key']}");

                continue;
            }

            $this->info("Creating: {$def['product_name']}...");

            $product = Product::create([
                'name' => $def['product_name'],
                'metadata' => ['internal_id' => $def['internal_id']],
            ]);

            $price = Price::create([
                'product' => $product->id,
                'unit_amount' => $def['price_cents'],
                'currency' => 'usd',
                'recurring' => ['interval' => 'month'],
                'metadata' => ['internal_id' => $def['internal_id']],
            ]);

            $this->envVars[$def['env_key']] = $price->id;
            $this->line("  ✓ {$def['env_key']}={$price->id}");
        }

        $this->newLine();
        $this->info('=== Add these to your .env ===');
        $this->newLine();

        foreach ($this->envVars as $key => $value) {
            $this->line("{$key}={$value}");
        }

        return self::SUCCESS;
    }

    private function getDefinitions(): array
    {
        return [
            // Plans
            ['internal_id' => 'starter', 'product_name' => 'Starter Plan', 'price_cents' => 5900, 'env_key' => 'STRIPE_PRICE_STARTER'],
            ['internal_id' => 'pro', 'product_name' => 'Pro Plan', 'price_cents' => 19900, 'env_key' => 'STRIPE_PRICE_PRO'],
            ['internal_id' => 'business', 'product_name' => 'Business Plan', 'price_cents' => 59900, 'env_key' => 'STRIPE_PRICE_BUSINESS'],

            // Storage add-ons
            ['internal_id' => 'storage_100gb', 'product_name' => 'Storage Add-on: 100 GB', 'price_cents' => 1900, 'env_key' => 'STRIPE_PRICE_STORAGE_100GB'],
            ['internal_id' => 'storage_500gb', 'product_name' => 'Storage Add-on: 500 GB', 'price_cents' => 6900, 'env_key' => 'STRIPE_PRICE_STORAGE_500GB'],
            ['internal_id' => 'storage_1tb', 'product_name' => 'Storage Add-on: 1 TB', 'price_cents' => 12900, 'env_key' => 'STRIPE_PRICE_STORAGE_1TB'],

            // AI credit add-ons
            ['internal_id' => 'credits_500', 'product_name' => 'AI Credits Add-on: 500', 'price_cents' => 2900, 'env_key' => 'STRIPE_PRICE_CREDITS_500'],
            ['internal_id' => 'credits_2000', 'product_name' => 'AI Credits Add-on: 2,000', 'price_cents' => 8900, 'env_key' => 'STRIPE_PRICE_CREDITS_2000'],
            ['internal_id' => 'credits_10000', 'product_name' => 'AI Credits Add-on: 10,000', 'price_cents' => 34900, 'env_key' => 'STRIPE_PRICE_CREDITS_10000'],

            // Creator Module
            ['internal_id' => 'creator_module', 'product_name' => 'Creator Module', 'price_cents' => 9900, 'env_key' => 'STRIPE_PRICE_CREATOR_MODULE'],
            ['internal_id' => 'creator_seats_25', 'product_name' => 'Creator Seats: +25', 'price_cents' => 4900, 'env_key' => 'STRIPE_PRICE_CREATOR_SEATS_25'],
            ['internal_id' => 'creator_seats_100', 'product_name' => 'Creator Seats: +100', 'price_cents' => 14900, 'env_key' => 'STRIPE_PRICE_CREATOR_SEATS_100'],
        ];
    }
}
