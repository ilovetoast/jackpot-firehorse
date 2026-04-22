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

class EditorCreativeSetDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_destroy_set_removes_variants_and_keeps_compositions(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-csd']);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-csd']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $c1 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'A',
            'document_json' => ['layers' => []],
        ]);
        $c2 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'B',
            'document_json' => ['layers' => []],
        ]);

        $set = CreativeSet::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'name' => 'My set',
            'status' => CreativeSet::STATUS_ACTIVE,
            'hero_composition_id' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c1->id,
            'sort_order' => 0,
            'label' => 'v1',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);
        CreativeSetVariant::create([
            'creative_set_id' => $set->id,
            'composition_id' => $c2->id,
            'sort_order' => 1,
            'label' => 'v2',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->deleteJson("/app/api/creative-sets/{$set->id}")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertNull(CreativeSet::query()->find($set->id));
        $this->assertSame(0, CreativeSetVariant::query()->count());
        $this->assertNotNull(Composition::query()->find($c1->id));
        $this->assertNotNull(Composition::query()->find($c2->id));
    }
}
