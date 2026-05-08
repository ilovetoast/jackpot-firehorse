<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoWorkspacesAdminTest extends TestCase
{
    use RefreshDatabase;

    private function seedTemplateAndAdmin(): array
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);

        $admin = User::create([
            'email' => 'sysadmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'S',
            'last_name' => 'A',
        ]);
        $admin->assignRole('site_admin');

        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl',
            'is_demo_template' => true,
            'demo_label' => 'Master',
            'demo_plan_key' => 'pro',
        ]);

        return [$admin, $template];
    }

    public function test_site_admin_can_view_demo_workspaces_index(): void
    {
        [$admin, $template] = $this->seedTemplateAndAdmin();

        Tenant::create([
            'name' => 'Inst',
            'slug' => 'inst',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.demo-workspaces.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/DemoWorkspaces/Index')
            ->has('demo_templates', 1)
            ->has('demo_instances', 1)
            ->has('filters')
            ->has('plan_options')
            ->has('creator_options')
            ->has('quick_create')
        );
    }

    public function test_non_admin_cannot_view_demo_workspaces_index(): void
    {
        $user = User::create([
            'email' => 'plain@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'P',
            'last_name' => 'U',
        ]);

        $this->actingAs($user)
            ->get(route('admin.demo-workspaces.index'))
            ->assertForbidden();
    }

    public function test_admin_can_filter_demo_instances_by_scope_plan_and_creator(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

        [$admin, $template] = $this->seedTemplateAndAdmin();

        $creator = User::create([
            'email' => 'creator@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'C',
            'last_name' => 'R',
        ]);

        Tenant::create([
            'name' => 'Active Pro',
            'slug' => 'active-pro',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'active',
            'demo_expires_at' => Carbon::parse('2026-07-01', 'UTC'),
            'demo_created_by_user_id' => $creator->id,
        ]);

        Tenant::create([
            'name' => 'Failed Demo',
            'slug' => 'failed-demo',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'failed',
            'demo_clone_failure_message' => 'S3 timeout',
        ]);

        Tenant::create([
            'name' => 'Expired Demo',
            'slug' => 'expired-demo',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'expired',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.index', ['instance_scope' => 'failed']))
            ->assertInertia(fn ($page) => $page->has('demo_instances', 1)
                ->where('demo_instances.0.slug', 'failed-demo'));

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.index', ['instance_scope' => 'expired']))
            ->assertInertia(fn ($page) => $page->has('demo_instances', 1)
                ->where('demo_instances.0.slug', 'expired-demo'));

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.index', [
                'instance_scope' => 'active',
                'plan_key' => 'pro',
                'created_by_user_id' => $creator->id,
            ]))
            ->assertInertia(fn ($page) => $page->has('demo_instances', 1)
                ->where('demo_instances.0.slug', 'active-pro'));

        Carbon::setTestNow();
    }

    public function test_admin_can_extend_demo_expiration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));

        [$admin, $template] = $this->seedTemplateAndAdmin();

        $demo = Tenant::create([
            'name' => 'Extend Me',
            'slug' => 'extend-me',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'active',
            'demo_expires_at' => Carbon::parse('2026-06-10', 'UTC')->startOfDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.demo-workspaces.extend', $demo), ['days' => 7])
            ->assertRedirect(route('admin.demo-workspaces.show', $demo));

        $demo->refresh();
        $this->assertSame('active', $demo->demo_status);
        $this->assertSame('2026-06-17', $demo->demo_expires_at->toDateString());

        Carbon::setTestNow();
    }

    public function test_admin_can_manually_expire_demo(): void
    {
        [$admin, $template] = $this->seedTemplateAndAdmin();

        $demo = Tenant::create([
            'name' => 'Expire Me',
            'slug' => 'expire-me',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'active',
            'demo_expires_at' => now()->addDays(5)->startOfDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.demo-workspaces.expire', $demo))
            ->assertRedirect(route('admin.demo-workspaces.show', $demo));

        $demo->refresh();
        $this->assertSame('expired', $demo->demo_status);
        $this->assertNotNull($demo->demo_expires_at);
    }

    public function test_admin_can_archive_failed_demo(): void
    {
        [$admin, $template] = $this->seedTemplateAndAdmin();

        $demo = Tenant::create([
            'name' => 'Bad Clone',
            'slug' => 'bad-clone',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'failed',
            'demo_clone_failure_message' => 'Disk full',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.demo-workspaces.archive-failed', $demo))
            ->assertRedirect(route('admin.demo-workspaces.show', $demo));

        $demo->refresh();
        $this->assertSame('archived', $demo->demo_status);
        $this->assertSame('Disk full', $demo->demo_clone_failure_message);
    }

    public function test_non_admin_cannot_access_demo_admin_actions(): void
    {
        [$admin, $template] = $this->seedTemplateAndAdmin();

        $demo = Tenant::create([
            'name' => 'Demo',
            'slug' => 'demo-x',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'active',
        ]);

        $user = User::create([
            'email' => 'nope@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'N',
            'last_name' => 'N',
        ]);

        $this->actingAs($user)->get(route('admin.demo-workspaces.show', $demo))->assertForbidden();
        $this->actingAs($user)->post(route('admin.demo-workspaces.extend', $demo), ['days' => 7])->assertForbidden();
        $this->actingAs($user)->post(route('admin.demo-workspaces.expire', $demo))->assertForbidden();
        $this->actingAs($user)->post(route('admin.demo-workspaces.archive-failed', $demo))->assertForbidden();
    }

    public function test_demo_detail_not_found_for_template_tenant(): void
    {
        [$admin, $template] = $this->seedTemplateAndAdmin();

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.show', $template))
            ->assertNotFound();
    }

    public function test_site_admin_can_view_demo_instance_detail(): void
    {
        [$admin, $template] = $this->seedTemplateAndAdmin();

        $demo = Tenant::create([
            'name' => 'Detail Demo',
            'slug' => 'detail-demo',
            'is_demo' => true,
            'demo_template_id' => $template->id,
            'demo_plan_key' => 'pro',
            'demo_status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.show', $demo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DemoWorkspaces/Show')
                ->where('tenant.id', $demo->id)
                ->has('counts')
                ->has('storage')
                ->has('timeline')
            );
    }
}
