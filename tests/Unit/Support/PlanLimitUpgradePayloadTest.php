<?php

namespace Tests\Unit\Support;

use App\Models\Tenant;
use App\Support\Billing\PlanLimitUpgradePayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitUpgradePayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_solves_max_upload_when_cap_exceeds_attempted(): void
    {
        $this->assertTrue(PlanLimitUpgradePayload::planSolvesMaxUploadMb(50, 34.2));
        $this->assertFalse(PlanLimitUpgradePayload::planSolvesMaxUploadMb(25, 34.2));
    }

    public function test_plan_solves_max_upload_for_config_style_unlimited_cap(): void
    {
        $this->assertTrue(PlanLimitUpgradePayload::planSolvesMaxUploadMb(999999, 5000.0));
    }

    public function test_build_for_upload_size_contains_expected_keys_and_url(): void
    {
        $tenant = Tenant::create([
            'name' => 'Limit Payload Tenant',
            'slug' => 'limit-payload-tenant',
            'manual_plan_override' => 'free',
        ]);

        $bytes = (int) (34.2 * 1024 * 1024);
        $payload = PlanLimitUpgradePayload::buildForUploadSizeExceeded($tenant, $bytes);

        $this->assertSame('plan_limit_exceeded', $payload['error_code']);
        $this->assertSame('max_upload_size', $payload['limit_key']);
        $this->assertSame('free', $payload['current_plan_key']);
        $this->assertSame('Free', $payload['current_plan_name']);
        $this->assertArrayHasKey('upgrade_url', $payload);
        $this->assertStringContainsString('reason=max_upload_size', $payload['upgrade_url']);
        $this->assertStringContainsString('current_plan=free', $payload['upgrade_url']);
    }
}
