<?php

declare(strict_types=1);

namespace App\Services\Office;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Headless LibreOffice conversion of Office documents (Word / Excel / PowerPoint)
 * to a single-page PDF for thumbnail rasterization.
 *
 * Used by {@see \App\Services\ThumbnailGenerationService::generateOfficeThumbnail} which
 * then delegates page-1 rendering to the existing PDF pipeline (Imagick + spatie/pdf-to-image).
 *
 * Requires the `soffice` binary (Debian/Ubuntu: {@code libreoffice-nogui} metapackage).
 */
final class LibreOfficeDocumentPreviewService
{
    /**
     * Resolve the LibreOffice "soffice" launcher, or null when not installed.
     */
    public function findBinary(): ?string
    {
        $configured = config('assets.thumbnail.office.soffice_binary');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        // Prefer known Ubuntu/Debian paths first (queue workers may have a minimal PATH).
        foreach (['/usr/bin/soffice', '/usr/lib/libreoffice/program/soffice', '/usr/bin/libreoffice'] as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $out = [];
        $rc = 0;
        @exec('command -v soffice 2>/dev/null', $out, $rc);
        if ($rc === 0 && ! empty($out[0]) && is_executable($out[0])) {
            return $out[0];
        }

        return null;
    }

    /**
     * First line of `soffice --version` for diagnostics (null if binary missing or command fails).
     */
    public function readSofficeVersionLine(?string $binary = null): ?string
    {
        $binary ??= $this->findBinary();
        if ($binary === null || ! is_executable($binary)) {
            return null;
        }
        $out = [];
        $rc = 0;
        @exec(escapeshellarg($binary).' --version 2>&1', $out, $rc);
        if ($rc !== 0 || $out === []) {
            return null;
        }

        return trim((string) ($out[0] ?? '')) ?: null;
    }

    public function isAvailable(): bool
    {
        return $this->findBinary() !== null
            && extension_loaded('imagick')
            && class_exists(\Spatie\PdfToImage\Pdf::class);
    }

    /**
     * Run LibreOffice headless conversion and return structured diagnostics (does not throw on conversion failure).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function convertToPdfWithDiagnostics(string $sourcePath, array $context = [], bool $deleteWorkDirWhenUnsuccessful = true): array
    {
        if (! is_readable($sourcePath) || ! is_file($sourcePath)) {
            throw new \RuntimeException("Office source file is not readable: {$sourcePath}");
        }

        $binary = $this->findBinary();
        if ($binary === null) {
            throw new \RuntimeException(
                'LibreOffice (soffice) is not installed or not executable. Install libreoffice-nogui on workers (see docs/environments/PRODUCTION_WORKER_SOFTWARE.md).',
            );
        }

        if (! extension_loaded('imagick') || ! class_exists(\Spatie\PdfToImage\Pdf::class)) {
            throw new \RuntimeException(
                'Office previews require the Imagick PHP extension and spatie/pdf-to-image (same stack as PDF thumbnails).',
            );
        }

        $maxBytes = (int) config('assets.thumbnail.office.max_source_bytes', 262_144_000);
        if ($maxBytes > 0) {
            $size = (int) filesize($sourcePath);
            if ($size > $maxBytes) {
                throw new \RuntimeException(
                    "Office document ({$size} bytes) exceeds configured maximum ({$maxBytes} bytes) for LibreOffice conversion.",
                );
            }
        }

        $memBefore = memory_get_usage(true);
        $workDir = sys_get_temp_dir().'/jp_lo_'.bin2hex(random_bytes(8));
        if (! @mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            throw new \RuntimeException("Unable to create LibreOffice work directory: {$workDir}");
        }

        $profileDir = $workDir.'/lo-user-profile';
        if (! @mkdir($profileDir, 0700, true) && ! is_dir($profileDir)) {
            File::deleteDirectory($workDir);
            throw new \RuntimeException("Unable to create LibreOffice user profile directory: {$profileDir}");
        }

        $safeBase = $this->safeBasename($sourcePath);
        $localSource = $workDir.'/'.$safeBase;
        if (! @copy($sourcePath, $localSource)) {
            File::deleteDirectory($workDir);
            throw new \RuntimeException('Unable to copy Office document into LibreOffice work directory');
        }

        $timeout = max(15, (int) config('assets.thumbnail.office.timeout_seconds', 120));
        $home = $workDir;
        $runtime = $workDir.'/xdg-run';
        @mkdir($runtime, 0700, true);

        $profileUrl = $this->profileDirToFileUrl($profileDir);
        $cmd = sprintf(
            'timeout %d env HOME=%s XDG_RUNTIME_DIR=%s %s --headless --nologo --norestore --nodefault --env:UserInstallation=%s --convert-to pdf --outdir %s %s 2>&1',
            $timeout,
            escapeshellarg($home),
            escapeshellarg($runtime),
            escapeshellarg($binary),
            escapeshellarg($profileUrl),
            escapeshellarg($workDir),
            escapeshellarg($localSource),
        );

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        $stdout = implode("\n", $output);
        $stderr = '';

        $stem = pathinfo($safeBase, PATHINFO_FILENAME);
        $pdfPath = $workDir.'/'.$stem.'.pdf';

        $dirListing = $this->safeListDirBasenames($workDir);
        $memAfter = memory_get_usage(true);

        $this->cleanupUserProfileDir($profileDir);

        $pdfExists = is_file($pdfPath);
        $pdfSize = $pdfExists ? (int) filesize($pdfPath) : null;
        $success = $exitCode === 0 && $pdfExists && $pdfSize !== null && $pdfSize > 0;

        $base = array_merge($context, [
            'local_input_path' => $sourcePath,
            'work_dir' => $workDir,
            'profile_dir' => $profileDir,
            'user_installation_url' => $profileUrl,
            'output_dir' => $workDir,
            'command' => $cmd,
            'timeout_seconds' => $timeout,
            'exit_code' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'output_dir_files' => $dirListing,
            'memory_bytes_before' => $memBefore,
            'memory_bytes_after' => $memAfter,
            'pdf_path_expected' => $pdfPath,
            'pdf_exists' => $pdfExists,
            'pdf_size' => $pdfSize,
            'libreoffice_binary' => $binary,
            'libreoffice_version_line' => $this->readSofficeVersionLine($binary),
            'success' => $success,
        ]);

        if (! $success) {
            $base['error_message'] = 'LibreOffice failed to produce a PDF preview. '.$this->abbreviateLoOutput($stdout);
            Log::warning('[LibreOfficeDocumentPreviewService] LibreOffice conversion failed', $base);
            if ($deleteWorkDirWhenUnsuccessful) {
                try {
                    File::deleteDirectory($workDir);
                } catch (\Throwable) {
                }
            }
            $base['pdf_path'] = null;

            return $base;
        }

        Log::info('[LibreOfficeDocumentPreviewService] LibreOffice conversion succeeded', $base);
        $base['pdf_path'] = $pdfPath;

        return $base;
    }

    /**
     * Convert an Office document on disk to PDF (all sheets / pages collapsed per LibreOffice rules).
     *
     * @param  array<string, mixed>  $context  Optional diagnostics: asset_id, asset_version_id, original_filename, mime_type, job_temp_dir
     * @return array{pdf_path: string, work_dir: string} Absolute paths; caller must delete {@code work_dir} when finished.
     *
     * @throws \RuntimeException When the binary is missing, source is too large, or conversion fails.
     */
    public function convertToPdf(string $sourcePath, array $context = []): array
    {
        $d = $this->convertToPdfWithDiagnostics($sourcePath, $context, true);
        if (! ($d['success'] ?? false)) {
            throw new \RuntimeException((string) ($d['error_message'] ?? 'LibreOffice failed to produce a PDF preview.'));
        }

        return [
            'pdf_path' => (string) $d['pdf_path'],
            'work_dir' => (string) $d['work_dir'],
        ];
    }

    private function profileDirToFileUrl(string $absoluteDir): string
    {
        $normalized = str_replace('\\', '/', $absoluteDir);
        if ($normalized === '') {
            return 'file:///';
        }
        if (! str_starts_with($normalized, '/')) {
            $normalized = '/'.$normalized;
        }
        $segments = array_values(array_filter(explode('/', $normalized), static fn (string $s): bool => $s !== ''));
        $encoded = array_map(static fn (string $segment): string => rawurlencode($segment), $segments);

        return 'file:///'.implode('/', $encoded);
    }

    /**
     * @return list<string>
     */
    private function safeListDirBasenames(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $names = @scandir($dir);
        if ($names === false) {
            return [];
        }

        return array_values(array_filter($names, static fn (string $n): bool => $n !== '.' && $n !== '..'));
    }

    private function cleanupUserProfileDir(string $profileDir): void
    {
        if ($profileDir !== '' && is_dir($profileDir)) {
            try {
                File::deleteDirectory($profileDir);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    private function safeBasename(string $path): string
    {
        $base = basename($path);
        $base = preg_replace('/[^a-zA-Z0-9._\-]+/', '_', $base) ?? 'document';
        if ($base === '' || $base === '.' || $base === '..') {
            $base = 'document.office';
        }

        return $base;
    }

    private function abbreviateLoOutput(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strlen($raw) > 400) {
            return substr($raw, 0, 400).'…';
        }

        return $raw;
    }
}
