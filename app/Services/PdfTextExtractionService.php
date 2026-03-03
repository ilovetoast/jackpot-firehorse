<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class PdfTextExtractionService
{
    /**
     * Extract text from a local PDF file using pdftotext (poppler-utils).
     *
     * @param string $localPdfPath Absolute path to the PDF file
     * @return array{text: string, source: string, exit_code: int, stderr: string}
     * @throws RuntimeException If extraction fails or pdftotext is not available
     */
    public function extractFromPath(string $localPdfPath): array
    {
        if (!file_exists($localPdfPath) || !is_readable($localPdfPath)) {
            throw new RuntimeException('PDF file does not exist or is not readable.');
        }

        $fileSize = filesize($localPdfPath) ?: 0;
        $maxSizeBytes = 25 * 1024 * 1024; // 25MB hard limit for extraction (abuse / meltdown prevention)
        if ($fileSize > $maxSizeBytes) {
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
            $stderr = implode("\n", $output);

            if (!file_exists($outputPath)) {
                Log::error('[PdfTextExtractionService] pdftotext diagnostics', [
                    'file_size' => $fileSize,
                    'exit_code' => $exitCode,
                    'stderr' => $stderr,
                ]);
                throw new RuntimeException(
                    'pdftotext did not produce output. Exit ' . $exitCode . '. ' . ($stderr ?: 'Is poppler-utils installed?')
                );
            }

            $text = file_get_contents($outputPath);
            if ($text === false) {
                throw new RuntimeException('Unable to read extracted text.');
            }

            $textLength = mb_strlen($text);
            $first200 = mb_substr($text, 0, 200);

            Log::info('[PdfTextExtractionService] pdftotext diagnostics', [
                'file_size' => $fileSize,
                'extracted_text_length' => $textLength,
                'first_200_chars' => $first200,
                'exit_code' => $exitCode,
                'stderr' => $stderr,
            ]);

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'pdftotext failed (exit ' . $exitCode . '). ' . ($stderr ?: 'Is poppler-utils installed?')
                );
            }

            return [
                'text' => $text,
                'source' => 'pdftotext',
                'exit_code' => $exitCode,
                'stderr' => $stderr,
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
