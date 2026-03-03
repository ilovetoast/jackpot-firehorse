<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\Extraction\PdfPageRenderer;
use App\Services\PdfPageRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Multimodal fallback for image-based PDFs.
 * Converts PDF to pages, dispatches per-page analysis, then merge triggers ingestion.
 */
class RunBrandPdfVisionExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $assetId,
        public int $brandId,
        public int $brandModelVersionId
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(
        PdfPageRenderingService $pdfRenderingService,
        PdfPageRenderer $pageRenderer
    ): void {
        $asset = Asset::with('currentVersion')->find($this->assetId);
        if (! $asset) {
            Log::warning('[RunBrandPdfVisionExtractionJob] Asset not found', ['asset_id' => $this->assetId]);
            return;
        }

        $batchId = 'vision_' . $this->assetId . '_' . uniqid();

        $visionBatch = BrandPdfVisionExtraction::create([
            'batch_id' => $batchId,
            'brand_id' => $this->brandId,
            'brand_model_version_id' => $this->brandModelVersionId,
            'asset_id' => $this->assetId,
            'pages_total' => 0,
            'pages_processed' => 0,
            'status' => BrandPdfVisionExtraction::STATUS_PROCESSING,
        ]);

        $tempPdfPath = null;
        $pagePaths = [];

        try {
            $tempPdfPath = $pdfRenderingService->downloadSourcePdfToTemp($asset, $asset->currentVersion);

            if (! $pageRenderer->isPdftoppmAvailable()) {
                $visionBatch->update([
                    'status' => BrandPdfVisionExtraction::STATUS_FAILED,
                    'error_message' => 'pdftoppm not installed. Install poppler-utils.',
                ]);
                Log::error('[RunBrandPdfVisionExtractionJob] pdftoppm not available');
                return;
            }

            $pagePaths = $pageRenderer->renderPages($tempPdfPath, PdfPageRenderer::MAX_PAGES);
            $visionBatch->update(['pages_total' => count($pagePaths)]);

            if (empty($pagePaths)) {
                $visionBatch->update([
                    'status' => BrandPdfVisionExtraction::STATUS_FAILED,
                    'error_message' => 'No pages could be rendered',
                ]);
                return;
            }

            foreach ($pagePaths as $pageNum => $path) {
                $pageExt = BrandPdfPageExtraction::create([
                    'batch_id' => $batchId,
                    'brand_id' => $this->brandId,
                    'brand_model_version_id' => $this->brandModelVersionId,
                    'asset_id' => $this->assetId,
                    'page_number' => $pageNum,
                    'extraction_json' => ['_temp_image_path' => $path],
                    'status' => BrandPdfPageExtraction::STATUS_PENDING,
                ]);
                AnalyzeBrandPdfPageJob::dispatch($pageExt->id);
            }

            Log::info('[RunBrandPdfVisionExtractionJob] Dispatched page jobs', [
                'batch_id' => $batchId,
                'pages' => count($pagePaths),
            ]);
        } finally {
            if ($tempPdfPath && file_exists($tempPdfPath)) {
                @unlink($tempPdfPath);
            }
        }
    }
}
