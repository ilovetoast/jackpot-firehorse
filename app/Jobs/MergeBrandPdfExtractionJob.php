<?php

namespace App\Jobs;

use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\BrandDNA\Extraction\PdfPageRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MergeBrandPdfExtractionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public string $batchId
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(PdfPageRenderer $pageRenderer): void
    {
        $visionBatch = BrandPdfVisionExtraction::where('batch_id', $this->batchId)->first();
        if (! $visionBatch) {
            Log::warning('[MergeBrandPdfExtractionJob] Batch not found', ['batch_id' => $this->batchId]);
            return;
        }

        if ($visionBatch->status === BrandPdfVisionExtraction::STATUS_COMPLETED) {
            return;
        }

        $pages = BrandPdfPageExtraction::where('batch_id', $this->batchId)
            ->where('status', BrandPdfPageExtraction::STATUS_COMPLETED)
            ->orderBy('page_number')
            ->get();

        $extractions = [];
        $pathsToClean = [];
        foreach ($pages as $p) {
            $ext = $p->extraction_json ?? [];
            $path = $ext['_temp_image_path'] ?? null;
            if ($path) {
                $pathsToClean[] = $path;
            }
            unset($ext['_temp_image_path'], $ext['page_number']);
            $extractions[] = $ext;
        }

        $merged = empty($extractions)
            ? BrandExtractionSchema::empty()
            : $this->mergePageResults($extractions);

        $visionBatch->update([
            'extraction_json' => $merged,
            'status' => BrandPdfVisionExtraction::STATUS_COMPLETED,
            'error_message' => null,
        ]);

        $pageRenderer->cleanupPages($pathsToClean);

        RunBrandIngestionJob::dispatch(
            $visionBatch->brand_id,
            $visionBatch->brand_model_version_id,
            $visionBatch->asset_id,
            null,
            []
        );

        Log::info('[MergeBrandPdfExtractionJob] Merged and dispatched ingestion', [
            'batch_id' => $this->batchId,
            'pages_merged' => count($extractions),
        ]);
    }

    protected function mergePageResults(array $pageExtractions): array
    {
        $result = BrandExtractionSchema::empty();

        foreach ($pageExtractions as $ext) {
            $result = BrandExtractionSchema::merge($result, $ext);
        }

        return $result;
    }
}
