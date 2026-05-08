<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Services\Demo\DemoClonePlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class DemoClonePlanServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_non_template_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Regular',
            'slug' => 'regular',
            'is_demo' => true,
            'is_demo_template' => false,
        ]);

        $this->expectException(InvalidArgumentException::class);
        app(DemoClonePlanService::class)->plan($tenant, 'Label', 'pro', 7, []);
    }

    public function test_plan_includes_cloneable_content_counts_and_skips_billing_ephemeral(): void
    {
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-plan',
            'is_demo_template' => true,
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        $report = app(DemoClonePlanService::class)->plan($template, 'Sales demo', 'pro', 14, ['buyer@example.com']);

        $this->assertSame('Sales demo', $report['meta']['target_demo_label']);
        $this->assertSame('pro', $report['meta']['plan_key']);
        $this->assertSame(14, $report['meta']['expiration_days']);
        $this->assertSame(['buyer@example.com'], $report['meta']['invited_emails']);
        $this->assertTrue($report['meta']['dry_run']);

        $this->assertArrayHasKey('brands', $report['would_clone']['content_row_counts']);
        $this->assertArrayHasKey('assets_active', $report['would_clone']['content_row_counts']);

        $excluded = $report['would_skip']['excluded_row_counts'];
        $this->assertArrayHasKey('subscriptions', $excluded);
        $this->assertArrayHasKey('activity_events', $excluded);
        $this->assertArrayHasKey('upload_sessions', $excluded);
        $this->assertArrayHasKey('downloads', $excluded);

        $ops = $report['would_skip']['operational_ephemeral'];
        $this->assertContains('notifications', $ops);
        $this->assertContains('downloads_share_links', $ops);

        $this->assertSame('copy_objects', $report['storage_strategy']['recommended']);
        $this->assertArrayHasKey('estimated_clone_bytes', $report['storage_strategy']);
        $this->assertArrayHasKey('rejected_alternative', $report['storage_strategy']);
    }

    public function test_dry_run_allowed_when_cloning_disabled(): void
    {
        config(['demo.cloning_enabled' => false]);

        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-off',
            'is_demo_template' => true,
        ]);

        $report = app(DemoClonePlanService::class)->plan($template, 'X', 'starter', 7, []);

        $this->assertFalse($report['meta']['cloning_enabled_config']);
        $this->assertNotEmpty($report['warnings']);
        $this->assertTrue(collect($report['warnings'])->contains(fn ($w) => str_contains((string) $w, 'cloning_enabled')));
    }

    public function test_invalid_email_fails_validation(): void
    {
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-mail',
            'is_demo_template' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(DemoClonePlanService::class)->plan($template, 'L', 'pro', 7, ['not-an-email']);
    }

    public function test_invalid_expiration_fails_validation(): void
    {
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-exp',
            'is_demo_template' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(DemoClonePlanService::class)->plan($template, 'L', 'pro', 30, []);
    }

    public function test_unknown_plan_key_fails_validation(): void
    {
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-plankey',
            'is_demo_template' => true,
        ]);

        try {
            app(DemoClonePlanService::class)->plan($template, 'L', 'plan_that_does_not_exist_xyz', 7, []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('plan_key', $e->errors());
        }
    }
}
