<?php

namespace Tests\Unit\Services;

use App\Exceptions\Model3dPreviewFailedException;
use App\Services\Models\BlenderModelPreviewService;
use App\Services\ThumbnailGenerationService;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * Locks the contract that the 3D preview master raster path throws the *typed*
 * {@see Model3dPreviewFailedException} (carrying user message, file type, debug
 * payload) — not a bare `\RuntimeException`. The typed exception is what
 * GenerateThumbnailsJob detects to short-circuit to a terminal SKIPPED state
 * (no 32× retry, no Sentry spam) for user-uploaded broken / unsupported models.
 *
 * If a future refactor reverts to throwing `\RuntimeException` directly, these
 * tests fail loudly so the no-retry / no-Sentry contract isn't silently lost.
 */
class Model3dPreviewFailedExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        BlenderModelPreviewService::$processRunnerOverride = null;
        parent::tearDown();
    }

    public function test_exception_extends_runtime_exception_for_backwards_compat(): void
    {
        $e = new Model3dPreviewFailedException(
            message: 'boom',
            userMessage: 'user-friendly',
        );

        $this->assertInstanceOf(RuntimeException::class, $e);
        $this->assertSame('boom', $e->getMessage());
        $this->assertSame('user-friendly', $e->userMessage);
        $this->assertNull($e->fileType);
        $this->assertFalse($e->blenderAttempted);
        $this->assertFalse($e->invalidSource);
        $this->assertSame([], $e->debug);
    }

    public function test_exception_carries_full_diagnostic_payload(): void
    {
        $e = new Model3dPreviewFailedException(
            message: 'Blender process error.',
            userMessage: 'We could not generate a preview for this model.',
            fileType: 'model_glb',
            blenderAttempted: true,
            invalidSource: false,
            debug: ['attempts' => [['attempt' => 'default', 'exit_code' => 1]]],
        );

        $this->assertSame('model_glb', $e->fileType);
        $this->assertTrue($e->blenderAttempted);
        $this->assertFalse($e->invalidSource);
        $this->assertIsArray($e->debug);
        $this->assertArrayHasKey('attempts', $e->debug);
    }

    public function test_invalid_glb_bytes_throw_typed_exception_with_invalid_source_flag(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => true]);
        // Guard: Blender must NOT spawn for non-GLB bytes — invalid source is a
        // pre-Blender abort that the job treats as terminal-no-retry.
        BlenderModelPreviewService::$processRunnerOverride = static function (): array {
            throw new \RuntimeException('Blender must not run for invalid GLB bytes');
        };

        $svc = app(ThumbnailGenerationService::class);
        $reset = new ReflectionMethod(ThumbnailGenerationService::class, 'resetModel3dRasterState');
        $reset->setAccessible(true);
        $reset->invoke($svc);

        $attempt = new ReflectionMethod(ThumbnailGenerationService::class, 'attemptBlenderOrStubMaster');
        $attempt->setAccessible(true);

        $tmp = tempnam(sys_get_temp_dir(), 'badglb_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, '<!DOCTYPE html><html><body>not a glb</body></html>');

        try {
            try {
                $attempt->invoke($svc, $tmp, 'model_glb', 128, 'fake.glb');
                $this->fail('Expected Model3dPreviewFailedException');
            } catch (Model3dPreviewFailedException $e) {
                $this->assertSame('model_glb', $e->fileType);
                $this->assertTrue($e->invalidSource, 'invalidSource flag must be set when GLB magic bytes fail');
                $this->assertFalse($e->blenderAttempted, 'Blender must not be attempted on invalid source');
                $this->assertNotSame('', $e->userMessage);
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function test_blender_runner_failure_throws_typed_exception_with_blender_attempted_flag(): void
    {
        config(['dam_3d.enabled' => true, 'dam_3d.real_render_enabled' => true]);
        // Force the Blender runner to throw — same shape as the production
        // "Blender process error." failure that originally hit Sentry.
        BlenderModelPreviewService::$processRunnerOverride = static function (): array {
            throw new \RuntimeException('simulated blender process error');
        };

        $svc = app(ThumbnailGenerationService::class);
        $reset = new ReflectionMethod(ThumbnailGenerationService::class, 'resetModel3dRasterState');
        $reset->setAccessible(true);
        $reset->invoke($svc);

        $attempt = new ReflectionMethod(ThumbnailGenerationService::class, 'attemptBlenderOrStubMaster');
        $attempt->setAccessible(true);

        // Minimal valid GLB header so we get past the magic-byte guard and
        // actually exercise the Blender invocation path.
        $tmp = tempnam(sys_get_temp_dir(), 'glb_');
        $this->assertNotFalse($tmp);
        // glTF binary magic: "glTF" (0x46546C67) + version (0x00000002) + length (4 bytes)
        $glbHeader = "glTF\x02\x00\x00\x00\x14\x00\x00\x00";
        file_put_contents($tmp, $glbHeader.str_repeat("\x00", 16));

        try {
            try {
                $attempt->invoke($svc, $tmp, 'model_glb', 128, 'real.glb');
                $this->fail('Expected Model3dPreviewFailedException');
            } catch (Model3dPreviewFailedException $e) {
                $this->assertSame('model_glb', $e->fileType);
                $this->assertFalse($e->invalidSource, 'invalidSource only set on pre-Blender magic-byte failure');
                $this->assertTrue($e->blenderAttempted, 'blenderAttempted should be true after the runner ran');
                $this->assertNotSame('', $e->userMessage);

                // Failure report should still be populated for telemetry.
                $rp = new ReflectionProperty(ThumbnailGenerationService::class, 'model3dPreviewReport');
                $rp->setAccessible(true);
                /** @var array<string, mixed> $r */
                $r = $rp->getValue($svc);
                $this->assertFalse($r['blender_used'] ?? true);
                $this->assertFalse($r['poster_stub'] ?? true);
                $this->assertNotEmpty($r['failure_message'] ?? null);
            }
        } finally {
            @unlink($tmp);
        }
    }
}
