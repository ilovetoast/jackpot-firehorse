<?php

namespace App\Services\Studio;

use App\Contracts\StudioCanvasRuntimePlaywrightInvokerContract;
use Symfony\Component\Process\Process;

final class DefaultStudioCanvasRuntimePlaywrightInvoker implements StudioCanvasRuntimePlaywrightInvokerContract
{
    /**
     * @param  list<string>  $command
     * @return array{exitCode: int|null, stdout: string, stderr: string}
     */
    public function run(array $command, ?string $cwd, int $timeoutSeconds): array
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeoutSeconds > 0 ? $timeoutSeconds : null);

        $process->run();

        return [
            'exitCode' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
