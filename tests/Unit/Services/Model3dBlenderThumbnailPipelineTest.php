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

    public function test_blender_failure_throws_without_stub_master(): void
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
            try {
                $attempt->invoke($svc, $tmp, 'model_stl', 256, 'part.stl');
                $this->fail('Expected RuntimeException when Blender render fails');
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $r */
            $r = $rp->getValue($svc);
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
        $fixture = dirname(__DIR__, 2).'/fixtures/3d/minimal_valid.glb';
        $this->assertFileExists($fixture, 'Commit tests/fixtures/3d/minimal_valid.glb for GLB gate tests');
        file_put_contents($tmp, (string) file_get_contents($fixture));

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
        $fixture = dirname(__DIR__, 2).'/fixtures/3d/minimal_valid.glb';
        $this->assertFileExists($fixture, 'Commit tests/fixtures/3d/minimal_valid.glb for GLB gate tests');
        file_put_contents($tmp, (string) file_get_contents($fixture));

        try {
            try {
                $attempt->invoke($svc, $tmp, 'model_glb', 64, 'x.glb');
                $this->fail('Expected RuntimeException when real_render_enabled is false');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsStringIgnoringCase('blender', $e->getMessage());
            }
            $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
            $rp->setAccessible(true);
            /** @var array<string, mixed> $r */
            $r = $rp->getValue($svc);
            $this->assertFalse($r['poster_stub'] ?? true);
            $this->assertFalse($r['blender_used'] ?? true);

            $mp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dMasterPngPath');
            $mp->setAccessible(true);
            $this->assertNull($mp->getValue($svc));
        } finally {
            @unlink($tmp);
        }
    }
}
