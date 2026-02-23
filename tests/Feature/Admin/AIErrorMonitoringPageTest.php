<?php

namespace Tests\Feature\Admin;

use App\Models\SentryIssue;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Error Monitoring admin page: page loads, admin only, rows display.
 */
class AIErrorMonitoringPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    public function test_page_loads_for_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $user->assignRole('site_admin');
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin/ai-error-monitoring');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AIErrorMonitoring/Index')
            ->has('config')
            ->has('issues')
        );
    }

    public function test_page_returns_403_for_non_admin(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Normal',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin/ai-error-monitoring');

        $response->assertStatus(403);
    }

    public function test_rows_display_when_issues_exist(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $user->assignRole('site_admin');
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        SentryIssue::create([
            'sentry_issue_id' => 'sentry-1',
            'environment' => 'staging',
            'level' => 'error',
            'title' => 'Test NullPointerException',
            'occurrence_count' => 5,
            'status' => 'open',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin/ai-error-monitoring');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AIErrorMonitoring/Index')
            ->where('issues.data.0.title', 'Test NullPointerException')
            ->where('issues.data.0.level', 'error')
            ->where('issues.data.0.status', 'open')
        );
    }

    public function test_confirm_button_visibility_toggles_based_on_config(): void
    {
        $tenant = Tenant::create(['name' => 'Test', 'slug' => 'test']);
        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Admin',
            'last_name' => 'User',
        ]);
        $user->assignRole('site_admin');
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        config(['sentry_ai.require_manual_confirmation' => true]);
        $responseTrue = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin/ai-error-monitoring');
        $responseTrue->assertStatus(200);
        $responseTrue->assertInertia(fn ($page) => $page
            ->component('Admin/AIErrorMonitoring/Index')
            ->where('config.require_confirmation', true)
        );

        config(['sentry_ai.require_manual_confirmation' => false]);
        $responseFalse = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id])
            ->get('/app/admin/ai-error-monitoring');
        $responseFalse->assertStatus(200);
        $responseFalse->assertInertia(fn ($page) => $page
            ->component('Admin/AIErrorMonitoring/Index')
            ->where('config.require_confirmation', false)
        );
    }
}
