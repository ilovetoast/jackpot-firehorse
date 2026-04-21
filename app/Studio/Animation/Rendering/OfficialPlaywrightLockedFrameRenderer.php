<?php

namespace App\Studio\Animation\Rendering;

use App\Models\StudioAnimationJob;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Versioned first-party Playwright render (see scripts/studio-animation/playwright-locked-frame.mjs).
 * When disabled or Playwright unavailable, callers fall back to legacy browser command / Imagick / client snapshot.
 */
final class OfficialPlaywrightLockedFrameRenderer
{
    public const RENDERER_VERSION = '1.4.0';

    public function tryRenderPng(
        StudioAnimationJob $job,
        array $document,
        int $viewportWidth,
        int $viewportHeight,
    ): LockedDocumentServerFrameResult {
        if (! (bool) config('studio_animation.official_playwright_renderer.enabled', false)) {
            return LockedDocumentServerFrameResult::skipped('official_playwright_disabled');
        }

        $scriptCfg = (string) config('studio_animation.official_playwright_renderer.script_path', '');
        $script = $scriptCfg !== '' ? $scriptCfg : base_path('scripts/studio-animation/playwright-locked-frame.mjs');
        if (! is_file($script)) {
            return LockedDocumentServerFrameResult::skipped('official_playwright_script_missing', ['path' => $script]);
        }

        $node = (string) config('studio_animation.official_playwright_renderer.node_binary', 'node');
        if ($node === 'node') {
            $found = (new ExecutableFinder)->find('node');
            if ($found !== null) {
                $node = $found;
            }
        }

        $jsonFile = (string) tempnam(sys_get_temp_dir(), 'sa-pw-doc-');
        $baseOut = (string) tempnam(sys_get_temp_dir(), 'sa-pw-out-');
        @unlink($baseOut);
        $pngFile = $baseOut.'.png';

        try {
            file_put_contents($jsonFile, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            @unlink($jsonFile);

            return LockedDocumentServerFrameResult::skipped('official_playwright_json_failed', ['message' => $e->getMessage()]);
        }

        $cmd = array_values(array_filter([
            $node,
            $script,
            $jsonFile,
            $pngFile,
            (string) max(1, min(8192, $viewportWidth)),
            (string) max(1, min(8192, $viewportHeight)),
            (string) $job->aspect_ratio,
            self::RENDERER_VERSION,
        ]));

        $timeout = (int) config('studio_animation.official_playwright_renderer.timeout_seconds', 120);
        $process = new Process($cmd, base_path());
        $process->setTimeout(max(10, $timeout));

        try {
            $process->run();
        } catch (\Throwable $e) {
            @unlink($jsonFile);
            @unlink($pngFile);
            Log::info('[OfficialPlaywrightLockedFrameRenderer] process exception', [
                'job_id' => $job->id,
                'message' => $e->getMessage(),
            ]);

            return LockedDocumentServerFrameResult::skipped('official_playwright_process_failed', ['message' => $e->getMessage()]);
        } finally {
            @unlink($jsonFile);
        }

        if (! $process->isSuccessful()) {
            @unlink($pngFile);
            Log::info('[OfficialPlaywrightLockedFrameRenderer] command failed', [
                'job_id' => $job->id,
                'exit' => $process->getExitCode(),
                'err' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);

            return LockedDocumentServerFrameResult::skipped('official_playwright_command_failed', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
        }

        if (! is_file($pngFile)) {
            return LockedDocumentServerFrameResult::skipped('official_playwright_missing_png');
        }

        $binary = (string) file_get_contents($pngFile);
        @unlink($pngFile);

        if (strlen($binary) < 64) {
            return LockedDocumentServerFrameResult::skipped('official_playwright_empty_png');
        }

        return LockedDocumentServerFrameResult::success($binary, [
            'engine' => 'browser_headless_official',
            'renderer_version' => self::RENDERER_VERSION,
        ]);
    }
}
