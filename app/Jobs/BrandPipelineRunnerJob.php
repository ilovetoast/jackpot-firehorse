<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandPipelineRun;
use App\Services\BrandDNA\BrandSnapshotService;
use App\Services\BrandDNA\BrandWebsiteCrawlerService;
use App\Services\BrandDNA\ClaudePdfExtractionService;
use App\Services\BrandDNA\Extraction\SectionAwareBrandGuidelinesProcessor;
use App\Services\BrandDNA\Extraction\WebsiteExtractionProcessor;
use App\Services\BrandDNA\Extraction\BrandMaterialProcessor;
use App\Services\BrandDNA\BrandResearchNotificationService;
use App\Services\BrandDNA\BrandVersionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * State machine for Brand Guidelines PDF pipeline.
 *
 * Text path (single-page / text-heavy): init -> completed (section-aware processor).
 * Vision path (multi-page / design-heavy): init -> analyzing -> completed (single Claude call).
 */
class BrandPipelineRunnerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public int $runId
    ) {
        $this->onQueue(config('queue.pdf_processing_queue', 'pdf-processing'));
    }

    public function handle(
        BrandSnapshotService $snapshotService,
        ClaudePdfExtractionService $claudeExtractionService
    ): void {
        $run = BrandPipelineRun::with(['brand', 'brandModelVersion', 'asset'])->find($this->runId);
        if (! $run) {
            return;
        }

        $brand = $run->brand;
        $draft = $run->brandModelVersion;
        $asset = $run->asset;

        if (! $brand || ! $draft) {
            $run->update([
                'status' => BrandPipelineRun::STATUS_FAILED,
                'stage' => BrandPipelineRun::STAGE_FAILED,
                'error_message' => 'Brand or draft not found',
            ]);
            return;
        }

        Log::channel('pipeline')->info('[BrandPipelineRunnerJob] Run started', [
            'run_id' => $run->id,
            'extraction_mode' => $run->extraction_mode,
        ]);

        try {
            if ($run->extraction_mode === BrandPipelineRun::EXTRACTION_MODE_TEXT) {
                $this->advanceTextPath($run, $brand, $draft, $asset, $snapshotService);
            } else {
                $this->advanceClaudePath($run, $brand, $draft, $asset, $claudeExtractionService);
            }
        } catch (\Throwable $e) {
            Log::error('[BrandPipelineRunnerJob] Failed', [
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            $run->update([
                'status' => BrandPipelineRun::STATUS_FAILED,
                'stage' => BrandPipelineRun::STAGE_FAILED,
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function advanceTextPath(
        BrandPipelineRun $run,
        Brand $brand,
        BrandModelVersion $draft,
        ?Asset $asset,
        BrandSnapshotService $snapshotService
    ): void {
        if ($run->stage !== BrandPipelineRun::STAGE_INIT) {
            return;
        }

        $extractions = [];

        if ($asset) {
            $extraction = $asset->getLatestPdfTextExtractionForVersion($asset->currentVersion?->id);
            if (! $extraction || ! $extraction->isComplete()) {
                self::dispatch($run->id)->delay(now()->addSeconds(5));
                return;
            }
            $text = trim($extraction->extracted_text ?? '');
            if ($text === '') {
                $run->update([
                    'status' => BrandPipelineRun::STATUS_FAILED,
                    'stage' => BrandPipelineRun::STAGE_FAILED,
                    'error_message' => 'No text in extraction',
                ]);
                return;
            }
            $processor = app(SectionAwareBrandGuidelinesProcessor::class);
            $extractions[] = $processor->process($text);
        }

        $crawlData = $this->runWebsiteCrawl($draft, $brand);
        if ($crawlData) {
            $extractions[] = app(WebsiteExtractionProcessor::class)->process($crawlData);
        }

        $materialIds = $draft->assetsForContext('brand_material')->get()->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        if (! empty($materialIds)) {
            $assets = Asset::whereIn('id', $materialIds)->where('brand_id', $brand->id)->get();
            if ($assets->isNotEmpty()) {
                $extractions[] = app(BrandMaterialProcessor::class)->process($assets);
            }
        }

        if (empty($extractions)) {
            $run->update([
                'status' => BrandPipelineRun::STATUS_FAILED,
                'stage' => BrandPipelineRun::STAGE_FAILED,
                'error_message' => 'No sources to process',
            ]);
            return;
        }

        $websiteUrl = $draft->model_payload['sources']['website_url'] ?? null;
        $activeSources = $this->deriveActiveSources($run->asset_id, $websiteUrl, $materialIds ?? []);
        $snapshot = $snapshotService->createFromExtractions(
            $brand,
            $draft,
            $extractions,
            $activeSources,
            $run,
            $websiteUrl ?? 'ingestion'
        );

        $draft->getOrCreateInsightState($snapshot->id);
        app(BrandResearchNotificationService::class)->maybeNotifyResearchReady($brand, $draft);
        app(BrandVersionService::class)->markResearchComplete($draft);

        $run->update([
            'stage' => BrandPipelineRun::STAGE_COMPLETED,
            'status' => BrandPipelineRun::STATUS_COMPLETED,
            'pages_total' => 1,
            'pages_processed' => 1,
        ]);
    }

    /**
     * Single-pass Claude PDF extraction: one API call, no page rendering.
     */
    protected function advanceClaudePath(
        BrandPipelineRun $run,
        Brand $brand,
        BrandModelVersion $draft,
        ?Asset $asset,
        ClaudePdfExtractionService $claudeService
    ): void {
        if ($run->stage !== BrandPipelineRun::STAGE_INIT) {
            return;
        }

        if (! $asset) {
            $run->update([
                'status' => BrandPipelineRun::STATUS_FAILED,
                'stage' => BrandPipelineRun::STAGE_FAILED,
                'error_message' => 'Asset not found for Claude extraction',
            ]);
            return;
        }

        $run->update([
            'stage' => BrandPipelineRun::STAGE_ANALYZING,
            'status' => BrandPipelineRun::STATUS_PROCESSING,
            'pages_total' => 1,
            'pages_processed' => 0,
        ]);

        Log::channel('pipeline')->info('[BrandPipelineRunnerJob] Claude single-pass extraction starting', [
            'run_id' => $run->id,
            'asset_id' => $asset->id,
        ]);

        $result = $claudeService->extract($asset);

        $run->update([
            'merged_extraction_json' => $result['extraction'],
            'raw_api_response_json' => $result['raw_response'],
            'pages_processed' => 1,
        ]);

        // Run website crawl alongside Claude extraction (merge happens in snapshot job)
        $crawlData = $this->runWebsiteCrawl($run->brandModelVersion, $brand);
        if ($crawlData) {
            $websiteExtraction = app(WebsiteExtractionProcessor::class)->process($crawlData);
            $existing = $run->merged_extraction_json ?? [];
            if (! empty($existing)) {
                $merged = \App\Services\BrandDNA\Extraction\BrandExtractionSchema::merge($existing, $websiteExtraction);
                $run->update(['merged_extraction_json' => $merged]);
            }
        }

        Log::channel('pipeline')->info('[BrandPipelineRunnerJob] Claude extraction stored, dispatching snapshot', [
            'run_id' => $run->id,
        ]);

        BrandPipelineSnapshotJob::dispatch($run->id);
    }

    /**
     * Run website crawl if a URL is set on the draft. Downloads logo as asset if found.
     */
    protected function runWebsiteCrawl(BrandModelVersion $draft, Brand $brand): ?array
    {
        $websiteUrl = $draft->model_payload['sources']['website_url'] ?? null;
        if (empty(trim((string) $websiteUrl))) {
            return null;
        }

        $crawler = app(BrandWebsiteCrawlerService::class);
        $crawlData = $crawler->crawl($websiteUrl);

        if (! empty($crawlData['logo_svg']) || ! empty($crawlData['logo_url'])) {
            $crawler->downloadLogoAsAsset($crawlData, $brand, $draft);
        }

        return $crawlData;
    }

    protected function deriveActiveSources(?string $pdfAssetId, ?string $websiteUrl, array $materialIds): array
    {
        $sources = [];
        if ($pdfAssetId) {
            $sources[] = 'pdf';
        }
        if (! empty(trim((string) $websiteUrl))) {
            $sources[] = 'website';
        }
        if (! empty($materialIds)) {
            $sources[] = 'materials';
        }

        return $sources;
    }

    public function failed(?\Throwable $exception): void
    {
        $run = BrandPipelineRun::find($this->runId);
        if (! $run || in_array($run->status, [BrandPipelineRun::STATUS_COMPLETED, BrandPipelineRun::STATUS_FAILED])) {
            return;
        }

        $run->update([
            'stage' => BrandPipelineRun::STAGE_FAILED,
            'status' => BrandPipelineRun::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Pipeline processing failed',
        ]);

        Log::channel('pipeline')->error('[BrandPipelineRunnerJob] Job failed permanently', [
            'run_id' => $this->runId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
