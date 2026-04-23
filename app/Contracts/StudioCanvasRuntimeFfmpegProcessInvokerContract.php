<?php

namespace App\Contracts;

/**
 * Invokes FFmpeg (or ffprobe) as a subprocess for canvas_runtime merge tests and production.
 *
 * @param  list<string>  $argv
 * @return array{exitCode: int|null, stdout: string, stderr: string}
 */
interface StudioCanvasRuntimeFfmpegProcessInvokerContract
{
    public function run(array $argv, ?string $cwd, float $timeoutSeconds): array;
}
