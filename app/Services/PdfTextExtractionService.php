<?php

namespace App\Services;

use RuntimeException;

class PdfTextExtractionService
{
    /**
     * Extract text from a local PDF file using pdftotext (poppler-utils).
     *
     * @param string $localPdfPath Absolute path to the PDF file
     * @return array{text: string, source: string}
     * @throws RuntimeException If extraction fails or pdftotext is not available
     */
    public function extractFromPath(string $localPdfPath): array
    {
        if (!file_exists($localPdfPath) || !is_readable($localPdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable.');
        }

        $size = filesize($localPdfPath) ?: 0;
        $maxSizeBytes = 25 * 1024 * 1024; // 25MB hard limit for extraction (abuse / meltdown prevention)
        if ($size > $maxSizeBytes) {
            throw new RuntimeException('PDF too large for extraction (max 25MB).');
        }

        $outputPath = tempnam(sys_get_temp_dir(), 'pdf_txt_');
        if ($outputPath === false) {
            throw new RuntimeException('Unable to create temporary output file.');
        }

        try {
            // pdftotext -layout keeps rough layout; -enc UTF-8 ensures UTF-8 output
            $cmd = sprintf(
                'pdftotext -layout -enc UTF-8 %s %s 2>&1',
                escapeshellarg($localPdfPath),
                escapeshellarg($outputPath)
            );
            $output = [];
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $stderr = implode("\n", $output);
                throw new RuntimeException(
                    'pdftotext failed (exit ' . $exitCode . '). ' . ($stderr ?: 'Is poppler-utils installed?')
                );
            }

            if (!file_exists($outputPath)) {
                throw new RuntimeException('pdftotext did not produce output.');
            }

            $text = file_get_contents($outputPath);
            if ($text === false) {
                throw new RuntimeException('Unable to read extracted text.');
            }

            return [
                'text' => $text,
                'source' => 'pdftotext',
            ];
        } finally {
            if ($outputPath && file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * Check if pdftotext is available on the system.
     */
    public function isPdftotextAvailable(): bool
    {
        $output = [];
        exec('which pdftotext 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 && !empty($output);
    }
}
