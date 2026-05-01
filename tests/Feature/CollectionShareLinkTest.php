<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Password-protected collection share links (V1).
 */
class CollectionShareLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function makeTenantBrand(): array
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        // Tenant::created creates a default brand; do not add a second brand — free plan max_brands=1
        // would mark the extra brand disabled and /app/* would redirect to errors.brand-disabled.
        $brand = $tenant->brands()->where('is_default', true)->firstOrFail();
        $brand->update(['name' => 'B', 'slug' => 'b']);

        return [$tenant, $brand];
    }

    public function test_guest_cannot_view_legacy_is_public_without_password(): void
    {
        [$tenant, $brand] = $this->makeTenantBrand();
        $tenant->update(['manual_plan_override' => 'enterprise']);

        Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Legacy',
            'slug' => 'legacy',
            'visibility' => 'brand',
            'is_public' => true,
        ]);

        $this->get('/b/'.$brand->slug.'/collections/legacy')->assertStatus(404);
    }

    public function test_password_gate_then_unlocked_show(): void
    {
        [$tenant, $brand] = $this->makeTenantBrand();
        $tenant->update(['manual_plan_override' => 'enterprise']);

        $collection = Collection::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'name' => 'Shared',
            'slug' => 'shared',
            'visibility' => 'brand',
            'is_public' => true,
            'public_share_token' => 'tokuniquesharetest01',
            'public_password_hash' => Hash::make('correct-password'),
            'public_password_set_at' => now(),
        ]);

        $this->get('/share/collections/tokuniquesharetest01')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Public/ShareCollectionGate'));

        $this->post(route('share.collections.unlock', ['token' => 'tokuniquesharetest01']), [
            'password' => 'wrong',
            '_token' => csrf_token(),
        ])->assertSessionHasErrors('password');

        $this->post(route('share.collections.unlock', ['token' => 'tokuniquesharetest01']), [
            'password' => 'correct-password',
            '_token' => csrf_token(),
        ])->assertRedirect(route('share.collections.show', ['token' => 'tokuniquesharetest01']));

        $this->get('/share/collections/tokuniquesharetest01')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Public/Collection'));
    }

    public function test_cannot_enable_is_public_without_plan_feature(): void
    {
        [$tenant, $brand] = $this->makeTenantBrand();
        $tenant->update(['manual_plan_override' => 'free']);

        $user = User::create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'A',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/collections', [
                'name' => 'C',
                'access_mode' => 'all_brand',
                'is_public' => true,
                'public_password' => 'password1x',
                'public_password_confirmation' => 'password1x',
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_public');

        $this->assertDatabaseMissing('collections', [
            'brand_id' => $brand->id,
            'name' => 'C',
        ]);
    }

    public function test_store_sets_password_hash_and_token(): void
    {
        [$tenant, $brand] = $this->makeTenantBrand();
        $tenant->update(['manual_plan_override' => 'enterprise']);

        $user = User::create([
            'email' => 'admin2@example.com',
            'password' => bcrypt('password'),
            'first_name' => 'A',
            'last_name' => 'A',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->tenants()->attach($tenant->id, ['role' => 'member']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson('/app/collections', [
                'name' => 'Shared create',
                'access_mode' => 'all_brand',
                'is_public' => true,
                'public_password' => 'password1x',
                'public_password_confirmation' => 'password1x',
                'public_downloads_enabled' => true,
            ], [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])->assertCreated();

        $c = Collection::query()->where('name', 'Shared create')->first();
        $this->assertNotNull($c->public_share_token);
        $this->assertNotNull($c->public_password_hash);
        $this->assertTrue(Hash::check('password1x', $c->public_password_hash));
    }
}
