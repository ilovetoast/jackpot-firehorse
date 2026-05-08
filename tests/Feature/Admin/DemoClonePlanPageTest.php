<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoClonePlanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_admin_can_open_clone_plan_form(): void
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
            'slug' => 'tpl-ui',
            'is_demo_template' => true,
            'demo_label' => 'Master',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.clone-plan', $template))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DemoWorkspaces/ClonePlan')
                ->has('template')
                ->where('clone_plan', null)
            );
    }

    public function test_preview_returns_plan_without_persisting_new_tenant(): void
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);

        $admin = User::create([
            'email' => 'sysadmin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'S',
            'last_name' => 'A',
        ]);
        $admin->assignRole('site_admin');

        $before = Tenant::query()->count();

        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-post',
            'is_demo_template' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.demo-workspaces.clone-plan.preview', $template), [
                'target_demo_label' => 'ACME demo',
                'plan_key' => 'pro',
                'expiration_days' => 7,
                'invited_emails_text' => 'a@example.com, b@example.com',
            ])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/DemoWorkspaces/ClonePlan')
                ->has('clone_plan.meta')
                ->where('clone_plan.meta.target_demo_label', 'ACME demo')
            );

        $this->assertSame($before + 1, Tenant::query()->count());
    }

    public function test_preview_validates_emails(): void
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
            'slug' => 'tpl-bad-mail',
            'is_demo_template' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.demo-workspaces.clone-plan.preview', $template), [
                'target_demo_label' => 'X',
                'plan_key' => 'pro',
                'expiration_days' => 7,
                'invited_emails_text' => 'bad',
            ])
            ->assertSessionHasErrors();
    }

    public function test_non_admin_gets_forbidden(): void
    {
        $user = User::create([
            'email' => 'plain@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'P',
            'last_name' => 'U',
        ]);

        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-403',
            'is_demo_template' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.demo-workspaces.clone-plan', $template))
            ->assertForbidden();
    }
}
