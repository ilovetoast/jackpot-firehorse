<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Composition;
use App\Models\StudioCompositionVideoExportJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Studio\CompositionRenderPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
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

    public function test_signed_url_root_rewrites_https_app_host_when_app_url_is_http(): void
    {
        Config::set('app.url', 'http://jackpot.local');
        Config::set('studio_video.canvas_export_signed_url_root', 'http://laravel.test');

        $payload = $this->makePayloadWithVideoSrc('https://jackpot.local/storage/x.mp4');
        $video = collect($payload['layers'])->firstWhere('id', 'v1');
        $this->assertIsArray($video);
        $this->assertSame('http://laravel.test/storage/x.mp4', $video['src']);
    }

    public function test_signed_url_root_rewrites_protocol_relative_urls(): void
    {
        Config::set('app.url', 'http://jackpot.local');
        Config::set('studio_video.canvas_export_signed_url_root', 'http://laravel.test');

        $payload = $this->makePayloadWithVideoSrc('//jackpot.local/storage/y.mp4');
        $video = collect($payload['layers'])->firstWhere('id', 'v1');
        $this->assertIsArray($video);
        $this->assertSame('http://laravel.test/storage/y.mp4', $video['src']);
    }

    public function test_extra_payload_origins_are_rewritten(): void
    {
        Config::set('app.url', 'http://jackpot.local');
        Config::set('studio_video.canvas_export_signed_url_root', 'http://laravel.test');
        Config::set('studio_video.canvas_export_payload_extra_origins', 'http://cdn.example.test');

        $payload = $this->makePayloadWithVideoSrc('http://cdn.example.test/media/z.mp4');
        $video = collect($payload['layers'])->firstWhere('id', 'v1');
        $this->assertIsArray($video);
        $this->assertSame('http://laravel.test/media/z.mp4', $video['src']);
    }

    /**
     * @return array<string, mixed>
     */
    private function makePayloadWithVideoSrc(string $src): array
    {
        $tenant = Tenant::create([
            'name' => 'T2',
            'slug' => 't2-'.Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'B2',
            'slug' => 'b2-'.Str::random(6),
        ]);
        $user = User::factory()->create();
        $composition = Composition::create([
            'tenant_id' => $tenant->id,
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'visibility' => Composition::VISIBILITY_SHARED,
            'name' => 'C2',
            'document_json' => [
                'width' => 1080,
                'height' => 1920,
                'layers' => [
                    ['id' => 'f1', 'type' => 'fill', 'visible' => true, 'locked' => false, 'z' => 0, 'fillKind' => 'solid', 'color' => '#000'],
                    [
                        'id' => 'v1',
                        'type' => 'video',
                        'name' => 'V',
                        'visible' => true,
                        'locked' => false,
                        'z' => 1,
                        'src' => $src,
                        'transform' => ['x' => 0, 'y' => 0, 'width' => 1080, 'height' => 1920, 'rotation' => 0],
                    ],
                ],
                'studio_timeline' => ['duration_ms' => 1000],
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

        return CompositionRenderPayloadFactory::fromComposition($composition, $tenant, $user, $job);
    }
}
