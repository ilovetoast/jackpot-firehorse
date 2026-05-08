<?php

namespace Tests\Unit\Services;

use App\Services\Billing\StripeCustomerVerifier;
use App\Services\BillingService;
use Tests\TestCase;

class BillingServicePlanSwapTest extends TestCase
{
    /**
     * Cashier swap() replaces all subscription items; we must merge add-on price IDs with the new plan price.
     */
    public function test_merge_plan_change_keeps_addon_prices_and_sets_new_plan(): void
    {
        $verifier = \Mockery::mock(StripeCustomerVerifier::class);

        $svc = new class($verifier) extends BillingService
        {
            public function mergeForTest(array $current, string $newPlanPrice): array
            {
                return $this->mergePlanChangePriceIds($current, $newPlanPrice);
            }

            /** @return list<string> */
            public function basePlanIdsForTest(): array
            {
                return $this->basePlanStripePriceIds();
            }
        };

        $starter = (string) config('plans.starter.stripe_price_id');
        $pro = (string) config('plans.pro.stripe_price_id');
        $storage100 = (string) config('billing_stripe.stripe_prices.addons.storage_100gb');

        $this->assertNotSame('', $starter);
        $this->assertNotSame('', $pro);
        $this->assertNotSame('', $storage100);

        $this->assertContains($starter, $svc->basePlanIdsForTest());
        $this->assertContains($pro, $svc->basePlanIdsForTest());

        $merged = $svc->mergeForTest([$starter, $storage100], $pro);
        sort($merged);
        $expected = [$pro, $storage100];
        sort($expected);
        $this->assertSame($expected, $merged);
    }

    public function test_merge_plan_change_single_line_replaces_plan_only(): void
    {
        $verifier = \Mockery::mock(StripeCustomerVerifier::class);

        $svc = new class($verifier) extends BillingService
        {
            public function mergeForTest(array $current, string $newPlanPrice): array
            {
                return $this->mergePlanChangePriceIds($current, $newPlanPrice);
            }
        };

        $starter = (string) config('plans.starter.stripe_price_id');
        $pro = (string) config('plans.pro.stripe_price_id');

        $this->assertSame([$pro], $svc->mergeForTest([$starter], $pro));
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }
}
