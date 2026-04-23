<?php

namespace App\Services\Studio;

use App\Contracts\StudioCanvasRuntimeFfmpegProcessInvokerContract;
use Symfony\Component\Process\Process;

final class DefaultStudioCanvasRuntimeFfmpegProcessInvoker implements StudioCanvasRuntimeFfmpegProcessInvokerContract
{
    /**
     * @param  list<string>  $argv
     * @return array{exitCode: int|null, stdout: string, stderr: string}
     */
    public function run(array $argv, ?string $cwd, float $timeoutSeconds): array
    {
        $process = new Process($argv, $cwd);
        $process->setTimeout($timeoutSeconds > 0 ? $timeoutSeconds : null);
        $process->run();

        return [
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
