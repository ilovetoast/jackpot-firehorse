<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\PdfTextExtraction;
use App\Services\PdfPageRenderingService;
use App\Services\PdfTextExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractPdfTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    /** @var string */
    public $assetId;

    /** @var int */
    public $extractionId;

    /** @var string|null */
    public $assetVersionId;

    public function __construct(string $assetId, int $extractionId, ?string $assetVersionId = null)
    {
        $this->assetId = $assetId;
        $this->extractionId = $extractionId;
        $this->assetVersionId = $assetVersionId;
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(
        PdfPageRenderingService $pdfPageRenderingService,
        PdfTextExtractionService $extractionService
    ): void {
        $asset = Asset::with(['storageBucket', 'tenant', 'currentVersion'])->find($this->assetId);
        if (!$asset) {
            Log::warning('[ExtractPdfTextJob] Asset not found', ['asset_id' => $this->assetId]);
            return;
        }

        $version = $this->assetVersionId
            ? AssetVersion::with('asset')->find($this->assetVersionId)
            : null;
        $activeVersion = $version ?: $asset->currentVersion;

        $mime = strtolower((string) ($asset->mime_type ?? ''));
        if (!str_contains($mime, 'pdf')) {
            Log::warning('[ExtractPdfTextJob] Asset is not a PDF', ['asset_id' => $asset->id]);
            return;
        }

        $extraction = PdfTextExtraction::where('asset_id', $asset->id)->find($this->extractionId);
        if (!$extraction) {
            Log::warning('[ExtractPdfTextJob] Extraction record not found', ['extraction_id' => $this->extractionId, 'asset_id' => $asset->id]);
            return;
        }

        if (!$extractionService->isPdftotextAvailable()) {
            $extraction->update([
                'status' => PdfTextExtraction::STATUS_FAILED,
                'processed_at' => now(),
                'error_message' => 'pdftotext is not installed. Install poppler-utils (e.g. apt-get install poppler-utils) on the queue worker.',
            ]);
            Log::error('[ExtractPdfTextJob] pdftotext not available on this system', ['asset_id' => $asset->id, 'extraction_id' => $extraction->id]);
            return;
        }

        $extraction->update([
            'status' => PdfTextExtraction::STATUS_PROCESSING,
            'error_message' => null,
        ]);

        $tempPdfPath = null;
        try {
            $tempPdfPath = $pdfPageRenderingService->downloadSourcePdfToTemp($asset, $activeVersion);
            $result = $extractionService->extractFromPath($tempPdfPath);
            $characterCount = mb_strlen($result['text']);

            $extraction->update([
                'extracted_text' => $result['text'],
                'character_count' => $characterCount,
                'extraction_source' => $result['source'],
                'status' => PdfTextExtraction::STATUS_COMPLETE,
                'processed_at' => now(),
                'error_message' => null,
            ]);

            Log::info('[ExtractPdfTextJob] PDF text extraction completed', [
                'asset_id' => $asset->id,
                'extraction_id' => $extraction->id,
                'source' => $result['source'],
                'character_count' => $characterCount,
            ]);
        } catch (\Throwable $e) {
            $extraction->update([
                'status' => PdfTextExtraction::STATUS_FAILED,
                'processed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            Log::error('[ExtractPdfTextJob] PDF text extraction failed', [
                'asset_id' => $asset->id,
                'extraction_id' => $extraction->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            if ($tempPdfPath && file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
        }
    }
}
