<?php

namespace App\Contracts;

/**
 * Runs the Node + Playwright canvas frame capture script (test seam).
 */
interface StudioCanvasRuntimePlaywrightInvokerContract
{
    /**
     * @param  list<string>  $command
     * @return array{exitCode: int|null, stdout: string, stderr: string}
     */
    public function run(array $command, ?string $cwd, int $timeoutSeconds): array;
}
