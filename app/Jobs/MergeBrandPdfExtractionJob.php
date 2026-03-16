<?php

namespace App\Jobs;

use App\Models\BrandPdfPageExtraction;
use App\Models\BrandPdfVisionExtraction;
use App\Services\BrandDNA\PageThumbnailGenerator;
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

    public int $tries = 5;

    public array $backoff = [5, 10, 20];

    public int $timeout = 120;

    public function __construct(
        public string $batchId,
        public int $attempt = 1
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(PdfPageRenderer $pageRenderer, PageThumbnailGenerator $thumbnailGenerator): void
    {
        $visionBatch = BrandPdfVisionExtraction::where('batch_id', $this->batchId)->first();
        if (! $visionBatch) {
            Log::warning('[MergeBrandPdfExtractionJob] Batch not found', ['batch_id' => $this->batchId]);
            return;
        }

        Log::info('[MergeBrandPdfExtractionJob] Started', [
            'batch_id' => $this->batchId,
            'attempt' => $this->attempt,
        ]);

        if ($visionBatch->status === BrandPdfVisionExtraction::STATUS_COMPLETED) {
            Log::info('[MergeBrandPdfExtractionJob] Batch already completed, skipping');
            return;
        }

        $pages = BrandPdfPageExtraction::where('batch_id', $this->batchId)->get();
        $totalPages = $pages->count();

        // Data presence guard: require actual extraction data, not just status flags
        $readyPages = $pages->filter(function ($page) {
            $ext = $page->extraction_json ?? [];
            return ! empty($ext['_page_classification'])
                || ! empty($ext['_page_extractions'])
                || ! empty($ext['identity'])
                || ! empty($ext['personality'])
                || ! empty($ext['visual']);
        });

        if ($totalPages === 0 || $readyPages->count() !== $totalPages) {
            Log::warning('[MergeBrandPdfExtractionJob] Skipped — page data incomplete', [
                'batch_id' => $this->batchId,
                'total_pages' => $totalPages,
                'ready_pages' => $readyPages->count(),
                'attempt' => $this->attempt,
            ]);
            if ($this->attempt < 5) {
                self::dispatch($this->batchId, $this->attempt + 1)->delay(now()->addSeconds(5));
            }
            return;
        }

        $pages = $readyPages->sortBy('page_number')->values();

        $extractions = [];
        $pathsToClean = [];
        $pageClassifications = [];
        $pageExtractionsRaw = [];
        $pageAnalysisRecords = [];
        $rejectedFieldCandidates = [];
        $usedVisualPipeline = false;
        $pageNumToPath = [];

        foreach ($pages as $p) {
            $ext = $p->extraction_json ?? [];
            $path = $ext['_temp_image_path'] ?? null;
            $pageNum = $ext['page_number'] ?? $p->page_number;
            if ($path) {
                $pathsToClean[] = $path;
                $pageNumToPath[$pageNum] = $path;
            }
            if (! empty($ext['_page_classification'])) {
                $usedVisualPipeline = true;
                $pageType = $ext['_page_classification']['page_type'] ?? 'unknown';
                $pageTitle = $ext['_page_classification']['title'] ?? null;
                $ocrText = $ext['_ocr_text'] ?? null;
                $eligibleFields = $this->getEligibleFieldsForPage($pageType, $pageTitle, $ocrText);
                $pageClassifications[] = array_merge($ext['_page_classification'], [
                    'page' => $pageNum,
                    'eligible_fields' => $eligibleFields,
                ]);
            }
            if (isset($ext['_page_extractions']) && is_array($ext['_page_extractions'])) {
                $pageType = $ext['_page_classification']['page_type'] ?? 'unknown';
                $pageTitle = $ext['_page_classification']['title'] ?? null;
                $ocrText = $ext['_ocr_text'] ?? null;
                $eligibleFields = $this->getEligibleFieldsForPage($pageType, $pageTitle, $ocrText);
                $acceptedPaths = array_values(array_unique(array_filter(array_map(
                    fn ($e) => $e['path'] ?? $e['field'] ?? null,
                    $ext['_page_extractions']
                ))));
                $rawPaths = array_values(array_unique(array_filter(array_map(
                    fn ($e) => $e['path'] ?? $e['field'] ?? null,
                    $ext['_raw_candidates'] ?? []
                ))));
                $attemptedFields = array_values(array_unique(array_merge($acceptedPaths, array_map(
                    fn ($r) => $r['path'] ?? null,
                    $ext['_rejected_candidates'] ?? []
                ))));
                $attemptedFields = array_values(array_unique(array_filter(array_merge($attemptedFields, $rawPaths))));
                $pageRejected = array_filter($ext['_rejected_candidates'] ?? [], fn ($r) => ($r['page'] ?? null) === $pageNum);
                $rejectedFields = array_map(fn ($r) => [
                    'path' => $r['path'] ?? null,
                    'value' => $r['value'] ?? null,
                    'reason' => $r['reason'] ?? 'rejected',
                ], $pageRejected);

                $pageExtractionsRaw[] = [
                    'page' => $pageNum,
                    'page_type' => $pageType,
                    'eligible_fields' => $eligibleFields,
                    'attempted_fields' => $attemptedFields,
                    'accepted_fields' => $acceptedPaths,
                    'rejected_fields' => $rejectedFields,
                    'extractions' => $ext['_page_extractions'],
                ];

                $ocrExcerpt = $ocrText && is_string($ocrText)
                    ? mb_substr(trim($ocrText), 0, 500) . (mb_strlen(trim($ocrText)) > 500 ? '…' : '')
                    : null;

                $pageAnalysisRecords[] = [
                    'page' => $pageNum,
                    'page_type' => $pageType,
                    'classification_confidence' => (float) ($ext['_page_classification']['confidence'] ?? 0.5),
                    'page_title' => $ext['_page_classification']['title'] ?? null,
                    'eligible_fields' => $eligibleFields,
                    'attempted_fields' => $attemptedFields,
                    'accepted_fields' => $acceptedPaths,
                    'rejected_fields' => $rejectedFields,
                    'ocr_text_excerpt' => $ocrExcerpt,
                    'ocr_text_full' => $ocrText && is_string($ocrText) ? $ocrText : null,
                    'raw_candidates' => $ext['_raw_candidates'] ?? [],
                    'accepted_candidates' => $ext['_page_extractions'],
                    'rejected_candidates' => $rejectedFields,
                    'used_in_final_merge' => [],
                ];
            } elseif (! empty($ext['_page_classification'])) {
                $pageType = $ext['_page_classification']['page_type'] ?? 'unknown';
                $pageTitle = $ext['_page_classification']['title'] ?? null;
                $ocrText = $ext['_ocr_text'] ?? null;
                $eligibleFields = $this->getEligibleFieldsForPage($pageType, $pageTitle, $ocrText);
                $ocrExcerpt = $ocrText && is_string($ocrText)
                    ? mb_substr(trim($ocrText), 0, 500) . (mb_strlen(trim($ocrText)) > 500 ? '…' : '')
                    : null;
                $pageAnalysisRecords[] = [
                    'page' => $pageNum,
                    'page_type' => $pageType,
                    'classification_confidence' => (float) ($ext['_page_classification']['confidence'] ?? 0.5),
                    'page_title' => $ext['_page_classification']['title'] ?? null,
                    'eligible_fields' => $eligibleFields,
                    'attempted_fields' => [],
                    'accepted_fields' => [],
                    'rejected_fields' => [],
                    'ocr_text_excerpt' => $ocrExcerpt,
                    'ocr_text_full' => $ocrText && is_string($ocrText) ? $ocrText : null,
                    'raw_candidates' => [],
                    'accepted_candidates' => [],
                    'rejected_candidates' => [],
                    'used_in_final_merge' => [],
                ];
            }
            if (! empty($ext['_rejected_candidates'])) {
                $rejectedFieldCandidates = array_merge($rejectedFieldCandidates, $ext['_rejected_candidates']);
            }
            unset(
                $ext['_temp_image_path'],
                $ext['page_number'],
                $ext['_page_classification'],
                $ext['_page_extractions'],
                $ext['_raw_candidates'],
                $ext['_rejected_candidates'],
                $ext['_ocr_text']
            );
            $extractions[] = $ext;
        }

        if ($usedVisualPipeline && ! empty($pageClassifications) && extension_loaded('gd')) {
            foreach ($pageClassifications as $i => $c) {
                $pageNum = $c['page'] ?? $i + 1;
                $path = $pageNumToPath[$pageNum] ?? null;
                if ($path) {
                    $thumb = $thumbnailGenerator->generate($path);
                    if ($thumb) {
                        $pageClassifications[$i]['thumbnail_base64'] = $thumb;
                        $pageAnalysisIdx = array_search($pageNum, array_column($pageAnalysisRecords, 'page'), true);
                        if ($pageAnalysisIdx !== false) {
                            $pageAnalysisRecords[$pageAnalysisIdx]['thumbnail_url'] = $thumb;
                        }
                    }
                }
            }
        }

        $merged = empty($extractions)
            ? BrandExtractionSchema::empty()
            : $this->mergePageResults($extractions);

        if ($usedVisualPipeline && ! empty($pageClassifications)) {
            $merged['page_classifications_json'] = $pageClassifications;
            $merged['page_extractions_json'] = $pageExtractionsRaw;
            $merged['page_analysis'] = $pageAnalysisRecords;
        }
        if (! empty($rejectedFieldCandidates)) {
            $merged['rejected_field_candidates'] = $rejectedFieldCandidates;
        }

        Log::info('[MergeBrandPdfExtractionJob] Merge inputs and output', [
            'batch_id' => $this->batchId,
            'page_job_results_count' => count($extractions),
            'page_classifications_count' => count($pageClassifications),
            'page_extractions_count' => count($pageExtractionsRaw),
            'page_analysis_count' => count($pageAnalysisRecords),
            'has_page_classifications_json' => ! empty($merged['page_classifications_json']),
            'has_page_extractions_json' => ! empty($merged['page_extractions_json']),
            'has_page_analysis' => ! empty($merged['page_analysis']),
        ]);

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
        )->delay(now()->addSeconds(2));

        Log::info('[MergeBrandPdfExtractionJob] Succeeded', [
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

    /**
     * Get eligible fields for a page. Uses config + fallback when page title/OCR contains explicit strategy labels.
     */
    protected function getEligibleFieldsForPage(string $pageType, ?string $pageTitle, $ocrText): array
    {
        $config = config('brand_dna_page_extraction.allowed_fields_by_page_type', []);
        $base = $config[$pageType] ?? [];

        $titleUpper = $pageTitle ? strtoupper($pageTitle) : '';
        $ocrUpper = is_string($ocrText) ? strtoupper(mb_substr(trim($ocrText), 0, 800)) : '';

        $fallbackFields = [];
        $strategyCues = [
            'PURPOSE' => ['identity.mission', 'identity.vision'],
            'PROMISE' => ['identity.positioning'],
            'POSITIONING' => ['identity.positioning', 'identity.industry', 'identity.tagline'],
            'BRAND VOICE' => ['personality.tone_keywords', 'personality.traits'],
            'VALUES' => ['identity.values'],
            'BELIEFS' => ['identity.beliefs'],
            'MISSION' => ['identity.mission'],
            'STRATEGY' => ['identity.mission', 'identity.positioning', 'identity.industry', 'identity.tagline'],
        ];

        foreach ($strategyCues as $cue => $fields) {
            if (str_contains($titleUpper, $cue) || str_contains($ocrUpper, $cue)) {
                $fallbackFields = array_merge($fallbackFields, $fields);
            }
        }

        if (empty($fallbackFields)) {
            return $base;
        }

        return array_values(array_unique(array_merge($base, $fallbackFields)));
    }
}
