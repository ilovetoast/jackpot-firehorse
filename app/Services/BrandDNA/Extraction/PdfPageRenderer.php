<?php

namespace App\Services\BrandDNA\Extraction;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Converts PDF pages to PNG images using pdftoppm (poppler-utils).
 * Used for vision-based extraction of image-only PDFs.
 */
class PdfPageRenderer
{
    public const MAX_PAGES = 15;

    public const DPI = 120;

    /**
     * Convert PDF to PNG pages. Max 15 pages.
     *
     * @return array<int, string> Map of 1-based page number => local PNG path
     */
    public function renderPages(string $localPdfPath, int $maxPages = self::MAX_PAGES): array
    {
        if (! file_exists($localPdfPath) || ! is_readable($localPdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable.');
        }

        $pageCount = $this->getPageCount($localPdfPath);
        $pagesToRender = min($pageCount, $maxPages);

        if ($pagesToRender < 1) {
            return [];
        }

        $outputDir = sys_get_temp_dir() . '/pdf_pages_' . uniqid();
        if (! @mkdir($outputDir, 0755, true)) {
            throw new RuntimeException('Unable to create temporary directory for PDF pages.');
        }

        try {
            $baseName = 'page';
            $cmd = sprintf(
                'pdftoppm -png -r %d -f 1 -l %d %s %s 2>&1',
                self::DPI,
                $pagesToRender,
                escapeshellarg($localPdfPath),
                escapeshellarg($outputDir . '/' . $baseName)
            );
            $output = [];
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $stderr = implode("\n", $output);
                Log::error('[PdfPageRenderer] pdftoppm failed', [
                    'exit_code' => $exitCode,
                    'stderr' => $stderr,
                ]);
                throw new RuntimeException('pdftoppm failed: ' . ($stderr ?: 'Is poppler-utils installed?'));
            }

            $result = [];
            $glob = glob($outputDir . '/' . $baseName . '-*.png');
            foreach ($glob ?: [] as $path) {
                if (preg_match('/-(\d+)\.png$/', $path, $m)) {
                    $p = (int) $m[1];
                    if ($p >= 1 && $p <= $pagesToRender) {
                        $result[$p] = $path;
                    }
                }
            }
            ksort($result);

            return $result;
        } catch (\Throwable $e) {
            $this->cleanupDir($outputDir);
            throw $e;
        }
    }

    /**
     * Clean up rendered page files. Call when done processing.
     */
    public function cleanupPages(array $paths): void
    {
        $dirs = [];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                @unlink($path);
                $dirs[dirname($path)] = true;
            }
        }
        foreach (array_keys($dirs) as $dir) {
            if (is_dir($dir) && str_contains($dir, 'pdf_pages_')) {
                @rmdir($dir);
            }
        }
    }

    public function isPdftoppmAvailable(): bool
    {
        $output = [];
        exec('which pdftoppm 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && ! empty($output);
    }

    protected function getPageCount(string $localPdfPath): int
    {
        $output = [];
        exec('pdfinfo ' . escapeshellarg($localPdfPath) . ' 2>/dev/null | grep Pages', $output, $exitCode);
        if ($exitCode === 0 && ! empty($output) && preg_match('/Pages:\s*(\d+)/', $output[0], $m)) {
            return (int) $m[1];
        }
        // Fallback: try pdftoppm with -f 1 -l 1 to get at least 1
        return 1;
    }

    protected function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        foreach ($files ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }
}
