<?php

namespace Tests\Unit\Services;

use App\Services\Models\BlenderModelPreviewService;
use Tests\TestCase;

class BlenderModelPreviewServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        BlenderModelPreviewService::$processRunnerOverride = null;
        parent::tearDown();
    }

    public function test_single_software_gl_attempt_succeeds(): void
    {
        config([
            'dam_3d.enabled' => true,
            'dam_3d.real_render_enabled' => true,
            'dam_3d.writable_home_for_blender' => false,
        ]);

        $calls = 0;
        BlenderModelPreviewService::$processRunnerOverride = static function (array $cmd, string $cwd, float $timeout) use (&$calls): array {
            $calls++;
            $poster = $cmd[6] ?? '';
            if (is_string($poster) && $poster !== '') {
                $im = imagecreatetruecolor(64, 64);
                imagepng($im, $poster);
                imagedestroy($im);
            }

            return ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''];
        };

        $tmp = tempnam(sys_get_temp_dir(), 'm3d_ok_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'dummy');

        try {
            $svc = app(BlenderModelPreviewService::class);
            $r = $svc->renderModelPreview($tmp, 128, '0f172a', false, null);
            $this->assertTrue($r['success']);
            $this->assertIsString($r['poster_path']);
            $this->assertFileExists($r['poster_path']);
            $this->assertSame(1, $calls);
            $attempts = $r['debug']['attempts'] ?? [];
            $this->assertCount(1, $attempts);
            $this->assertSame('software_gl', $attempts[0]['attempt'] ?? null);
            @unlink($r['poster_path']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_single_attempt_on_failure(): void
    {
        config([
            'dam_3d.enabled' => true,
            'dam_3d.real_render_enabled' => true,
            'dam_3d.writable_home_for_blender' => false,
        ]);

        $calls = 0;
        BlenderModelPreviewService::$processRunnerOverride = static function (array $cmd, string $cwd, float $timeout) use (&$calls): array {
            $calls++;

            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'fail'];
        };

        $tmp = tempnam(sys_get_temp_dir(), 'm3d_fail_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'dummy');

        try {
            $svc = app(BlenderModelPreviewService::class);
            $r = $svc->renderModelPreview($tmp, 64, '0f172a', false, null);
            $this->assertFalse($r['success']);
            $this->assertSame(1, $calls);
        } finally {
            @unlink($tmp);
        }
    }
}
