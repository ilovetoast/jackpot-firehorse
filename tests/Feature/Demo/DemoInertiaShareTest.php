<?php

namespace Tests\Feature\Demo;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoInertiaShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_workspace_shared_prop_on_app_shell(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-06 12:00:00', 'UTC'));

        $tenant = Tenant::create([
            'name' => 'Demo Shared',
            'slug' => 'demo-shared',
            'is_demo' => true,
            'demo_label' => 'ACME pilot',
            'demo_expires_at' => Carbon::parse('2026-05-10 00:00:00', 'UTC'),
        ]);
        $brand = $tenant->defaultBrand;
        $this->assertNotNull($brand);

        $user = User::create([
            'email' => 'owner-overview@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->get(route('app'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Company/Overview')
            ->has('demo_workspace')
            ->where('demo_workspace.is_demo', true)
            ->where('demo_workspace.is_demo_template', false)
            ->where('demo_workspace.label', 'ACME pilot')
            ->where('demo_workspace.days_remaining', 4)
            ->where('demo_workspace.expired', false)
        );

        Carbon::setTestNow();
    }
}
