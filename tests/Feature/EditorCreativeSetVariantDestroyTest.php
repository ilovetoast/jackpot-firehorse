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

class EditorCreativeSetVariantDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_archives_non_base_variant(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-vd']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-vd']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $c1 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'Base',
            'document_json' => ['layers' => []],
        ]);
        $c2 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'Branch',
            'document_json' => ['layers' => []],
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'Set',
            'status' => CreativeSet::STATUS_ACTIVE,
            'hero_composition_id' => $c2->id,
        ]);
        $v1 = CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'Base',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        $v2 = CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'Branch',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->deleteJson("/app/api/creative-sets/{$set->id}/variants/{$v2->id}")
            ->assertOk()
            ->assertJsonCount(1, 'creative_set.variants')
            ->assertJsonPath('creative_set.variants.0.composition_id', (string) $c1->id);

        $this->assertNull(CreativeSetVariant::query()->find($v2->id));
        $this->assertNull($set->fresh()->hero_composition_id);
    }

    public function test_destroy_rejects_base_variant(): void
    {
        $tenant = Tenant::create(['name' => 'T2', 'slug' => 't-vd2']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B2', 'slug' => 'b-vd2']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $c1 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'Base',
            'document_json' => ['layers' => []],
        ]);
        $c2 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'B2',
            'document_json' => ['layers' => []],
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'Set',
            'status' => CreativeSet::STATUS_ACTIVE,
        ]);
        $v1 = CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'Base',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'Other',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->deleteJson("/app/api/creative-sets/{$set->id}/variants/{$v1->id}")
            ->assertStatus(422);
    }
}
