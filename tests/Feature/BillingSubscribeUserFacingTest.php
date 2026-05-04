<?php

namespace Tests\Feature;

use App\Exceptions\BillingUserFacingException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BillingSubscribeUserFacingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_subscribe_maps_billing_user_facing_exception_to_safe_flash(): void
    {
        Permission::firstOrCreate(['name' => 'billing.manage', 'guard_name' => 'web']);

        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-sub', 'email' => 't@example.com']);
        $user = User::create([
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'O',
            'last_name' => 'W',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->givePermissionTo('billing.manage');

        $this->instance(BillingService::class, Mockery::mock(BillingService::class, function ($m) {
            $m->shouldReceive('createCheckoutSession')
                ->once()
                ->andThrow(new BillingUserFacingException(
                    'We couldn’t start checkout. Please try again, or contact support if the issue continues.',
                    'BILLING_CHECKOUT_FAILED',
                    ['tenant_id' => 1]
                ));
        }));

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->from('/app/billing')
            ->post('/app/billing/subscribe', [
                'price_id' => 'price_test_123',
                'plan_id' => 'starter',
            ]);

        $response->assertSessionHas('billing_error_code', 'BILLING_CHECKOUT_FAILED');
        $response->assertSessionHasErrors('subscription');
        $msg = (string) session('errors')?->first('subscription');
        $this->assertNotSame('', $msg);
        $this->assertStringNotContainsString('cus_', $msg);
    }
}
