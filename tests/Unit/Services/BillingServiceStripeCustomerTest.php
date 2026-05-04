<?php

namespace Tests\Unit\Services;

use App\Exceptions\BillingUserFacingException;
use App\Models\Tenant;
use App\Services\Billing\StripeCustomerVerifier;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Stripe\Exception\ApiErrorException;
use Tests\TestCase;

class BillingServiceStripeCustomerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_clears_stale_stripe_id_when_customer_missing_and_no_active_subscription(): void
    {
        $tenant = Tenant::create(['name' => 'Co A', 'slug' => 'co-a', 'email' => 'a@example.com']);
        $tenant->stripe_id = 'cus_stale';
        $tenant->saveQuietly();

        $verifier = Mockery::mock(StripeCustomerVerifier::class);
        $verifier->shouldReceive('customerExistsInStripeAccount')
            ->once()
            ->with('cus_stale')
            ->andReturnFalse();

        $svc = new class($verifier) extends BillingService {
            public function __construct(StripeCustomerVerifier $v)
            {
                parent::__construct($v);
            }

            protected function createLocalStripeCustomer(Tenant $tenant): void
            {
                $tenant->stripe_id = 'cus_new';
                $tenant->saveQuietly();
            }
        };

        $result = $svc->ensureValidStripeCustomer($tenant, 99, ['plan_key' => 'starter']);

        $this->assertTrue($result['recovered_stale']);
        $this->assertTrue($result['created_new']);
        $this->assertSame('cus_new', $tenant->fresh()->stripe_id);
    }

    public function test_keeps_valid_stripe_id_without_calling_create(): void
    {
        $tenant = Tenant::create(['name' => 'Co B', 'slug' => 'co-b', 'email' => 'b@example.com']);
        $tenant->stripe_id = 'cus_good';
        $tenant->saveQuietly();

        $verifier = Mockery::mock(StripeCustomerVerifier::class);
        $verifier->shouldReceive('customerExistsInStripeAccount')
            ->once()
            ->with('cus_good')
            ->andReturnTrue();

        $svc = new class($verifier) extends BillingService {
            public function __construct(StripeCustomerVerifier $v)
            {
                parent::__construct($v);
            }

            protected function createLocalStripeCustomer(Tenant $tenant): void
            {
                $this->fail('createLocalStripeCustomer should not run when Stripe customer exists');
            }
        };

        $result = $svc->ensureValidStripeCustomer($tenant);

        $this->assertFalse($result['recovered_stale']);
        $this->assertFalse($result['created_new']);
        $this->assertSame('cus_good', $tenant->fresh()->stripe_id);
    }

    public function test_refuses_to_clear_when_local_subscription_looks_active(): void
    {
        $tenant = Tenant::create(['name' => 'Co C', 'slug' => 'co-c', 'email' => 'c@example.com']);
        $tenant->stripe_id = 'cus_bad';
        $tenant->saveQuietly();

        DB::table('subscriptions')->insert([
            'tenant_id' => $tenant->id,
            'type' => 'default',
            'name' => 'default',
            'stripe_id' => 'sub_test_1',
            'stripe_status' => 'active',
            'stripe_price' => null,
            'quantity' => null,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $verifier = Mockery::mock(StripeCustomerVerifier::class);
        $verifier->shouldReceive('customerExistsInStripeAccount')
            ->once()
            ->with('cus_bad')
            ->andReturnFalse();

        $svc = new BillingService($verifier);

        $this->expectException(BillingUserFacingException::class);
        $this->expectExceptionMessage('verify billing');

        try {
            $svc->ensureValidStripeCustomer($tenant);
        } finally {
            $this->assertSame('cus_bad', $tenant->fresh()->stripe_id);
        }
    }

    public function test_non_missing_stripe_errors_are_not_swallowed(): void
    {
        $tenant = Tenant::create(['name' => 'Co D', 'slug' => 'co-d', 'email' => 'd@example.com']);
        $tenant->stripe_id = 'cus_x';
        $tenant->saveQuietly();

        $apiEx = Mockery::mock(ApiErrorException::class);
        $apiEx->shouldReceive('getStripeCode')->andReturn('rate_limit');
        $apiEx->shouldReceive('getMessage')->andReturn('Too many requests');

        $verifier = Mockery::mock(StripeCustomerVerifier::class);
        $verifier->shouldReceive('customerExistsInStripeAccount')
            ->once()
            ->andThrow($apiEx);

        $svc = new BillingService($verifier);

        $this->expectException(ApiErrorException::class);

        $svc->ensureValidStripeCustomer($tenant);
    }

    public function test_creates_customer_when_stripe_id_empty_without_calling_verifier(): void
    {
        $tenant = Tenant::create(['name' => 'Co E', 'slug' => 'co-e', 'email' => 'e@example.com']);

        $verifier = Mockery::mock(StripeCustomerVerifier::class);
        $verifier->shouldNotReceive('customerExistsInStripeAccount');

        $svc = new class($verifier) extends BillingService {
            public function __construct(StripeCustomerVerifier $v)
            {
                parent::__construct($v);
            }

            protected function createLocalStripeCustomer(Tenant $tenant): void
            {
                $tenant->stripe_id = 'cus_bootstrapped';
                $tenant->saveQuietly();
            }
        };

        config(['services.stripe.secret' => 'sk_test_fake']);

        $result = $svc->ensureValidStripeCustomer($tenant, 5);

        $this->assertTrue($result['created_new']);
        $this->assertFalse($result['recovered_stale']);
        $this->assertSame('cus_bootstrapped', $tenant->fresh()->stripe_id);
    }
}
