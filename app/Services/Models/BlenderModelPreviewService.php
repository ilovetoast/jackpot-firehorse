<?php

namespace App\Services\Models;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Worker-only Blender invocation for 3D poster renders and optional GLB export.
 *
 * Never required on web nodes; callers must fall back when this service reports failure.
 */
final class BlenderModelPreviewService
{
    /**
     * Optional test override: function(string[] $command, string $cwd, float $timeoutSeconds): array{
     *   exit_code:int, stdout:string, stderr:string
     * }
     *
     * @var (callable(array<int, string>, string, float): array{exit_code: int, stdout: string, stderr: string})|null
     */
    public static $processRunnerOverride = null;

    /**
     * @return array{
     *   success: bool,
     *   poster_path: ?string,
     *   viewer_glb_local_path: ?string,
     *   render_seconds: ?float,
     *   conversion_seconds: ?float,
     *   failure_message: ?string,
     *   blender_version: ?string,
     *   debug: array<string, mixed>
     * }
     */
    public function renderModelPreview(
        string $sourceAbsolutePath,
        int $posterSizePx,
        string $backgroundHexNoHash,
        bool $exportGlb,
        ?string $exportGlbAbsolutePath,
    ): array {
        $empty = [
            'success' => false,
            'poster_path' => null,
            'viewer_glb_local_path' => null,
            'render_seconds' => null,
            'conversion_seconds' => null,
            'failure_message' => null,
            'blender_version' => null,
            'debug' => [],
        ];

        $binary = (string) config('dam_3d.blender_binary', '/usr/bin/blender');
        $binary = trim($binary);
        if ($binary === '' || ! is_file($binary) || ! is_executable($binary)) {
            return array_replace($empty, [
                'failure_message' => 'Blender binary not found or not executable.',
                'debug' => ['blender_resolved' => false],
            ]);
        }

        $script = resource_path('blender/render_model_preview.py');
        if (! is_file($script)) {
            return array_replace($empty, [
                'failure_message' => 'Bundled Blender script missing.',
                'debug' => ['script_present' => false],
            ]);
        }

        $maxBytes = (int) config('dam_3d.max_server_render_bytes', 104_857_600);
        $srcSize = @filesize($sourceAbsolutePath);
        if ($srcSize !== false && $srcSize > $maxBytes) {
            return array_replace($empty, [
                'failure_message' => 'Source exceeds max_server_render_bytes for Blender preview.',
                'debug' => ['source_bytes' => $srcSize, 'max_bytes' => $maxBytes],
            ]);
        }

        $exportPath = ($exportGlb && is_string($exportGlbAbsolutePath) && $exportGlbAbsolutePath !== '')
            ? $exportGlbAbsolutePath
            : '';

        $timeout = max(5.0, (float) config('dam_3d.max_render_seconds', 180));
        if ($exportPath !== '') {
            $timeout = max($timeout, (float) config('dam_3d.max_conversion_seconds', 180));
        }

        $sequences = $this->blenderRenderEnvSequences();
        $attemptsLog = [];
        $lastFailure = $empty;

        foreach ($sequences as $seq) {
            $label = (string) ($seq['label'] ?? 'default');
            $env = is_array($seq['env'] ?? null) ? $seq['env'] : [];

            $work = sys_get_temp_dir().'/dam3d_w_'.uniqid('', true);
            if (! @mkdir($work, 0700, true) && ! is_dir($work)) {
                return array_replace($empty, [
                    'failure_message' => 'Could not create temp work directory.',
                    'debug' => ['attempts' => $attemptsLog],
                ]);
            }

            $baseTmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
            $posterPath = tempnam($baseTmp, 'dam3d_p_');
            if ($posterPath === false) {
                $this->cleanupDir($work);

                return array_replace($empty, [
                    'failure_message' => 'Could not allocate temp poster path.',
                    'debug' => ['attempts' => $attemptsLog],
                ]);
            }
            @unlink($posterPath);
            $posterPath .= '.png';

            $cmd = array_merge(
                [
                    $binary,
                    '-b',
                    '--python',
                    $script,
                    '--',
                    $sourceAbsolutePath,
                    $posterPath,
                    (string) max(32, min(4096, $posterSizePx)),
                    ltrim($backgroundHexNoHash, '#'),
                ],
                $exportPath !== '' ? [$exportPath] : [''],
            );

            $t0 = microtime(true);
            try {
                $run = $this->runProcess($cmd, $work, $timeout, $env);
            } catch (\Throwable $e) {
                @unlink($posterPath);
                $this->cleanupDir($work);
                $attemptsLog[] = [
                    'attempt' => $label,
                    'exception_class' => $e::class,
                    'message' => $e->getMessage(),
                    'env_keys' => array_keys($env),
                ];
                $lastFailure = array_replace($empty, [
                    'failure_message' => 'Blender process error.',
                    'debug' => ['attempts' => $attemptsLog],
                ]);

                continue;
            }
            $this->cleanupDir($work);
            $wall = microtime(true) - $t0;

            $stderr = $run['stderr'] ?? '';
            $stdout = $run['stdout'] ?? '';
            $code = (int) ($run['exit_code'] ?? 1);
            $hint = self::summarizeProcessOutput($stderr, $stdout);
            $attemptsLog[] = [
                'attempt' => $label,
                'exit_code' => $code,
                'summary' => $hint,
                'render_seconds' => round($wall, 3),
                'env_keys' => array_keys($env),
            ];

            if ($code === 0 && is_file($posterPath) && filesize($posterPath) >= 32) {
                $bv = $this->detectBlenderVersion($binary);
                $glbLocal = ($exportPath !== '' && is_file($exportPath) && filesize($exportPath) > 32) ? $exportPath : null;

                return [
                    'success' => true,
                    'poster_path' => $posterPath,
                    'viewer_glb_local_path' => $glbLocal,
                    'render_seconds' => round($wall, 3),
                    'conversion_seconds' => $glbLocal !== null ? round($wall, 3) : null,
                    'failure_message' => null,
                    'blender_version' => $bv,
                    'debug' => [
                        'exit_code' => $code,
                        'summary' => self::summarizeProcessOutput($stderr, $stdout),
                        'attempts' => $attemptsLog,
                    ],
                ];
            }

            @unlink($posterPath);
            $lastFailure = array_replace($empty, [
                'failure_message' => 'Blender render failed.',
                'render_seconds' => round($wall, 3),
                'debug' => [
                    'exit_code' => $code,
                    'summary' => $hint,
                    'attempts' => $attemptsLog,
                ],
            ]);
        }

        return $lastFailure;
    }

    /**
     * @return list<array{label: string, env: array<string, string>}>
     */
    private function blenderRenderEnvSequences(): array
    {
        $base = [];
        if ((bool) config('dam_3d.writable_home_for_blender', true)) {
            $home = storage_path('framework/cache/dam3d-blender-home');
            if (! is_dir($home)) {
                @mkdir($home, 0775, true);
            }
            if (is_dir($home) && is_writable($home)) {
                $base['HOME'] = $home;
                $xdg = $home.DIRECTORY_SEPARATOR.'xdg-config';
                @mkdir($xdg, 0775, true);
                if (is_dir($xdg) && is_writable($xdg)) {
                    $base['XDG_CONFIG_HOME'] = $xdg;
                }
            }
        }

        // Always use software rasterization for headless workers. EGL/GPU-first runs are flaky on Docker/WSL2
        // and produced stub posters even when Blender was installed; llvmpipe is slower but consistent.
        $software = [
            'LIBGL_ALWAYS_SOFTWARE' => '1',
            'GALLIUM_DRIVER' => 'llvmpipe',
            'MESA_LOADER_DRIVER_OVERRIDE' => 'llvmpipe',
        ];

        return [
            ['label' => 'software_gl', 'env' => array_merge($base, $software)],
        ];
    }

    public static function blenderBinaryConfigured(): string
    {
        return trim((string) config('dam_3d.blender_binary', '/usr/local/bin/blender'));
    }

    public static function blenderBinaryUsable(): bool
    {
        $b = self::blenderBinaryConfigured();

        return $b !== '' && is_file($b) && is_executable($b);
    }

    /**
     * @param  array<string, string>  $env  Merged with inherited OS env (Symfony Process: non-null third arg adds/overrides).
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, string $cwd, float $timeout, array $env = []): array
    {
        if (self::$processRunnerOverride !== null) {
            return (self::$processRunnerOverride)($command, $cwd, $timeout);
        }

        $process = new Process($command, $cwd, $env === [] ? null : $env, null, $timeout);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * Remove temp poster / converted GLB files produced by {@see renderModelPreview} (not the original upload).
     */
    public function releaseTempArtifacts(?string $posterPath, ?string $glbPath): void
    {
        $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        foreach ([$posterPath, $glbPath] as $p) {
            if (! is_string($p) || $p === '' || ! str_starts_with($p, $tmp)) {
                continue;
            }
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    private function cleanupDir(string $dir): void
    {
        if ($dir === '' || $dir === '/' || ! str_starts_with($dir, sys_get_temp_dir())) {
            return;
        }
        try {
            File::deleteDirectory($dir);
        } catch (\Throwable $e) {
            Log::warning('[BlenderModelPreviewService] temp cleanup failed', [
                'dir' => basename($dir),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function detectBlenderVersion(string $binary): ?string
    {
        if (self::$processRunnerOverride !== null) {
            return null;
        }
        try {
            $p = new Process([$binary, '-b', '--version'], sys_get_temp_dir(), null, null, 15.0);
            $p->run();
            if (! $p->isSuccessful()) {
                return null;
            }
            $line = strtok($p->getOutput(), "\n");

            return is_string($line) && trim($line) !== '' ? trim($line) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function summarizeProcessOutput(string $stderr, string $stdout): string
    {
        $blob = trim($stderr !== '' ? $stderr : $stdout);
        $blob = preg_replace('/\s+/', ' ', $blob) ?? $blob;

        return strlen($blob) > 400 ? substr($blob, 0, 397).'…' : $blob;
    }
}
