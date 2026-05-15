<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectAuthenticatedFromMarketingSurface;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingAuthenticatedRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_load_home_without_redirect(): void
    {
        $this->get('/')
            ->assertOk();
    }

    public function test_authenticated_user_is_redirected_from_home_to_gateway(): void
    {
        $tenant = Tenant::create([
            'name' => 'Co',
            'slug' => 'co',
        ]);
        $user = User::create([
            'email' => 'm@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'K',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('gateway'));
    }

    public function test_marketing_site_query_sets_bypass_and_strips_url(): void
    {
        $tenant = Tenant::create([
            'name' => 'Co2',
            'slug' => 'co2',
        ]);
        $user = User::create([
            'email' => 'm2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'K',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);

        $this->actingAs($user)
            ->get('/?marketing_site=1')
            ->assertRedirect('/');

        $this->assertTrue(session(RedirectAuthenticatedFromMarketingSurface::SESSION_KEY));
    }

    public function test_authenticated_user_with_active_session_is_redirected_to_overview(): void
    {
        $tenant = Tenant::create([
            'name' => 'Co-Active',
            'slug' => 'co-active',
        ]);
        $user = User::create([
            'email' => 'active@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'U',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        // Simulate an active workspace session (already picked tenant + brand).
        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => 99])
            ->get('/')
            ->assertRedirect('/app/overview');
    }

    public function test_any_app_request_clears_marketing_bypass(): void
    {
        $tenant = Tenant::create([
            'name' => 'Co3',
            'slug' => 'co3',
        ]);
        $user = User::create([
            'email' => 'm3@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'M',
            'last_name' => 'K',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession([RedirectAuthenticatedFromMarketingSurface::SESSION_KEY => true])
            ->get('/app/companies')
            ->assertOk();

        $this->assertFalse(session()->has(RedirectAuthenticatedFromMarketingSurface::SESSION_KEY));

        $this->actingAs($user)
            ->get('/')
            ->assertRedirect(route('gateway'));
    }
}
