<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Services\Demo\DemoTenantService;
use App\Support\DemoGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DemoTenantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_demo_tenant_includes_instance_and_template(): void
    {
        $svc = app(DemoTenantService::class);

        $normal = Tenant::create(['name' => 'N', 'slug' => 'n']);
        $this->assertFalse($svc->isDemoTenant($normal));

        $demo = Tenant::create(['name' => 'D', 'slug' => 'd', 'is_demo' => true]);
        $this->assertTrue($svc->isDemoTenant($demo));

        $tpl = Tenant::create(['name' => 'T', 'slug' => 't', 'is_demo_template' => true]);
        $this->assertTrue($svc->isDemoTenant($tpl));
    }

    public function test_demo_restriction_message_for_billing_on_demo_only(): void
    {
        $svc = app(DemoTenantService::class);
        $normal = Tenant::create(['name' => 'N', 'slug' => 'n']);
        $demo = Tenant::create(['name' => 'D', 'slug' => 'd', 'is_demo' => true]);

        $this->assertNull($svc->demoRestrictionMessage(DemoTenantService::ACTION_BILLING_CHANGE, $normal));
        $this->assertSame(
            DemoTenantService::DISABLED_MESSAGE,
            $svc->demoRestrictionMessage(DemoTenantService::ACTION_BILLING_CHANGE, $demo)
        );
    }

    public function test_assert_demo_can_perform_throws_for_blocked_action(): void
    {
        $svc = app(DemoTenantService::class);
        $demo = Tenant::create(['name' => 'D', 'slug' => 'd', 'is_demo' => true]);

        $this->expectException(ValidationException::class);
        $svc->assertDemoCanPerform(DemoTenantService::ACTION_GENERATE_API_KEY, $demo);
    }

    public function test_demo_guard_delegates(): void
    {
        $demo = Tenant::create(['name' => 'D', 'slug' => 'd', 'is_demo' => true]);
        $this->assertTrue(DemoGuard::isDemoTenant($demo));

        $this->expectException(ValidationException::class);
        DemoGuard::assertDemoCanPerform(DemoTenantService::ACTION_EXTERNAL_INTEGRATION, $demo);
    }
}
