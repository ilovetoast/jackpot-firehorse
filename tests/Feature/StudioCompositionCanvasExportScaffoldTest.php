<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StudioCompositionCanvasExportScaffoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_internal_render_route_returns_inertia_for_export_job(): void
    {
        URL::forceRootUrl('http://localhost');

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
                        'content' => 'Canvas export hello',
                        'style' => ['fontFamily' => 'Inter', 'fontSize' => 22, 'color' => '#111827'],
                    ],
                ],
                'studio_timeline' => ['duration_ms' => 5000],
            ],
        ]);

        $row = StudioCompositionVideoExportJob::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'composition_id' => $composition->id,
            'render_mode' => 'canvas_runtime',
            'status' => StudioCompositionVideoExportJob::STATUS_QUEUED,
            'meta_json' => [],
        ]);

        $url = URL::temporarySignedRoute(
            'internal.studio.composition-export-render',
            now()->addMinutes(5),
            ['exportJob' => $row->id],
        );

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('StudioExport/CompositionExportRender')
                ->has('renderPayload.version')
                ->has('renderPayload.brand_context')
                ->where('renderPayload.width', 1080)
                ->where('renderPayload.height', 1920));
    }
}
