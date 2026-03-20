<?php

namespace App\Http\Controllers;

use App\Jobs\BrandPipelineRunnerJob;
use App\Jobs\ExtractPdfTextJob;
use App\Jobs\RunBrandResearchJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
use App\Models\PdfTextExtraction;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\BrandDNA\PipelineFinalizationService;
use App\Services\BrandDNA\ResearchProgressService;
use App\Services\BrandDNA\SuggestionViewTransformer;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\WebsiteUrlNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Research — dedicated page for research inputs, pipeline status, and results.
 */
class BrandResearchController extends Controller
{
    public function __construct(
        private BrandVersionService $versionService,
        private PipelineFinalizationService $finalizationService,
        private ResearchProgressService $progressService
    ) {}

    /**
     * GET /brands/{brand}/research
     */
    public function show(Request $request, Brand $brand): Response|RedirectResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $planName = app(\App\Services\PlanService::class)->getCurrentPlan($tenant);
        if ($planName === 'free') {
            return redirect()->route('brands.edit', ['brand' => $brand->id, 'tab' => 'strategy'])
                ->with('warning', 'Brand Research requires a paid plan. You can manually configure your Brand DNA below.');
        }

        $version = $this->versionService->getWorkingVersion($brand);

        $guidelinesPdfAsset = $version->assetsForContext('guidelines_pdf')->first();
        $brandMaterials = $version->assetsForContext('brand_material')->get()->map(fn (Asset $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'original_filename' => $a->original_filename,
            'thumbnail_url' => $a->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED) ?: null,
        ])->values()->all();

        $sources = $version->model_payload['sources'] ?? [];
        $hasWebsiteUrl = ! empty(trim((string) ($sources['website_url'] ?? '')));
        $hasSocialUrls = ! empty(array_filter($sources['social_urls'] ?? [], fn ($u) => is_string($u) && trim($u) !== ''));
        $brandMaterialCount = count($brandMaterials);

        $runs = BrandPipelineRun::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $version->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $snapshots = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $version->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $latestCompletedSnapshot = $snapshots->firstWhere('status', 'completed');

        $finalization = $this->finalizationService->compute(
            $brand->id,
            $version->id,
            $guidelinesPdfAsset,
            $hasWebsiteUrl,
            $hasSocialUrls,
            $brandMaterialCount
        );

        $latestRun = $runs->first();
        $snapshotData = $latestCompletedSnapshot?->snapshot ?? [];
        $suggestionsData = $latestCompletedSnapshot?->suggestions ?? [];

        $pipelineHealth = $this->detectPipelineHealth($latestRun, $snapshots);

        return Inertia::render('Brand/Research', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'lifecycle_stage' => $version->lifecycle_stage,
                'research_status' => $version->research_status,
                'research_started_at' => $version->research_started_at?->toIso8601String(),
            ],
            'inputs' => [
                'pdf' => $guidelinesPdfAsset ? [
                    'id' => $guidelinesPdfAsset->id,
                    'filename' => $guidelinesPdfAsset->original_filename,
                    'size_bytes' => $guidelinesPdfAsset->size_bytes,
                    'thumbnail_url' => $guidelinesPdfAsset->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED) ?: null,
                ] : null,
                'website_url' => $sources['website_url'] ?? '',
                'social_urls' => $sources['social_urls'] ?? [],
                'materials' => $brandMaterials,
            ],
            'runs' => $runs->map(fn (BrandPipelineRun $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'stage' => $r->stage,
                'extraction_mode' => $r->extraction_mode,
                'error_message' => $r->error_message,
                'pages_total' => $r->pages_total,
                'pages_processed' => $r->pages_processed,
                'created_at' => $r->created_at?->toIso8601String(),
                'updated_at' => $r->updated_at?->toIso8601String(),
                'completed_at' => $r->completed_at?->toIso8601String(),
            ])->values()->all(),
            'snapshots' => $snapshots->map(fn (BrandPipelineSnapshot $s) => [
                'id' => $s->id,
                'status' => $s->status,
                'source_url' => $s->source_url,
                'created_at' => $s->created_at?->toIso8601String(),
                'has_suggestions' => ! empty($s->suggestions),
                'has_coherence' => ! empty($s->coherence),
            ])->values()->all(),
            'status' => [
                'pdf_complete' => ($finalization['pipeline_status']['text_extraction_complete'] ?? true),
                'snapshot_ready' => ($finalization['pipeline_status']['snapshot_persisted'] ?? false),
                'suggestions_ready' => ($finalization['pipeline_status']['suggestions_ready'] ?? false),
                'research_finalized' => $finalization['research_finalized'],
            ],
            'pipelineHealth' => $pipelineHealth,
            'results' => [
                'snapshot' => $snapshotData,
                'suggestions' => SuggestionViewTransformer::forFrontend($suggestionsData, $snapshotData),
                'coherence' => $latestCompletedSnapshot?->coherence,
                'alignment' => $latestCompletedSnapshot?->alignment,
            ],
            'modelPayload' => $version->model_payload ?? [],
            'brandResearchGate' => $this->getBrandResearchGate($tenant),
        ]);
    }

    /**
     * POST /brands/{brand}/research/analyze
     * Triggers both ingestion (PDF/materials) and research (URL crawl).
     */
    public function triggerAnalysis(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $aiUsageService = app(\App\Services\AiUsageService::class);
        if (! $aiUsageService->canUseFeature($tenant, 'brand_research')) {
            $status = $aiUsageService->getUsageStatus($tenant)['brand_research'] ?? [];
            $planName = app(\App\Services\PlanService::class)->getCurrentPlan($tenant);
            $gate = ($status['is_disabled'] ?? false) || ($status['cap'] ?? 0) === 0 ? 'plan_required' : 'usage_exceeded';

            return response()->json([
                'error' => $gate === 'plan_required'
                    ? 'AI brand research requires a paid plan.'
                    : "Monthly brand research limit reached ({$status['usage']}/{$status['cap']}).",
                'gate' => $gate,
            ], $gate === 'plan_required' ? 403 : 429);
        }

        $version = $this->versionService->getWorkingVersion($brand);

        $request->merge([
            'website_url' => WebsiteUrlNormalizer::normalize($request->input('website_url')),
        ]);
        $socialRaw = $request->input('social_urls');
        if (is_array($socialRaw)) {
            $request->merge([
                'social_urls' => array_values(array_filter(array_map(
                    static function ($u) {
                        if (! is_string($u) || trim($u) === '') {
                            return null;
                        }

                        return WebsiteUrlNormalizer::normalize($u) ?? $u;
                    },
                    $socialRaw
                ))),
            ]);
        }

        $validated = $request->validate([
            'pdf_asset_id' => 'nullable|string|exists:assets,id',
            'website_url' => 'nullable|string|url',
            'social_urls' => 'nullable|array',
            'social_urls.*' => 'string|url',
            'material_asset_ids' => 'nullable|array',
            'material_asset_ids.*' => 'string|exists:assets,id',
        ]);

        $pdfAssetId = $validated['pdf_asset_id'] ?? null;
        $websiteUrl = $validated['website_url'] ?? null;
        $socialUrls = $validated['social_urls'] ?? [];
        $materialIds = $validated['material_asset_ids'] ?? [];

        // Link the PDF to this version so it persists across page reloads
        if ($pdfAssetId) {
            \App\Models\BrandModelVersionAsset::where('brand_model_version_id', $version->id)
                ->where('builder_context', 'guidelines_pdf')
                ->delete();

            \App\Models\BrandModelVersionAsset::create([
                'brand_model_version_id' => $version->id,
                'asset_id' => $pdfAssetId,
                'builder_context' => 'guidelines_pdf',
            ]);
        }

        // Persist URL inputs into the version payload
        $payload = $version->model_payload ?? [];
        $payload['sources'] = [
            'website_url' => $websiteUrl ?? '',
            'social_urls' => $socialUrls,
        ];
        $version->update(['model_payload' => $payload]);

        if (empty($materialIds)) {
            $materialIds = $version->assetsForContext('brand_material')->pluck('assets.id')->map(fn ($id) => (string) $id)->values()->all();
        }

        $triggered = [];

        // PDF/materials ingestion pipeline
        if ($pdfAssetId || ! empty($materialIds)) {
            $extractionMode = BrandPipelineRun::EXTRACTION_MODE_TEXT;
            if ($pdfAssetId) {
                $pdfAsset = Asset::find($pdfAssetId);
                if ($pdfAsset && str_contains(strtolower($pdfAsset->mime_type ?? ''), 'pdf')) {
                    $extractionMode = BrandPipelineRun::resolveExtractionMode($pdfAsset);
                }
            }

            $pdfAssetForSize = $pdfAssetId ? Asset::find($pdfAssetId) : null;
            $run = BrandPipelineRun::create([
                'brand_id' => $brand->id,
                'brand_model_version_id' => $version->id,
                'asset_id' => $pdfAssetId,
                'source_size_bytes' => BrandPipelineRun::sourceSizeBytesFromAsset($pdfAssetForSize),
                'stage' => BrandPipelineRun::STAGE_INIT,
                'extraction_mode' => $extractionMode,
                'status' => BrandPipelineRun::STATUS_PENDING,
            ]);
            BrandPipelineRunnerJob::dispatch($run->id);
            $triggered[] = 'ingestion';
        }

        // URL crawl research
        $urlsToCrawl = array_filter(
            array_merge($websiteUrl ? [$websiteUrl] : [], $socialUrls),
            fn ($u) => is_string($u) && trim($u) !== ''
        );
        foreach ($urlsToCrawl as $url) {
            RunBrandResearchJob::dispatch($brand->id, $version->id, $url);
            $triggered[] = 'crawl';
        }

        if (empty($triggered)) {
            return response()->json(['error' => 'No sources to analyze.'], 422);
        }

        $this->versionService->markResearchRunning($version);

        try {
            $aiUsageService->trackUsage($tenant, 'brand_research');
        } catch (\Throwable $e) {
            // Non-critical
        }

        return response()->json(['triggered' => $triggered]);
    }

    /**
     * POST /brands/{brand}/research/rerun
     * Creates a new pipeline run without overwriting the version payload.
     */
    public function rerun(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $version = $this->versionService->getWorkingVersion($brand);

        $guidelinesPdfAsset = $version->assetsForContext('guidelines_pdf')->first();
        $sources = $version->model_payload['sources'] ?? [];
        $websiteRaw = trim((string) ($sources['website_url'] ?? ''));
        $websiteUrl = $websiteRaw === '' ? '' : (WebsiteUrlNormalizer::normalize($websiteRaw) ?? '');
        $socialUrls = array_values(array_filter(array_map(
            static function ($u) {
                if (! is_string($u) || trim($u) === '') {
                    return null;
                }

                return WebsiteUrlNormalizer::normalize($u) ?? $u;
            },
            $sources['social_urls'] ?? []
        )));

        if ($websiteRaw !== '' && $websiteUrl !== '' && strcasecmp($websiteRaw, $websiteUrl) !== 0) {
            $pl = $version->model_payload ?? [];
            $pl['sources'] = array_merge($pl['sources'] ?? [], ['website_url' => $websiteUrl]);
            $version->update(['model_payload' => $pl]);
            $version->refresh();
        }

        $triggered = [];

        if ($guidelinesPdfAsset) {
            $extractionMode = BrandPipelineRun::resolveExtractionMode($guidelinesPdfAsset);
            $run = BrandPipelineRun::create([
                'brand_id' => $brand->id,
                'brand_model_version_id' => $version->id,
                'asset_id' => $guidelinesPdfAsset->id,
                'source_size_bytes' => BrandPipelineRun::sourceSizeBytesFromAsset($guidelinesPdfAsset),
                'stage' => BrandPipelineRun::STAGE_INIT,
                'extraction_mode' => $extractionMode,
                'status' => BrandPipelineRun::STATUS_PENDING,
            ]);
            BrandPipelineRunnerJob::dispatch($run->id);
            $triggered[] = 'ingestion';
        }

        $urlsToCrawl = array_filter(
            array_merge($websiteUrl ? [$websiteUrl] : [], $socialUrls),
            fn ($u) => trim($u) !== ''
        );
        foreach ($urlsToCrawl as $url) {
            RunBrandResearchJob::dispatch($brand->id, $version->id, $url);
            $triggered[] = 'crawl';
        }

        if (empty($triggered)) {
            return response()->json(['error' => 'No sources configured. Add a PDF or URL first.'], 422);
        }

        $this->versionService->markResearchRunning($version);

        return response()->json(['triggered' => $triggered, 'rerun' => true]);
    }

    /**
     * POST /brands/{brand}/research/advance-to-review
     * User explicitly advances from research to review stage.
     */
    public function advanceToReview(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $version = $this->versionService->getWorkingVersion($brand);

        if (! $version->isResearchComplete()) {
            $guidelinesPdfAsset = $version->assetsForContext('guidelines_pdf')->first();
            $sources = $version->model_payload['sources'] ?? [];
            $finalization = $this->finalizationService->compute(
                $brand->id,
                $version->id,
                $guidelinesPdfAsset,
                ! empty(trim((string) ($sources['website_url'] ?? ''))),
                ! empty($sources['social_urls'] ?? []),
                $version->assetsForContext('brand_material')->count()
            );

            if (! ($finalization['research_finalized'] ?? false)) {
                return response()->json(['error' => 'Research is not yet complete.'], 422);
            }

            $this->versionService->markResearchComplete($version);
        }

        $this->versionService->advanceToReview($version);

        return response()->json([
            'advanced' => true,
            'lifecycle_stage' => $version->fresh()->lifecycle_stage,
        ]);
    }

    /**
     * Detect stuck/failed pipeline runs and compute elapsed timing.
     *
     * @return array{state: string, error: string|null, can_retry: bool, started_at: string|null, elapsed_seconds: int|null, last_activity_at: string|null}
     */
    protected function detectPipelineHealth(?BrandPipelineRun $latestRun, $snapshots): array
    {
        if (! $latestRun) {
            return ['state' => 'idle', 'error' => null, 'can_retry' => false, 'started_at' => null, 'elapsed_seconds' => null, 'last_activity_at' => null];
        }

        $startedAt = $latestRun->created_at;
        $lastActivity = $latestRun->updated_at ?? $latestRun->created_at;

        if ($latestRun->status === BrandPipelineRun::STATUS_COMPLETED) {
            $runningSnapshot = $snapshots->first(fn ($s) => in_array($s->status, ['pending', 'running']));
            if ($runningSnapshot) {
                $snapshotActivity = $runningSnapshot->updated_at ?? $runningSnapshot->created_at;
                $stuckThreshold = now()->subMinutes(5);
                if ($snapshotActivity && $snapshotActivity->lt($stuckThreshold)) {
                    $minutesAgo = (int) $snapshotActivity->diffInMinutes(now());

                    return [
                        'state' => 'stuck',
                        'error' => "Snapshot generation appears stuck (no progress for {$minutesAgo} minutes).",
                        'can_retry' => true,
                        'started_at' => $startedAt?->toIso8601String(),
                        'elapsed_seconds' => $startedAt ? (int) $startedAt->diffInSeconds(now()) : null,
                        'last_activity_at' => $snapshotActivity->toIso8601String(),
                    ];
                }

                return [
                    'state' => 'processing',
                    'error' => null,
                    'can_retry' => false,
                    'started_at' => $startedAt?->toIso8601String(),
                    'elapsed_seconds' => $startedAt ? (int) $startedAt->diffInSeconds(now()) : null,
                    'last_activity_at' => $snapshotActivity?->toIso8601String(),
                ];
            }

            return [
                'state' => 'completed',
                'error' => null,
                'can_retry' => false,
                'started_at' => $startedAt?->toIso8601String(),
                'elapsed_seconds' => $latestRun->completed_at && $startedAt
                    ? (int) $startedAt->diffInSeconds($latestRun->completed_at)
                    : null,
                'last_activity_at' => ($latestRun->completed_at ?? $lastActivity)?->toIso8601String(),
            ];
        }

        if ($latestRun->status === BrandPipelineRun::STATUS_FAILED) {
            $message = app()->environment('production')
                ? 'Processing failed. You can retry to re-analyze your brand materials.'
                : ($latestRun->error_message ?: 'Processing failed with an unknown error.');

            return [
                'state' => 'failed',
                'error' => $message,
                'can_retry' => true,
                'started_at' => $startedAt?->toIso8601String(),
                'elapsed_seconds' => $startedAt ? (int) $startedAt->diffInSeconds($lastActivity) : null,
                'last_activity_at' => $lastActivity?->toIso8601String(),
            ];
        }

        if ($latestRun->status === BrandPipelineRun::STATUS_PROCESSING) {
            $stuckThreshold = now()->subMinutes(5);
            if ($lastActivity && $lastActivity->lt($stuckThreshold)) {
                $minutesAgo = (int) $lastActivity->diffInMinutes(now());

                return [
                    'state' => 'stuck',
                    'error' => "Processing appears stuck (no progress for {$minutesAgo} minutes).",
                    'can_retry' => true,
                    'started_at' => $startedAt?->toIso8601String(),
                    'elapsed_seconds' => $startedAt ? (int) $startedAt->diffInSeconds(now()) : null,
                    'last_activity_at' => $lastActivity->toIso8601String(),
                ];
            }

            return [
                'state' => 'processing',
                'error' => null,
                'can_retry' => false,
                'started_at' => $startedAt?->toIso8601String(),
                'elapsed_seconds' => $startedAt ? (int) $startedAt->diffInSeconds(now()) : null,
                'last_activity_at' => $lastActivity?->toIso8601String(),
            ];
        }

        return [
            'state' => 'idle',
            'error' => null,
            'can_retry' => false,
            'started_at' => $startedAt?->toIso8601String(),
            'elapsed_seconds' => null,
            'last_activity_at' => $lastActivity?->toIso8601String(),
        ];
    }

    protected function ensureTextExtractionStarted(Asset $asset): void
    {
        $asset->loadMissing('currentVersion');
        $versionId = $asset->currentVersion?->id;
        $existing = $asset->getLatestPdfTextExtractionForVersion($versionId);

        if ($existing && in_array($existing->status, [PdfTextExtraction::STATUS_PENDING, 'processing', 'complete'])) {
            return;
        }

        $extraction = $asset->pdfTextExtractions()->create([
            'asset_version_id' => $versionId,
            'extraction_source' => null,
            'status' => PdfTextExtraction::STATUS_PENDING,
        ]);

        ExtractPdfTextJob::dispatch($asset->id, $extraction->id, $versionId);
    }

    protected function getBrandResearchGate($tenant): array
    {
        $aiUsageService = app(\App\Services\AiUsageService::class);
        $planName = app(\App\Services\PlanService::class)->getCurrentPlan($tenant);
        $status = $aiUsageService->getUsageStatus($tenant)['brand_research'] ?? [];
        $canUse = $aiUsageService->canUseFeature($tenant, 'brand_research');

        return [
            'allowed' => $canUse,
            'plan' => $planName,
            'usage' => $status['usage'] ?? 0,
            'cap' => $status['cap'] ?? 0,
            'remaining' => $status['remaining'] ?? 0,
            'is_disabled' => ($status['cap'] ?? 0) === 0,
            'is_exceeded' => $status['is_exceeded'] ?? false,
        ];
    }
}
