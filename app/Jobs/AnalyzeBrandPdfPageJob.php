<?php

namespace App\Jobs;

use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\BrandExtractionFusionService;
use App\Services\BrandDNA\Extraction\VisionExtractionService;
use App\Services\BrandDNA\FieldCandidateValidationService;
use App\Services\BrandDNA\PdfPageClassificationService;
use App\Services\BrandDNA\PdfPageVisualExtractionService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeBrandPdfPageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public int $pageExtractionId
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(
        VisionExtractionService $visionService,
        PdfPageClassificationService $classificationService,
        PdfPageVisualExtractionService $visualExtractionService,
        FieldCandidateValidationService $validationService
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('[AnalyzeBrandPdfPageJob] Started', [
            'page_extraction_id' => $this->pageExtractionId,
        ]);

        $pageExt = BrandPdfPageExtraction::find($this->pageExtractionId);
        if (! $pageExt) {
            return;
        }

        if ($pageExt->status === BrandPdfPageExtraction::STATUS_CANCELLED) {
            return;
        }

        $visionBatch = BrandPdfVisionExtraction::where('batch_id', $pageExt->batch_id)->first();
        if (! $visionBatch) {
            $pageExt->update(['status' => BrandPdfPageExtraction::STATUS_CANCELLED]);
            return;
        }

        $pageExt->update(['status' => BrandPdfPageExtraction::STATUS_PROCESSING]);

        $imagePath = $pageExt->extraction_json['_temp_image_path'] ?? null;
        if (! $imagePath || ! file_exists($imagePath)) {
            $pageExt->update([
                'status' => BrandPdfPageExtraction::STATUS_FAILED,
                'error_message' => 'Image path missing or file not found',
            ]);
            return;
        }

        $useVisualPipeline = config('brand_dna.visual_page_extraction_enabled', false);

        Log::info('[AnalyzeBrandPdfPageJob] Pipeline config', [
            'page_extraction_id' => $pageExt->id,
            'visual_page_extraction_enabled' => $useVisualPipeline,
            'env' => config('app.env'),
            'path' => $useVisualPipeline ? 'visual' : 'legacy',
        ]);

        try {
            if ($useVisualPipeline) {
                $extraction = $this->runVisualPipeline($imagePath, $pageExt, $classificationService, $visualExtractionService, $validationService);
            } else {
                $extraction = $visionService->extractFromImage($imagePath);
                $extraction = array_merge($extraction, [
                    'page_number' => $pageExt->page_number,
                    'confidence' => $extraction['confidence'] ?? 0.5,
                ]);
            }

            // Write extraction data FIRST, then status last — prevents Merge from reading completed before data is persisted
            $pageExt->update([
                'extraction_json' => $extraction,
                'error_message' => null,
            ]);
            $pageExt->update(['status' => BrandPdfPageExtraction::STATUS_COMPLETED]);

            Log::info('[AnalyzeBrandPdfPageJob] Completed', [
                'page_extraction_id' => $pageExt->id,
                'has_page_classification' => ! empty($extraction['_page_classification']),
                'has_page_extractions' => isset($extraction['_page_extractions']) && is_array($extraction['_page_extractions']),
                'page_extractions_count' => isset($extraction['_page_extractions']) ? count($extraction['_page_extractions']) : 0,
            ]);

            $visionBatch->increment('pages_processed');
            $signals = $this->countSignals($extraction);
            $visionBatch->update(['signals_detected' => $visionBatch->signals_detected + $signals]);

            $visionBatch->refresh();
            // Merge is now dispatched by Bus::batch()->then() in RunBrandPdfVisionExtractionJob
            // when ALL page jobs complete. No early exit — every page must be processed.
        } catch (\Throwable $e) {
            $pageExt->update([
                'status' => BrandPdfPageExtraction::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            Log::error('[AnalyzeBrandPdfPageJob] Page analysis failed', [
                'page_extraction_id' => $pageExt->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function runVisualPipeline(
        string $imagePath,
        BrandPdfPageExtraction $pageExt,
        PdfPageClassificationService $classificationService,
        PdfPageVisualExtractionService $visualExtractionService,
        FieldCandidateValidationService $validationService
    ): array {
        $pageNum = $pageExt->page_number;
        $ocrText = $pageExt->extraction_json['_ocr_text'] ?? null;

        $classification = $classificationService->classifyPage($imagePath, $pageNum);
        $pageResult = $visualExtractionService->extractFromPage($imagePath, $classification, $ocrText);

        $rawExtractions = $pageResult['extractions'] ?? [];
        [$acceptedExtractions, $rejectedCandidates] = $validationService->validateMany($rawExtractions);

        $pageResultValidated = array_merge($pageResult, ['extractions' => $acceptedExtractions]);

        $fusionService = app(BrandExtractionFusionService::class);
        $schema = $fusionService->pageExtractionsToSchema([$pageResultValidated]);

        $rejectedForDebug = array_map(fn ($r) => [
            'path' => $r['path'] ?? null,
            'value' => $r['value'] ?? null,
            'reason' => $r['reason'] ?? 'rejected',
            'page' => $pageNum,
            'page_type' => $pageResult['page_type'] ?? null,
        ], $rejectedCandidates);

        return array_merge($schema, [
            'page_number' => $pageNum,
            '_page_classification' => $classification,
            '_page_extractions' => $acceptedExtractions,
            '_raw_candidates' => $rawExtractions,
            '_rejected_candidates' => $rejectedForDebug,
            '_ocr_text' => $ocrText,
            'confidence' => $classification['confidence'] ?? $schema['confidence'] ?? 0.5,
        ]);
    }

    protected function countSignals(array $extraction): int
    {
        $c = 0;
        foreach (['identity', 'personality', 'visual'] as $section) {
            $data = $extraction[$section] ?? [];
            foreach ($data as $v) {
                if ($v !== null && $v !== '' && $v !== []) {
                    $c++;
                }
            }
        }
        return $c;
    }
}
