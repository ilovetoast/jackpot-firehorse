<?php

namespace Tests\Unit\Services;

use App\Services\ThumbnailGenerationService;
use ReflectionMethod;
use Tests\TestCase;

class ThumbnailGenerationServiceDownloadTempPathTest extends TestCase
{
    public function test_allocate_temp_path_for_glb_preserves_extension(): void
    {
        $svc = app(ThumbnailGenerationService::class);
        $m = new ReflectionMethod(ThumbnailGenerationService::class, 'allocateTempPathForThumbnailSourceDownload');
        $m->setAccessible(true);

        $key = 'tenants/u/assets/a/v1/original.glb';
        $path = $m->invoke($svc, $key, 'model/gltf-binary');
        try {
            $this->assertStringEndsWith('.glb', $path);
            $this->assertStringStartsWith(sys_get_temp_dir(), $path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function test_allocate_temp_path_for_pdf_unchanged(): void
    {
        $svc = app(ThumbnailGenerationService::class);
        $m = new ReflectionMethod(ThumbnailGenerationService::class, 'allocateTempPathForThumbnailSourceDownload');
        $m->setAccessible(true);

        $path = $m->invoke($svc, 'tenants/u/v1/original', 'application/pdf');
        try {
            $this->assertStringEndsWith('.pdf', $path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
