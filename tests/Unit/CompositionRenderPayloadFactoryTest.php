<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\CompositionRenderPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompositionRenderPayloadFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_includes_brand_context_and_font_entries_for_text_layer(): void
    {
        $tenant = Tenant::create([
            'name' => 'T',
            'slug' => 't-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B',
            'slug' => 'b-'.Str::random(6),
        ]);
        $user = User::factory()->create();
        $composition = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C',
            'document_json' => [
                'width' => 1080,
                'height' => 1920,
                'layers' => [
                    ['id' => 'f1', 'type' => 'fill', 'visible' => true, 'locked' => false, 'z' => 0, 'fillKind' => 'solid', 'color' => '#e0e0e0'],
                    [
                        'id' => 't1',
                        'type' => 'text',
                        'name' => 'Headline',
                        'visible' => true,
                        'locked' => false,
                        'z' => 1,
                        'transform' => ['x' => 10, 'y' => 20, 'width' => 400, 'height' => 80, 'rotation' => 0],
                        'content' => 'Export hello',
                        'style' => ['fontFamily' => 'Inter', 'fontSize' => 24, 'color' => '#111827'],
                    ],
                ],
                'studio_timeline' => ['duration_ms' => 5000],
            ],
        ]);

        $job = StudioCompositionVideoExportJob::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => $composition->id,
            'render_mode' => 'canvas_runtime',
            'status' => StudioCompositionVideoExportJob::STATUS_QUEUED,
            'meta_json' => [],
        ]);

        $payload = CompositionRenderPayloadFactory::fromComposition($composition, $tenant, $user, $job);

        $this->assertArrayHasKey('brand_context', $payload);
        $this->assertIsArray($payload['brand_context']);
        $this->assertArrayHasKey('typography', $payload['brand_context']);
        $this->assertNotEmpty($payload['fonts']);
        $kinds = array_values(array_filter(array_map(
            static fn ($row) => is_array($row) ? ($row['kind'] ?? null) : null,
            $payload['fonts']
        )));
        $this->assertContains('text_layer_family', $kinds);
    }
}
