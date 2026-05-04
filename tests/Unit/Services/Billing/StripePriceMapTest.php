<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\StripePriceMap;
use InvalidArgumentException;
use Tests\TestCase;

final class StripePriceMapTest extends TestCase
{
    public function test_assert_unique_price_ids_passes_for_default_config(): void
    {
        StripePriceMap::assertUniquePriceIds();
        $this->addToAssertionCount(1);
    }

    public function test_every_plan_resolves_to_stripe_price_id(): void
    {
        $map = new StripePriceMap;

        foreach (array_keys(config('billing_stripe.stripe_prices.plans', [])) as $planKey) {
            $id = $map->priceIdForPlan((string) $planKey);
            $this->assertIsString($id);
            $this->assertStringStartsWith('price_', $id, "Plan {$planKey} should have a Stripe price id");
        }
    }

    public function test_every_addon_resolves_to_stripe_price_id(): void
    {
        $map = new StripePriceMap;

        foreach (array_keys(config('billing_stripe.stripe_prices.addons', [])) as $addonKey) {
            $id = $map->priceIdForAddon((string) $addonKey);
            $this->assertIsString($id);
            $this->assertStringStartsWith('price_', $id, "Add-on {$addonKey} should have a Stripe price id");
        }
    }

    public function test_unknown_plan_key_returns_null(): void
    {
        $map = new StripePriceMap;

        $this->assertNull($map->priceIdForPlan('nonexistent_plan_key'));
    }

    public function test_unknown_addon_key_returns_null(): void
    {
        $map = new StripePriceMap;

        $this->assertNull($map->priceIdForAddon('nonexistent_addon_key'));
    }

    public function test_resolve_key_for_price_id_round_trip(): void
    {
        $map = new StripePriceMap;
        $starter = $map->priceIdForPlan('starter');
        $this->assertNotNull($starter);

        $resolved = $map->resolveKeyForPriceId($starter);
        $this->assertSame(['kind' => 'plan', 'key' => 'starter'], $resolved);

        $storage = $map->priceIdForAddon('storage_100gb');
        $this->assertNotNull($storage);
        $this->assertSame(
            ['kind' => 'addon', 'key' => 'storage_100gb'],
            $map->resolveKeyForPriceId($storage)
        );
    }

    public function test_unknown_price_id_resolves_to_null(): void
    {
        $map = new StripePriceMap;

        $this->assertNull($map->resolveKeyForPriceId('price_does_not_exist_in_map_xyz'));
    }

    public function test_ai_credits_pack_ids_resolve(): void
    {
        $map = new StripePriceMap;

        foreach (['credits_500', 'credits_2000', 'credits_10000'] as $packId) {
            $id = $map->priceIdForAiCreditsPackId($packId);
            $this->assertIsString($id);
            $this->assertStringStartsWith('price_', $id);
        }
    }

    public function test_ai_credits_unknown_pack_throws(): void
    {
        $map = new StripePriceMap;

        $this->expectException(InvalidArgumentException::class);
        $map->priceIdForAiCreditsPackId('credits_invalid');
    }

    public function test_duplicate_price_ids_throw(): void
    {
        $dup = 'price_duplicate_for_test';
        $original = config('billing_stripe');
        config([
            'billing_stripe.stripe_prices.plans.starter' => $dup,
            'billing_stripe.stripe_prices.plans.pro' => $dup,
        ]);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Duplicate Stripe price ID');
            StripePriceMap::assertUniquePriceIds();
        } finally {
            config(['billing_stripe' => $original]);
        }
    }
}
