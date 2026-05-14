<?php

namespace Tests\Unit\Services;

use App\Jobs\GenerateThumbnailsJob;
use App\Models\Asset;
use App\Services\Models\BlenderModelPreviewService;
use App\Services\ThumbnailGenerationService;
use ReflectionMethod;
use Tests\TestCase;

class Model3dPosterStubThumbnailTest extends TestCase
{
    protected function tearDown(): void
    {
        BlenderModelPreviewService::$processRunnerOverride = null;
        parent::tearDown();
    }
    public function test_stub_requires_dam_3d(): void
    {
        config(['dam_3d.enabled' => false]);
        $svc = app(ThumbnailGenerationService::class);
        $m = new ReflectionMethod(ThumbnailGenerationService::class, 'generateModel3dRasterThumbnail');
        $m->setAccessible(true);
        $tmp = tempnam(sys_get_temp_dir(), 'glb_');
        file_put_contents($tmp, 'bin');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('DAM_3D processing');
            $m->invoke($svc, $tmp, ['width' => 64, 'height' => 64, '_original_filename' => 'x.glb']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_invalid_glb_bytes_throw_without_raster_when_dam_3d_enabled(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => true]);
        BlenderModelPreviewService::$processRunnerOverride = static function (): array {
            throw new \RuntimeException('Blender must not run for invalid GLB bytes');
        };

        $svc = app(ThumbnailGenerationService::class);
        $m = new ReflectionMethod(ThumbnailGenerationService::class, 'generateModel3dRasterThumbnail');
        $m->setAccessible(true);
        $tmp = tempnam(sys_get_temp_dir(), 'glb_');
        file_put_contents($tmp, 'fakeglb');
        try {
            $this->expectException(\RuntimeException::class);
            $m->invoke($svc, $tmp, [
                'width' => 120,
                'height' => 120,
                'quality' => 80,
                '_original_filename' => 'chair.glb',
                '_asset_id' => 1,
                '_file_type' => 'model_glb',
            ]);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_supports_thumbnail_generation_for_glb_when_dam_3d_true(): void
    {
        config(['dam_3d.enabled' => true]);
        $job = new GenerateThumbnailsJob('00000000-0000-0000-0000-000000000001');
        $ref = new ReflectionMethod(GenerateThumbnailsJob::class, 'supportsThumbnailGeneration');
        $ref->setAccessible(true);
        $asset = new Asset([
            'original_filename' => 'm.glb',
            'mime_type' => 'model/gltf-binary',
            'metadata' => [],
        ]);
        $this->assertTrue($ref->invoke($job, $asset, null));
    }

    public function test_supports_thumbnail_generation_for_stl_when_dam_3d_true(): void
    {
        config(['dam_3d.enabled' => true]);
        $job = new GenerateThumbnailsJob('00000000-0000-0000-0000-000000000001');
        $ref = new ReflectionMethod(GenerateThumbnailsJob::class, 'supportsThumbnailGeneration');
        $ref->setAccessible(true);
        $asset = new Asset([
            'original_filename' => 'part.stl',
            'mime_type' => 'model/stl',
            'metadata' => [],
        ]);
        $this->assertTrue($ref->invoke($job, $asset, null));
    }

    public function test_supports_thumbnail_generation_for_obj_with_text_plain_mime_when_dam_3d_true(): void
    {
        config(['dam_3d.enabled' => true]);
        $job = new GenerateThumbnailsJob('00000000-0000-0000-0000-000000000001');
        $ref = new ReflectionMethod(GenerateThumbnailsJob::class, 'supportsThumbnailGeneration');
        $ref->setAccessible(true);
        $asset = new Asset([
            'original_filename' => 'mesh.obj',
            'mime_type' => 'text/plain',
            'metadata' => [],
        ]);
        $this->assertTrue($ref->invoke($job, $asset, null));
    }

    public function test_supports_thumbnail_generation_for_glb_when_dam_3d_false(): void
    {
        config(['dam_3d.enabled' => false]);
        $job = new GenerateThumbnailsJob('00000000-0000-0000-0000-000000000001');
        $ref = new ReflectionMethod(GenerateThumbnailsJob::class, 'supportsThumbnailGeneration');
        $ref->setAccessible(true);
        $asset = new Asset([
            'original_filename' => 'm.glb',
            'mime_type' => 'model/gltf-binary',
            'metadata' => [],
        ]);
        $this->assertFalse($ref->invoke($job, $asset, null));
    }

    public function test_fbx_supports_thumbnail_pipeline_when_dam_3d_true(): void
    {
        config(['dam_3d.enabled' => true]);
        $job = new GenerateThumbnailsJob('00000000-0000-0000-0000-000000000001');
        $ref = new ReflectionMethod(GenerateThumbnailsJob::class, 'supportsThumbnailGeneration');
        $ref->setAccessible(true);
        $asset = new Asset([
            'original_filename' => 'm.fbx',
            'mime_type' => 'application/vnd.autodesk.fbx',
            'metadata' => [],
        ]);
        $this->assertTrue($ref->invoke($job, $asset, null));
    }

    public function test_preview_3d_merge_marks_poster_stub_ready(): void
    {
        $merged = \App\Support\Preview3dMetadata::merge([], [
            'status' => \App\Support\Preview3dMetadata::STATUS_READY,
            'poster_path' => 'tenants/x/assets/y/v1/thumbnails/original/medium/z.webp',
            'thumbnail_path' => 'tenants/x/assets/y/v1/thumbnails/original/thumb/z.webp',
            'viewer_path' => null,
            'debug' => ['poster_stub' => true],
        ]);
        $this->assertSame(\App\Support\Preview3dMetadata::STATUS_READY, $merged['status']);
        $this->assertStringContainsString('medium', (string) $merged['poster_path']);
        $this->assertNull($merged['viewer_path']);
        $this->assertTrue($merged['debug']['poster_stub'] ?? false);
    }
}
