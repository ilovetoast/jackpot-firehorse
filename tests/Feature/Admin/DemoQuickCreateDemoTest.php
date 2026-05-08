<?php

namespace Tests\Feature\Admin;

use App\Jobs\CreateDemoWorkspaceCloneJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoQuickCreateDemoTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithTemplate(): array
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);
        $admin = User::create([
            'email' => 'quick-demo-admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Q',
            'last_name' => 'A',
        ]);
        $admin->assignRole('site_admin');

        $template = Tenant::create([
            'name' => 'Gold Template',
            'slug' => 'gold-template',
            'is_demo_template' => true,
            'demo_label' => 'Gold',
            'demo_plan_key' => 'pro',
        ]);

        return [$admin, $template];
    }

    public function test_quick_create_forbidden_when_cloning_disabled(): void
    {
        config(['demo.cloning_enabled' => false]);
        [$admin, $template] = $this->adminWithTemplate();

        $this->actingAs($admin)
            ->postJson('/app/admin/demo-workspaces/quick-create', [
                'template_id' => $template->id,
                'plan_key' => 'pro',
                'expiration_days' => 7,
                'invited_emails' => [$admin->email],
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Demo cloning is disabled. Set DEMO_CLONING_ENABLED=true in the environment.',
            );
    }

    public function test_quick_create_dispatches_job_when_cloning_enabled(): void
    {
        config(['demo.cloning_enabled' => true]);
        Bus::fake();
        [$admin, $template] = $this->adminWithTemplate();

        $response = $this->actingAs($admin)->postJson('/app/admin/demo-workspaces/quick-create', [
            'template_id' => $template->id,
            'plan_key' => 'pro',
            'expiration_days' => 7,
            'invited_emails' => ['buyer@example.com', $admin->email],
            'target_demo_label' => 'ACME Trial',
        ]);

        $response->assertOk();
        $response->assertJsonPath('tenant.demo_status', 'pending');
        $response->assertJsonStructure([
            'gateway_url',
            'view_details_url',
            'tenant' => ['id', 'slug', 'name', 'demo_status'],
        ]);

        $tenantId = (int) $response->json('tenant.id');
        $this->assertGreaterThan(0, $tenantId);

        $demo = Tenant::query()->findOrFail($tenantId);
        $this->assertTrue($demo->is_demo);
        $this->assertFalse($demo->is_demo_template);
        $this->assertSame((int) $template->id, (int) $demo->demo_template_id);
        $this->assertSame('ACME Trial', $demo->name);

        Bus::assertDispatched(CreateDemoWorkspaceCloneJob::class, function (CreateDemoWorkspaceCloneJob $job) use ($tenantId, $admin): bool {
            return $job->demoTenantId === $tenantId
                && in_array('buyer@example.com', $job->invitedEmails, true)
                && in_array($admin->email, $job->invitedEmails, true);
        });
    }

    public function test_quick_create_rejects_invalid_email(): void
    {
        config(['demo.cloning_enabled' => true]);
        [$admin, $template] = $this->adminWithTemplate();

        $this->actingAs($admin)
            ->postJson('/app/admin/demo-workspaces/quick-create', [
                'template_id' => $template->id,
                'plan_key' => 'pro',
                'expiration_days' => 7,
                'invited_emails' => ['not-an-email'],
            ])
            ->assertUnprocessable();
    }

    public function test_quick_create_rejects_invalid_expiration(): void
    {
        config(['demo.cloning_enabled' => true]);
        [$admin, $template] = $this->adminWithTemplate();

        $this->actingAs($admin)
            ->postJson('/app/admin/demo-workspaces/quick-create', [
                'template_id' => $template->id,
                'plan_key' => 'pro',
                'expiration_days' => 99,
                'invited_emails' => [$admin->email],
            ])
            ->assertUnprocessable();
    }
}
