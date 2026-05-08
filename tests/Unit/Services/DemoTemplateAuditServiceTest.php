<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\User;
use App\Services\Demo\DemoTemplateAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DemoTemplateAuditServiceTest extends TestCase
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
        app(DemoTemplateAuditService::class)->audit($tenant);
    }

    public function test_audit_returns_expected_structure_for_template(): void
    {
        $template = Tenant::create([
            'name' => 'Template Co',
            'slug' => 'template-co',
            'is_demo_template' => true,
        ]);

        $report = app(DemoTemplateAuditService::class)->audit($template);

        $this->assertSame($template->id, $report['meta']['tenant_id']);
        $this->assertTrue($report['meta']['is_demo_template']);
        $this->assertArrayHasKey('clone_ready', $report);
        $this->assertArrayHasKey('excluded_from_clone', $report);
        $this->assertArrayHasKey('warnings', $report);
        $this->assertArrayHasKey('unsupported_relationships', $report);
        $this->assertArrayHasKey('missing_required_data', $report);
        $this->assertArrayHasKey('storage', $report);

        $this->assertSame(1, $report['clone_ready']['tenant_record']);
        $this->assertSame(1, $report['clone_ready']['brands']);
        $this->assertArrayHasKey('categories', $report['clone_ready']);
    }

    public function test_excludes_tenant_invitations_from_clone_counts(): void
    {
        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-inv',
            'is_demo_template' => true,
        ]);

        $inviter = User::create([
            'email' => 'inv@example.com',
            'password' => bcrypt('x'),
            'first_name' => 'I',
            'last_name' => 'V',
        ]);

        TenantInvitation::create([
            'tenant_id' => $template->id,
            'email' => 'pending@example.com',
            'role' => 'member',
            'token' => str_repeat('a', 64),
            'invited_by' => $inviter->id,
            'brand_assignments' => null,
            'sent_at' => now(),
        ]);

        $report = app(DemoTemplateAuditService::class)->audit($template);

        $this->assertGreaterThanOrEqual(1, $report['excluded_from_clone']['tenant_invitations']);
    }
}
