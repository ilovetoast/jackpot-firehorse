<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Extracts text from image files using the system Tesseract binary.
 *
 * Intentionally shells out via {@see Process} rather than depending on a native
 * binding, so it mirrors how {@see \App\Services\PdfTextExtractionService}
 * shells out to pdftotext. When the binary is missing, isAvailable() returns
 * false and callers can surface a helpful error without failing the job.
 */
final class ImageOcrService
{
    public const DEFAULT_LANGUAGE = 'eng';

    /** Tesseract OEM 3 = LSTM only (most accurate). PSM 6 = assume uniform block of text. */
    private const DEFAULT_TESSERACT_ARGS = ['--oem', '3', '--psm', '6'];

    /** Upper bound on raw OCR text written to metadata. Prevents runaway PDFs from bloating JSON rows. */
    public const MAX_OCR_TEXT_CHARS = 50_000;

    public function isAvailable(): bool
    {
        try {
            $process = new Process(['tesseract', '--version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run OCR on a local image file and return the extracted text.
     *
     * @return array{text: string, source: string, truncated: bool}
     */
    public function extractFromPath(string $localPath, string $language = self::DEFAULT_LANGUAGE): array
    {
        if (! is_file($localPath)) {
            throw new \InvalidArgumentException("Image not found at path: {$localPath}");
        }

        $args = array_merge(
            ['tesseract', $localPath, 'stdout', '-l', $language],
            self::DEFAULT_TESSERACT_ARGS,
        );

        $process = new Process($args);
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (ProcessFailedException $e) {
            Log::warning('[ImageOcrService] tesseract process failed', [
                'path' => $localPath,
                'error' => $e->getMessage(),
            ]);

            return ['text' => '', 'source' => 'tesseract_failed', 'truncated' => false];
        }

        if (! $process->isSuccessful()) {
            Log::warning('[ImageOcrService] tesseract exited non-zero', [
                'path' => $localPath,
                'exit_code' => $process->getExitCode(),
                'stderr' => $process->getErrorOutput(),
            ]);

            return ['text' => '', 'source' => 'tesseract_nonzero', 'truncated' => false];
        }

        $raw = trim((string) $process->getOutput());
        $truncated = false;
        if (mb_strlen($raw) > self::MAX_OCR_TEXT_CHARS) {
            $raw = mb_substr($raw, 0, self::MAX_OCR_TEXT_CHARS);
            $truncated = true;
        }

        return ['text' => $raw, 'source' => 'tesseract', 'truncated' => $truncated];
    }
}
