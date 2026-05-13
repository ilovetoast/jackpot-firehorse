<?php

namespace Tests\Unit\Services;

use App\Services\Models\BlenderModelPreviewService;
use App\Services\ThumbnailGenerationService;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class Model3dBlenderThumbnailPipelineTest extends TestCase
{
    protected function tearDown(): void
    {
        BlenderModelPreviewService::$processRunnerOverride = null;
        parent::tearDown();
    }

    public function test_blender_failure_falls_back_to_stub_master(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => true]);
        BlenderModelPreviewService::$processRunnerOverride = static fn (array $cmd, string $cwd, float $timeout): array => [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'import failed: fake',
        ];

        $svc = app(ThumbnailGenerationService::class);
        $m = new ReflectionMethod(ThumbnailGenerationService::class, 'resetModel3dRasterState');
        $m->setAccessible(true);
        $m->invoke($svc);

        $attempt = new ReflectionMethod(ThumbnailGenerationService::class, 'attemptBlenderOrStubMaster');
        $attempt->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'm3d_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        try {
            $attempt->invoke($svc, $tmp, 'model_stl', 256, 'part.stl');
            $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $r */
            $r = $rp->getValue($svc);
            $this->assertTrue($r['poster_stub']);
            $this->assertFalse($r['blender_used']);
            $this->assertNotEmpty($r['failure_message'] ?? null);

            $mp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dMasterPngPath');
            $mp->setAccessible(true);
            $p = $mp->getValue($svc);
            $this->assertIsString($p);
            $this->assertFileExists($p);
            @unlink($p);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_blender_success_sets_real_poster_flags(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => true]);
        BlenderModelPreviewService::$processRunnerOverride = static function (array $cmd, string $cwd, float $timeout): array {
            $poster = $cmd[6] ?? '';
            if (is_string($poster) && $poster !== '') {
                $im = imagecreatetruecolor(8, 8);
                imagepng($im, $poster);
                imagedestroy($im);
            }

            return ['exit_code' => 0, 'stdout' => 'DAM3D_BLENDER_OK', 'stderr' => ''];
        };

        $svc = app(ThumbnailGenerationService::class);
        $reset = new ReflectionMethod(ThumbnailGenerationService::class, 'resetModel3dRasterState');
        $reset->setAccessible(true);
        $reset->invoke($svc);

        $attempt = new ReflectionMethod(ThumbnailGenerationService::class, 'attemptBlenderOrStubMaster');
        $attempt->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'm3d_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        try {
            $attempt->invoke($svc, $tmp, 'model_glb', 128, 'a.glb');
            $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $r */
            $r = $rp->getValue($svc);
            $this->assertFalse($r['poster_stub']);
            $this->assertTrue($r['blender_used']);

            $mp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dMasterPngPath');
            $mp->setAccessible(true);
            $p = $mp->getValue($svc);
            $this->assertIsString($p);
            @unlink($p);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_real_render_disabled_never_invokes_blender_runner(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => false]);
        BlenderModelPreviewService::$processRunnerOverride = static function (): array {
            throw new \RuntimeException('Blender process runner should not run when real_render_enabled is false');
        };

        $svc = app(ThumbnailGenerationService::class);
        $reset = new ReflectionMethod(ThumbnailGenerationService::class, 'resetModel3dRasterState');
        $reset->setAccessible(true);
        $reset->invoke($svc);

        $attempt = new ReflectionMethod(ThumbnailGenerationService::class, 'attemptBlenderOrStubMaster');
        $attempt->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'm3d_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        try {
            $attempt->invoke($svc, $tmp, 'model_glb', 64, 'x.glb');
            $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $r */
            $r = $rp->getValue($svc);
            $this->assertTrue($r['poster_stub']);
            $this->assertFalse($r['blender_used']);

            $mp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dMasterPngPath');
            $mp->setAccessible(true);
            $p = $mp->getValue($svc);
            $this->assertIsString($p);
            @unlink($p);
        } finally {
            @unlink($tmp);
        }
    }
}
