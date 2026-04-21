<?php

namespace App\Studio\Animation\Rendering;

use App\Models\StudioAnimationJob;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Optional external command that renders locked document JSON to PNG (e.g. headless Chromium via Node).
 * Configure `studio_animation.browser_locked_frame.command_template` with placeholders
 * {{DOCUMENT_JSON}} and {{OUTPUT_PNG}} (absolute paths). Disabled by default.
 */
final class BrowserHeadlessLockedFrameRenderer
{
    public function tryRenderPng(StudioAnimationJob $job, array $document): LockedDocumentServerFrameResult
    {
        if (! (bool) config('studio_animation.browser_locked_frame.enabled', false)) {
            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_disabled');
        }

        $template = (string) config('studio_animation.browser_locked_frame.command_template', '');
        if (trim($template) === '') {
            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_not_configured');
        }

        $jsonFile = (string) tempnam(sys_get_temp_dir(), 'sa-doc-');
        $baseOut = (string) tempnam(sys_get_temp_dir(), 'sa-png-');
        @unlink($baseOut);
        $pngFile = $baseOut.'.png';

        try {
            file_put_contents($jsonFile, json_encode($document, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            @unlink($jsonFile);

            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_json_encode_failed', [
                'message' => $e->getMessage(),
            ]);
        }

        $cmd = str_replace(
            ['{{DOCUMENT_JSON}}', '{{OUTPUT_PNG}}'],
            [escapeshellarg($jsonFile), escapeshellarg($pngFile)],
            $template
        );

        $timeout = (int) config('studio_animation.browser_locked_frame.timeout_seconds', 120);
        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout(max(5, $timeout));

        try {
            $process->run();
        } catch (\Throwable $e) {
            @unlink($jsonFile);
            @unlink($pngFile);
            Log::info('[BrowserHeadlessLockedFrameRenderer] process exception', [
                'job_id' => $job->id,
                'message' => $e->getMessage(),
            ]);

            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_process_failed', [
                'message' => $e->getMessage(),
            ]);
        } finally {
            @unlink($jsonFile);
        }

        if (! $process->isSuccessful()) {
            @unlink($pngFile);
            Log::info('[BrowserHeadlessLockedFrameRenderer] command failed', [
                'job_id' => $job->id,
                'exit' => $process->getExitCode(),
                'err' => $process->getErrorOutput(),
            ]);

            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_command_failed', [
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
            ]);
        }

        if (! is_file($pngFile)) {
            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_missing_output_png');
        }

        $binary = (string) file_get_contents($pngFile);
        @unlink($pngFile);

        if (strlen($binary) < 64) {
            return LockedDocumentServerFrameResult::skipped('browser_locked_frame_empty_png');
        }

        return LockedDocumentServerFrameResult::success($binary, [
            'engine' => 'browser_headless',
        ]);
    }
}
