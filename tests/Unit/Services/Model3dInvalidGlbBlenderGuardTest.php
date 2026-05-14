<?php

namespace Tests\Unit\Services;

use App\Services\Models\BlenderModelPreviewService;
use App\Services\ThumbnailGenerationService;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class Model3dInvalidGlbBlenderGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        BlenderModelPreviewService::$processRunnerOverride = null;
        parent::tearDown();
    }

    public function test_html_bytes_skip_blender_and_flag_invalid_glb(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => true]);
        BlenderModelPreviewService::$processRunnerOverride = static function (): array {
            throw new \RuntimeException('Blender must not run for non-GLB bytes');
        };

        $svc = app(ThumbnailGenerationService::class);
        $reset = new ReflectionMethod(ThumbnailGenerationService::class, 'resetModel3dRasterState');
        $reset->setAccessible(true);
        $reset->invoke($svc);

        $attempt = new ReflectionMethod(ThumbnailGenerationService::class, 'attemptBlenderOrStubMaster');
        $attempt->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'badglb_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<!DOCTYPE html><html><body>not a glb</body></html>");

        try {
            try {
                $attempt->invoke($svc, $tmp, 'model_glb', 128, 'fake.glb');
                $this->fail('Expected RuntimeException for invalid GLB bytes');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $r */
            $r = $rp->getValue($svc);
            $this->assertTrue($r['invalid_glb_source'] ?? false);
            $this->assertFalse($r['poster_stub'] ?? true);
            $this->assertFalse($r['blender_used'] ?? true);
            $this->assertNotEmpty($r['failure_message'] ?? null);

            $mp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dMasterPngPath');
            $mp->setAccessible(true);
            $this->assertNull($mp->getValue($svc));
        } finally {
            @unlink($tmp);
        }
    }
}
