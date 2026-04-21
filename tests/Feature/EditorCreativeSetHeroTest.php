<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorCreativeSetHeroTest extends TestCase
{
    use RefreshDatabase;

    public function test_patch_hero_sets_and_clears(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $c1 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C1',
            'document_json' => ['layers' => []],
        ]);
        $c2 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C2',
            'document_json' => ['layers' => []],
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'Set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'B',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->patchJson("/app/api/creative-sets/{$set->id}/hero", ['composition_id' => $c2->id])
            ->assertOk()
            ->assertJsonPath('creative_set.hero_composition_id', (string) $c2->id);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->patchJson("/app/api/creative-sets/{$set->id}/hero", ['composition_id' => null])
            ->assertOk()
            ->assertJsonPath('creative_set.hero_composition_id', null);
    }

    public function test_patch_hero_rejects_non_member_composition(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B2', 'slug' => 'b2']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $c1 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C1',
            'document_json' => ['layers' => []],
        ]);
        $other = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'Other',
            'document_json' => ['layers' => []],
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'Set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'A',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->patchJson("/app/api/creative-sets/{$set->id}/hero", ['composition_id' => $other->id])
            ->assertStatus(422);
    }
}
