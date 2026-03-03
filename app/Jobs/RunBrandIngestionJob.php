<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandIngestionRecord;
use App\Models\BrandPdfVisionExtraction;
use App\Models\BrandModelVersion;
use App\Models\BrandResearchSnapshot;
use App\Services\BrandDNA\BrandAlignmentEngine;
use App\Services\BrandDNA\BrandCoherenceScoringService;
use App\Services\BrandDNA\BrandWebsiteCrawlerService;
use App\Services\BrandDNA\Extraction\BrandExtractionSchema;
use App\Services\BrandDNA\Extraction\BrandGuidelinesProcessor;
use App\Services\BrandDNA\Extraction\BrandMaterialProcessor;
use App\Services\BrandDNA\Extraction\ExtractionSuggestionService;
use App\Services\BrandDNA\Extraction\WebsiteExtractionProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Unified Brand Ingestion Job.
 * Extracts from PDF, website, materials; merges; creates snapshot with suggestions.
 * Does NOT auto-mutate draft.
 */
class RunBrandIngestionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $brandId,
        public int $brandModelVersionId,
        public mixed $pdfAssetId = null,
        public ?string $websiteUrl = null,
        public array $materialAssetIds = []
    ) {}

    public function handle(
        BrandWebsiteCrawlerService $crawlerService,
        BrandCoherenceScoringService $coherenceService,
        BrandAlignmentEngine $alignmentEngine,
        BrandGuidelinesProcessor $pdfProcessor,
        WebsiteExtractionProcessor $websiteProcessor,
        BrandMaterialProcessor $materialProcessor,
        ExtractionSuggestionService $suggestionService
    ): void {
        $brand = Brand::find($this->brandId);
        if (! $brand) {
            return;
        }

        $draft = BrandModelVersion::find($this->brandModelVersionId);
        if (! $draft || ($draft->brandModel?->brand_id ?? null) !== $brand->id) {
            return;
        }

        $record = BrandIngestionRecord::create([
            'brand_id' => $brand->id,
            'brand_model_version_id' => $draft->id,
            'status' => BrandIngestionRecord::STATUS_PROCESSING,
        ]);

        try {
            $extractions = [];

            if ($this->pdfAssetId) {
                $pdfExt = $this->processPdf($pdfProcessor);
                if ($pdfExt) {
                    $extractions[] = $pdfExt;
                }
            }

            if ($this->websiteUrl) {
                $crawlData = $crawlerService->crawl($this->websiteUrl);
                $extractions[] = $websiteProcessor->process($crawlData);
            }

            if (! empty($this->materialAssetIds)) {
                $assets = Asset::whereIn('id', $this->materialAssetIds)
                    ->where('brand_id', $brand->id)
                    ->get();
                if ($assets->isNotEmpty()) {
                    $extractions[] = $materialProcessor->process($assets);
                }
            }

            $extraction = empty($extractions)
                ? BrandExtractionSchema::empty()
                : BrandExtractionSchema::merge(...$extractions);

            $conflicts = $extraction['conflicts'] ?? [];

            $record->update(['extraction_json' => $extraction]);

            $suggestions = $suggestionService->generateSuggestions($extraction, $conflicts);

            $snapshotPayload = $this->extractionToSnapshotPayload($extraction);
            $snapshotPayload['conflicts'] = $conflicts;

            $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
            $coherence = $coherenceService->score(
                $draft->model_payload ?? [],
                $suggestions,
                $snapshotPayload,
                $brand,
                $brandMaterialCount,
                $conflicts
            );
            $alignment = $alignmentEngine->analyze($draft->model_payload ?? []);

            $snapshot = BrandResearchSnapshot::create([
                'brand_id' => $brand->id,
                'brand_model_version_id' => $draft->id,
                'source_url' => $this->websiteUrl ?? 'ingestion',
                'status' => 'completed',
                'snapshot' => $snapshotPayload,
                'suggestions' => $suggestions,
                'coherence' => $coherence,
                'alignment' => $alignment,
            ]);

            $draft->getOrCreateInsightState($snapshot->id);

            $record->update(['status' => BrandIngestionRecord::STATUS_COMPLETED]);

            $extractedSignalCount = $this->countExtractionSignals($extraction);
            Log::info('Brand ingestion summary', [
                'draft_id' => $draft->id,
                'pdf_asset_id' => $this->pdfAssetId,
                'extracted_signal_count' => $extractedSignalCount,
                'suggestion_count' => count($suggestions),
                'snapshot_created' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('[RunBrandIngestionJob] Ingestion failed', [
                'draft_id' => $this->brandModelVersionId,
                'pdf_asset_id' => $this->pdfAssetId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $record->update([
                'status' => BrandIngestionRecord::STATUS_FAILED,
                'extraction_json' => array_merge($record->extraction_json ?? [], ['error' => $e->getMessage()]),
                'failure_reason' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function processPdf(BrandGuidelinesProcessor $processor): ?array
    {
        $asset = Asset::find($this->pdfAssetId);
        if (! $asset) {
            return null;
        }

        $visionExtraction = BrandPdfVisionExtraction::where('asset_id', $asset->id)
            ->where('brand_id', $this->brandId)
            ->where('brand_model_version_id', $this->brandModelVersionId)
            ->where('status', BrandPdfVisionExtraction::STATUS_COMPLETED)
            ->latest()
            ->first();

        if ($visionExtraction && ! empty($visionExtraction->extraction_json)) {
            return $visionExtraction->extraction_json;
        }

        $extraction = $asset->getLatestPdfTextExtractionForVersion($asset->currentVersion?->id);
        if (! $extraction || ! $extraction->isComplete()) {
            return null;
        }

        $text = trim($extraction->extracted_text ?? '');
        if ($text === '') {
            return null;
        }

        return $processor->process($text);
    }

    protected function countExtractionSignals(array $extraction): int
    {
        $count = 0;
        foreach (['identity', 'personality', 'visual'] as $section) {
            $data = $extraction[$section] ?? [];
            if (! is_array($data)) {
                continue;
            }
            foreach ($data as $v) {
                if ($v !== null && $v !== '' && $v !== []) {
                    $count++;
                }
            }
        }
        return $count;
    }

    protected function extractionToSnapshotPayload(array $extraction): array
    {
        $visual = $extraction['visual'] ?? [];
        $colors = $visual['primary_colors'] ?? [];

        return [
            'logo_url' => $visual['logo_detected'] ?? null,
            'primary_colors' => array_map(fn ($c) => is_string($c) ? $c : ($c['hex'] ?? $c), $colors),
            'detected_fonts' => $visual['fonts'] ?? [],
            'hero_headlines' => $extraction['sources']['website']['hero_headlines'] ?? [],
            'brand_bio' => $extraction['identity']['positioning'] ?? $extraction['sources']['website']['brand_bio'] ?? null,
        ];
    }
}
