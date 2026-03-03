<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\AssetVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\PdfTextExtraction;
use App\Jobs\RunBrandPdfVisionExtractionJob;
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

            if ($characterCount === 0) {
                $extraction->update([
                    'extracted_text' => null,
                    'character_count' => 0,
                    'extraction_source' => $result['source'],
                    'status' => PdfTextExtraction::STATUS_FAILED,
                    'processed_at' => now(),
                    'error_message' => 'No selectable text detected.',
                    'failure_reason' => 'No selectable text detected',
                ]);
                Log::warning('[ExtractPdfTextJob] PDF extraction produced no text, triggering vision fallback', [
                    'asset_id' => $asset->id,
                    'extraction_id' => $extraction->id,
                ]);
                $this->dispatchVisionFallback($asset);
                return;
            }


            $extraction->update([
                'extracted_text' => $result['text'],
                'character_count' => $characterCount,
                'extraction_source' => $result['source'],
                'status' => PdfTextExtraction::STATUS_COMPLETE,
                'processed_at' => now(),
                'error_message' => null,
                'failure_reason' => null,
            ]);

            Log::info('[ExtractPdfTextJob] PDF text extraction completed', [
                'asset_id' => $asset->id,
                'extraction_id' => $extraction->id,
                'source' => $result['source'],
                'character_count' => $characterCount,
            ]);

            $builderContext = $asset->builder_context ?? '';
            if ($characterCount < 500 && $builderContext === 'guidelines_pdf') {
                $this->dispatchVisionFallback($asset);
                return;
            }

            // Guarantee ingestion runs after extraction (no reliance on frontend)
            if ($characterCount > 0 && $builderContext === 'guidelines_pdf') {
                $pivot = BrandModelVersionAsset::where('asset_id', $asset->id)
                    ->where('builder_context', 'guidelines_pdf')
                    ->first();
                if ($pivot) {
                    $draft = $pivot->brandModelVersion;
                    if ($draft) {
                        $draft->loadMissing('brandModel');
                    }
                    if ($draft?->brandModel) {
                        RunBrandIngestionJob::dispatch(
                            $draft->brandModel->brand_id,
                            $draft->id,
                            $asset->id
                        );
                        Log::info('[ExtractPdfTextJob] Dispatched RunBrandIngestionJob after extraction', [
                            'asset_id' => $asset->id,
                            'draft_id' => $draft->id,
                            'brand_id' => $draft->brandModel->brand_id,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $extraction->update([
                'status' => PdfTextExtraction::STATUS_FAILED,
                'processed_at' => now(),
                'error_message' => $e->getMessage(),
                'failure_reason' => $e->getMessage(),
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

    protected function dispatchVisionFallback(Asset $asset): void
    {
        $pivot = BrandModelVersionAsset::where('asset_id', $asset->id)
            ->where('builder_context', 'guidelines_pdf')
            ->first();
        if (! $pivot) {
            return;
        }
        $draft = $pivot->brandModelVersion;
        if (! $draft?->brandModel) {
            return;
        }
        RunBrandPdfVisionExtractionJob::dispatch(
            $asset->id,
            $draft->brandModel->brand_id,
            $draft->id
        );
        Log::info('[ExtractPdfTextJob] Dispatched RunBrandPdfVisionExtractionJob (multimodal fallback)', [
            'asset_id' => $asset->id,
            'draft_id' => $draft->id,
        ]);
    }
}
