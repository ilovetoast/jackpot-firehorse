<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoTemplateAuditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_admin_can_view_template_audit_page(): void
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);

        $admin = User::create([
            'email' => 'sa@example.com',
            'password' => bcrypt('p'),
            'first_name' => 'S',
            'last_name' => 'A',
        ]);
        $admin->assignRole('site_admin');

        $template = Tenant::create([
            'name' => 'Audit Me',
            'slug' => 'audit-me',
            'is_demo_template' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.demo-workspaces.template-audit', $template));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/DemoWorkspaces/TemplateAudit')
            ->has('report.meta')
            ->where('report.meta.tenant_id', $template->id)
        );
    }

    public function test_audit_returns_404_when_tenant_is_not_demo_template(): void
    {
        Role::firstOrCreate(['name' => 'site_admin', 'guard_name' => 'web']);

        $admin = User::create([
            'email' => 'sa2@example.com',
            'password' => bcrypt('p'),
            'first_name' => 'S',
            'last_name' => 'A',
        ]);
        $admin->assignRole('site_admin');

        $normal = Tenant::create([
            'name' => 'Normal',
            'slug' => 'normal-co',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.demo-workspaces.template-audit', $normal))
            ->assertNotFound();
    }

    public function test_non_admin_cannot_view_template_audit(): void
    {
        $user = User::create([
            'email' => 'plain@example.com',
            'password' => bcrypt('p'),
            'first_name' => 'P',
            'last_name' => 'L',
        ]);

        $template = Tenant::create([
            'name' => 'Tpl',
            'slug' => 'tpl-x',
            'is_demo_template' => true,
        ]);

        $this->actingAs($user)
            ->get(route('admin.demo-workspaces.template-audit', $template))
            ->assertForbidden();
    }
}
