<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\CreativeSet;
use App\Models\CreativeSetVariant;
use App\Models\GenerationJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudioVersionsFakeGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('studio_creative_set_generation.fake_complete_generation', true);
    }

    public function test_generate_two_colors_completes_inline_under_sync_queue(): void
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-fake-gen',
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-fake-gen']);
        $user = User::factory()->create();
        $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        $user->brands()->attach($brand->id, ['role' => 'admin', 'removed_at' => null]);

        $c1 = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C1',
            'document_json' => ['width' => 1080, 'height' => 1080, 'layers' => []],
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
            'label' => 'Base',
            'status' => CreativeSetVariant::STATUS_READY,
            'axis' => null,
        ]);

        $response = $this->actingAs($user)
            ->withSession(['tenant_id' => $tenant->id, 'brand_id' => $brand->id])
            ->postJson("/app/api/creative-sets/{$set->id}/generate", [
                'source_composition_id' => $c1->id,
                'color_ids' => ['black', 'white'],
                'scene_ids' => [],
                'format_ids' => [],
            ]);

        $response->assertStatus(202);
        $jobId = (int) $response->json('generation_job.id');
        $this->assertNotSame(0, $jobId);

        $job = GenerationJob::query()->with('items')->findOrFail($jobId);
        $this->assertSame(GenerationJob::STATUS_COMPLETED, $job->status);
        $this->assertCount(2, $job->items);
        foreach ($job->items as $item) {
            $this->assertSame('completed', $item->status);
        }

        $set->refresh();
        $this->assertCount(3, $set->variants);
    }
}
