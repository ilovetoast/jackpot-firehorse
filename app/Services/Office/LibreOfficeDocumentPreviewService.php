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

        foreach (['soffice', '/usr/bin/soffice', '/usr/lib/libreoffice/program/soffice'] as $candidate) {
            if ($candidate === 'soffice') {
                $out = [];
                $rc = 0;
                @exec('command -v soffice 2>/dev/null', $out, $rc);
                if ($rc === 0 && ! empty($out[0]) && is_executable($out[0])) {
                    return $out[0];
                }
            } elseif (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function isAvailable(): bool
    {
        return $this->findBinary() !== null
            && extension_loaded('imagick')
            && class_exists(\Spatie\PdfToImage\Pdf::class);
    }

    /**
     * Convert an Office document on disk to PDF (all sheets / pages collapsed per LibreOffice rules).
     *
     * @return array{pdf_path: string, work_dir: string} Absolute paths; caller must delete {@code work_dir} when finished.
     *
     * @throws \RuntimeException When the binary is missing, source is too large, or conversion fails.
     */
    public function convertToPdf(string $sourcePath): array
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

        $maxBytes = (int) config('assets.thumbnail.office.max_source_bytes', 52_428_800);
        if ($maxBytes > 0) {
            $size = (int) filesize($sourcePath);
            if ($size > $maxBytes) {
                throw new \RuntimeException(
                    "Office document ({$size} bytes) exceeds configured maximum ({$maxBytes} bytes) for LibreOffice conversion.",
                );
            }
        }

        $workDir = sys_get_temp_dir().'/jp_lo_'.bin2hex(random_bytes(8));
        if (! @mkdir($workDir, 0700, true) && ! is_dir($workDir)) {
            throw new \RuntimeException("Unable to create LibreOffice work directory: {$workDir}");
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

        $cmd = sprintf(
            'timeout %d env HOME=%s XDG_RUNTIME_DIR=%s %s --headless --nologo --norestore --nodefault --convert-to pdf --outdir %s %s 2>&1',
            $timeout,
            escapeshellarg($home),
            escapeshellarg($runtime),
            escapeshellarg($binary),
            escapeshellarg($workDir),
            escapeshellarg($localSource),
        );

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        $tail = implode("\n", array_slice($output, 0, 40));

        $stem = pathinfo($safeBase, PATHINFO_FILENAME);
        $pdfPath = $workDir.'/'.$stem.'.pdf';

        if ($exitCode !== 0 || ! is_file($pdfPath) || filesize($pdfPath) === 0) {
            File::deleteDirectory($workDir);
            Log::warning('[LibreOfficeDocumentPreviewService] LibreOffice conversion failed', [
                'exit_code' => $exitCode,
                'output_tail' => $tail,
                'source' => $sourcePath,
            ]);
            throw new \RuntimeException(
                'LibreOffice failed to produce a PDF preview. '.$this->abbreviateLoOutput($tail),
            );
        }

        return [
            'pdf_path' => $pdfPath,
            'work_dir' => $workDir,
        ];
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
