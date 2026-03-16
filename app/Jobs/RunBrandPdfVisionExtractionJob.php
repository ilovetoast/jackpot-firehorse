<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\Extraction\PdfPageRenderer;
use App\Services\PdfPageRenderingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Multimodal fallback for image-based PDFs.
 * Converts PDF to pages, dispatches per-page analysis via Bus::batch().
 * Merge runs ONLY when ALL page jobs complete (no race condition, no early exit).
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
        Log::info('[RunBrandPdfVisionExtractionJob] Started', [
            'asset_id' => $this->assetId,
            'brand_id' => $this->brandId,
            'brand_model_version_id' => $this->brandModelVersionId,
        ]);

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

            Log::info('[RunBrandPdfVisionExtractionJob] Page render complete', [
                'batch_id' => $batchId,
                'pages_count' => count($pagePaths),
            ]);

            if (empty($pagePaths)) {
                $visionBatch->update([
                    'status' => BrandPdfVisionExtraction::STATUS_FAILED,
                    'error_message' => 'No pages could be rendered',
                ]);
                return;
            }

            $pageJobs = [];
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
                $pageJobs[] = new AnalyzeBrandPdfPageJob($pageExt->id);
            }

            Bus::batch($pageJobs)
                ->then(function () use ($batchId) {
                    // Delay prevents DB write timing issues where page jobs finish but transactions are still flushing
                    MergeBrandPdfExtractionJob::dispatch($batchId)
                        ->delay(now()->addSeconds(3));
                })
                ->catch(function (\Throwable $e) use ($visionBatch) {
                    Log::error('[RunBrandPdfVisionExtractionJob] Page batch failed', [
                        'batch_id' => $visionBatch->batch_id,
                        'error' => $e->getMessage(),
                    ]);
                    $visionBatch->update([
                        'status' => BrandPdfVisionExtraction::STATUS_FAILED,
                        'error_message' => 'Page extraction failed: ' . $e->getMessage(),
                    ]);
                })
                ->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'))
                ->dispatch();

            Log::info('[RunBrandPdfVisionExtractionJob] Dispatched page batch', [
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
