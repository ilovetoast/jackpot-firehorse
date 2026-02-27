<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * First-time login: tenant and default (or first) brand are set in session without lazy-load errors.
 */
class FirstTimeLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_time_login_sets_tenant_and_default_brand_in_session(): void
    {
        $tenant = Tenant::create([
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);

        $defaultBrand = $tenant->brands()->where('is_default', true)->first();
        $this->assertNotNull($defaultBrand, 'Tenant boot should create a default brand');

        $user = User::create([
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'admin']);
        $defaultBrand->users()->syncWithoutDetaching([$user->id => ['role' => 'admin']]);

        $response = $this->post(route('login'), [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertTrue(str_contains($response->headers->get('Location'), '/app/dashboard') || str_contains($response->headers->get('Location'), '/app'));
        $response->assertSessionHas('tenant_id', $tenant->id);
        $response->assertSessionHas('brand_id', $defaultBrand->id);
    }

    public function test_first_time_login_uses_first_brand_when_none_marked_default(): void
    {
        $tenant = Tenant::create([
            'name' => 'Legacy Corp',
            'slug' => 'legacy-corp',
        ]);

        $bootBrand = $tenant->brands()->where('is_default', true)->first();
        $this->assertNotNull($bootBrand);
        $bootBrand->update(['is_default' => false]);

        $firstBrand = $tenant->brands()->orderBy('id')->first();
        $this->assertNotNull($firstBrand);

        $user = User::create([
            'email' => 'legacy@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'Legacy',
            'last_name' => 'User',
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $firstBrand->users()->syncWithoutDetaching([$user->id => ['role' => 'viewer']]);

        $response = $this->post(route('login'), [
            'email' => 'legacy@example.com',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('tenant_id', $tenant->id);
        $response->assertSessionHas('brand_id', $firstBrand->id);
    }
}
