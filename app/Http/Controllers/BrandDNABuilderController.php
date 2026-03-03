<?php

namespace App\Http\Controllers;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Jobs\RunBrandIngestionJob;
use App\Jobs\RunBrandResearchJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandIngestionRecord;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandResearchSnapshot;
use App\Models\BrandPdfVisionExtraction;
use App\Models\PdfTextExtraction;
use App\Services\BrandDNA\BrandAlignmentEngine;
use App\Services\BrandDNA\BrandCoherenceScoringService;
use App\Services\BrandDNA\BrandDnaDraftService;
use App\Services\BrandDNA\CoherenceDeltaService;
use App\Services\BrandDNA\SuggestionApplier;
use App\Services\BrandDNA\BrandGuidelinesPublishValidator;
use App\Services\BrandDNA\BrandModelService;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Guidelines Builder v1 — backend API + wizard.
 */
class BrandDNABuilderController extends Controller
{
    public function __construct(
        private BrandDnaDraftService $draftService,
        private BrandModelService $brandModelService,
        private BrandGuidelinesPublishValidator $publishValidator,
        private BrandCoherenceScoringService $coherenceService,
        private BrandAlignmentEngine $alignmentEngine,
        private SuggestionApplier $suggestionApplier,
        private CoherenceDeltaService $coherenceDeltaService,
        private \App\Services\BrandDNA\PublishedVersionGuard $publishedGuard
    ) {}

    /**
     * GET /brands/{brand}/brand-guidelines/builder
     * Wizard UI shell.
     */
    public function show(Request $request, Brand $brand): Response
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $stepKeys = BrandGuidelinesBuilderSteps::stepKeys();
        $steps = BrandGuidelinesBuilderSteps::steps();
        $currentStep = $request->query('step', BrandGuidelinesBuilderSteps::STEP_BACKGROUND);
        if ($currentStep === 'purpose') {
            $currentStep = BrandGuidelinesBuilderSteps::STEP_PURPOSE_PROMISE;
        }
        if (! BrandGuidelinesBuilderSteps::isValidStepKey($currentStep)) {
            $currentStep = BrandGuidelinesBuilderSteps::STEP_BACKGROUND;
        }
        $anchor = $request->query('anchor');

        $crawlerRunning = false;
        $ingestionProcessing = false;
        $ingestionRecords = collect();
        $latestSnapshot = [];
        $latestSuggestions = [];
        $latestSnapshotLite = null;
        $latestCoherence = null;
        $latestAlignment = null;
        $insightState = ['dismissed' => [], 'accepted' => []];
        $brandMaterialCount = 0;
        $brandMaterials = [];
        $visualReferences = [];
        $guidelinesPdfAssetId = null;
        $guidelinesPdfFilename = null;
        $overallStatus = 'pending';
        try {
            try {
                $state = $draft->getOrCreateInsightState();
                $insightState = [
                    'dismissed' => $state->dismissed ?? [],
                    'accepted' => $state->accepted ?? [],
                ];
            } catch (\Throwable $e) {
                // Table may not exist yet
            }
            $runningSnapshot = BrandResearchSnapshot::where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->whereIn('status', ['pending', 'running'])
                ->latest()
                ->first();
            $crawlerRunning = $runningSnapshot !== null;
            $ingestionRecords = BrandIngestionRecord::where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();
            $ingestionProcessing = $ingestionRecords->contains(fn ($r) => $r->status === BrandIngestionRecord::STATUS_PROCESSING);
            $latestResearch = BrandResearchSnapshot::where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->where('status', 'completed')
                ->latest()
                ->first();
            $latestSnapshot = $latestResearch?->snapshot ?? [];
            $latestSuggestions = $latestResearch?->suggestions ?? [];
            $latestSnapshotLite = $latestResearch ? [
                'id' => $latestResearch->id,
                'status' => $latestResearch->status,
                'created_at' => $latestResearch->created_at?->toIso8601String(),
                'source_url' => $latestResearch->source_url,
            ] : null;
            $latestCoherence = $latestResearch?->coherence;
            $latestAlignment = $latestResearch?->alignment;

            $brandMaterialAssets = $draft->assetsForContext('brand_material')->get();
            $brandMaterialCount = $brandMaterialAssets->count();
            $brandMaterials = $brandMaterialAssets->map(fn (Asset $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'original_filename' => $a->original_filename,
                'thumbnail_url' => $a->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED) ?: null,
                'signed_url' => $a->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null,
            ])->values()->all();

            $visualRefAssets = $draft->assetsForContext('visual_reference')->get();
            $visualReferences = $visualRefAssets->map(fn (Asset $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'original_filename' => $a->original_filename,
                'thumbnail_url' => $a->deliveryUrl(AssetVariant::THUMB_SMALL, DeliveryContext::AUTHENTICATED) ?: null,
                'signed_url' => $a->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null,
            ])->values()->all();

            $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
            $guidelinesPdfAssetId = $guidelinesPdfAsset?->id;
            $guidelinesPdfFilename = $guidelinesPdfAsset?->original_filename;

            // Compute per-source completion (AND logic: all enabled sources must be complete)
            $sources = $draft->model_payload['sources'] ?? [];
            $hasWebsiteUrl = ! empty(trim((string) ($sources['website_url'] ?? '')));
            $hasSocialUrls = ! empty($sources['social_urls'] ?? []);
            $hasPdf = $guidelinesPdfAsset !== null;
            $hasMaterials = $brandMaterialCount > 0;

            $pdfComplete = ! $hasPdf;
            if ($hasPdf) {
                $visionBatch = BrandPdfVisionExtraction::where('asset_id', $guidelinesPdfAsset->id)
                    ->where('brand_id', $brand->id)
                    ->where('brand_model_version_id', $draft->id)
                    ->latest()
                    ->first();
                $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
                if ($visionBatch) {
                    $pdfComplete = in_array($visionBatch->status, [BrandPdfVisionExtraction::STATUS_COMPLETED, BrandPdfVisionExtraction::STATUS_FAILED]);
                } elseif ($extraction) {
                    $pdfComplete = ! in_array($extraction->status, ['pending', 'processing']);
                } else {
                    $pdfComplete = false;
                }
            }

            $websiteComplete = ! $hasWebsiteUrl && ! $hasSocialUrls;
            if ($hasWebsiteUrl || $hasSocialUrls) {
                $websiteComplete = $latestResearch !== null && $crawlerRunning === false;
            }

            $materialsComplete = ! $hasMaterials;
            if ($hasMaterials) {
                $latestIngestion = $ingestionRecords->first();
                $materialsComplete = $latestIngestion && $latestIngestion->status !== BrandIngestionRecord::STATUS_PROCESSING;
            }

            $socialComplete = true; // Social is part of website crawl; treat as not_required when no social URLs, else covered by websiteComplete

            $allSourcesComplete = $pdfComplete && $websiteComplete && $materialsComplete && $socialComplete;
            $overallStatus = $allSourcesComplete ? 'completed' : 'processing';
            if ($ingestionRecords->first()?->status === BrandIngestionRecord::STATUS_FAILED) {
                $overallStatus = 'failed';
            }

            // Processing step: if complete, redirect to archetype
            if ($currentStep === BrandGuidelinesBuilderSteps::STEP_PROCESSING && $overallStatus === 'completed') {
                return redirect()->to(route('brands.brand-guidelines.builder', ['brand' => $brand->id, 'step' => BrandGuidelinesBuilderSteps::STEP_ARCHETYPE]));
            }

            // Post-background steps (archetype, etc.): if not complete, redirect to processing (intentional checkpoint)
            $postBackgroundSteps = [BrandGuidelinesBuilderSteps::STEP_ARCHETYPE, BrandGuidelinesBuilderSteps::STEP_PURPOSE_PROMISE, BrandGuidelinesBuilderSteps::STEP_EXPRESSION, BrandGuidelinesBuilderSteps::STEP_POSITIONING, BrandGuidelinesBuilderSteps::STEP_STANDARDS];
            if (in_array($currentStep, $postBackgroundSteps, true) && $overallStatus !== 'completed') {
                return redirect()->to(route('brands.brand-guidelines.builder', ['brand' => $brand->id, 'step' => BrandGuidelinesBuilderSteps::STEP_PROCESSING]));
            }

            // Background step: never redirect — user can edit, upload, and click Next when ready
        } catch (\Throwable $e) {
            // Tables may not exist yet
            $ingestionProcessing = false;
            $ingestionRecords = collect();
            $overallStatus = $overallStatus ?? 'pending';
        }

        return Inertia::render('BrandGuidelines/Builder', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
            'draft' => [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
                'status' => $draft->status,
            ],
            'modelPayload' => $draft->model_payload ?? [],
            'steps' => $steps,
            'stepKeys' => $stepKeys,
            'currentStep' => $currentStep,
            'anchor' => $anchor,
            'crawlerRunning' => $crawlerRunning,
            'ingestionProcessing' => $ingestionProcessing,
            'ingestionRecords' => $ingestionRecords->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'error' => ($r->extraction_json ?? [])['error'] ?? null,
            ])->values()->all(),
            'latestSnapshot' => $latestSnapshot,
            'latestSuggestions' => $latestSuggestions,
            'latestSnapshotLite' => $latestSnapshotLite,
            'latestCoherence' => $latestCoherence,
            'latestAlignment' => $latestAlignment,
            'insightState' => $insightState,
            'brandMaterialCount' => $brandMaterialCount,
            'brandMaterials' => $brandMaterials,
            'visualReferences' => $visualReferences,
            'guidelinesPdfAssetId' => $guidelinesPdfAssetId ?? null,
            'guidelinesPdfFilename' => $guidelinesPdfFilename ?? null,
            'overallStatus' => $overallStatus ?? 'pending',
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/start
     * Creates a NEW draft and redirects to wizard.
     */
    public function start(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->createNewDraftVersion($brand);

        return redirect()->route('brands.brand-guidelines.builder', [
            'brand' => $brand->id,
            'step' => BrandGuidelinesBuilderSteps::STEP_BACKGROUND,
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/patch
     * Body: { step_key: string, payload: object }
     * Merges payload into draft version (creates draft if none). Returns updated draft summary.
     */
    public function patch(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $stepKeys = BrandGuidelinesBuilderSteps::stepKeys();
        $validated = $request->validate([
            'step_key' => ['required', 'string', 'in:' . implode(',', $stepKeys)],
            'payload' => 'required|array',
        ]);

        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        if ($this->publishedGuard->isPublished($draft) && $this->publishedGuard->patchTouchesStructuralField($validated['payload'])) {
            return response()->json([
                'error' => 'Cannot edit structural fields on published version. Create a new version to make changes.',
            ], 403);
        }

        $draft = $this->draftService->patchFromStep(
            $brand,
            $validated['step_key'],
            $validated['payload']
        );

        return response()->json([
            'draft_version' => [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
                'status' => $draft->status,
            ],
            'payload_snippet' => $this->getPayloadSnippetForStep($draft->model_payload ?? [], $validated['step_key']),
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/versions/{version}/publish
     * Body: { enable_scoring: boolean|null }
     * Validates required fields, then activates version.
     */
    public function publish(Request $request, Brand $brand, BrandModelVersion $version): JsonResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $brandModel = $brand->brandModel;
        if (! $brandModel || $version->brand_model_id !== $brandModel->id) {
            abort(404, 'Version not found for this brand.');
        }

        $missing = $this->publishValidator->validate($version, $brand);
        if (! empty($missing)) {
            return response()->json([
                'error' => 'validation_failed',
                'message' => 'Please complete all required fields before publishing.',
                'missing_fields' => $missing,
            ], 422);
        }

        $enableScoring = $request->input('enable_scoring');
        if ($enableScoring !== null) {
            $brandModel->update(['brand_dna_scoring_enabled' => (bool) $enableScoring]);
        }

        $this->brandModelService->activateVersion($version);
        $brandModel->update(['is_enabled' => true]);

        return response()->json([
            'active_version_id' => $version->id,
            'brand_dna_enabled' => true,
            'brand_dna_scoring_enabled' => $brandModel->fresh()->brand_dna_scoring_enabled ?? true,
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/unpublish
     * Disables Brand DNA without deleting versions.
     */
    public function unpublish(Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $brandModel = $brand->brandModel;
        if (! $brandModel) {
            return response()->json(['brand_dna_enabled' => false]);
        }

        $brandModel->update(['is_enabled' => false]);

        return response()->json([
            'brand_dna_enabled' => false,
            'message' => 'Brand DNA is now disabled. Versions are preserved.',
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/attach-asset
     */
    public function attachAsset(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $validated = $request->validate([
            'asset_id' => 'required|string|uuid|exists:assets,id',
            'builder_context' => 'required|string|in:brand_material,visual_reference,typography_reference,logo_reference,guidelines_pdf',
        ]);
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $assetId = $validated['asset_id'];
        $context = $validated['builder_context'];

        // guidelines_pdf: only one per draft — replace any existing
        if ($context === 'guidelines_pdf') {
            BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
                ->where('builder_context', 'guidelines_pdf')
                ->delete();
        }

        $exists = BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
            ->where('asset_id', $assetId)
            ->where('builder_context', $context)
            ->exists();
        if (! $exists) {
            BrandModelVersionAsset::create([
                'brand_model_version_id' => $draft->id,
                'asset_id' => $assetId,
                'builder_context' => $context,
                'reference_type' => $context === 'visual_reference' ? \App\Models\BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE : null,
            ]);
            if ($context === 'visual_reference') {
                $payload = $draft->model_payload ?? [];
                $visual = $payload['visual'] ?? [];
                $refs = $visual['approved_references'] ?? [];
                $refs[] = ['asset_id' => $assetId, 'kind' => 'photo_reference'];
                $payload['visual'] = array_merge($visual, ['approved_references' => $refs]);
                $draft->update(['model_payload' => $payload]);
            }
        }
        $count = $draft->assetsForContext($context)->count();
        return response()->json(['attached' => true, 'count' => $count]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/detach-asset
     */
    public function detachAsset(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $validated = $request->validate([
            'asset_id' => 'required|string|uuid|exists:assets,id',
            'builder_context' => 'required|string|in:brand_material,visual_reference,typography_reference,logo_reference,guidelines_pdf',
        ]);
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $context = $validated['builder_context'];
        $assetId = $validated['asset_id'];
        BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
            ->where('asset_id', $assetId)
            ->where('builder_context', $context)
            ->delete();
        if ($context === 'visual_reference') {
            $payload = $draft->model_payload ?? [];
            $visual = $payload['visual'] ?? [];
            $refs = array_values(array_filter(
                $visual['approved_references'] ?? [],
                fn ($r) => ((is_array($r) && isset($r['asset_id']) ? $r['asset_id'] : $r) !== $assetId)
            ));
            $payload['visual'] = array_merge($visual, ['approved_references' => $refs]);
            $draft->update(['model_payload' => $payload]);
        }
        $count = $draft->assetsForContext($context)->count();
        return response()->json(['detached' => true, 'count' => $count]);
    }

    /**
     * GET /brands/{brand}/brand-dna/ingestions
     * List ingestion records for processing status and extraction summary.
     */
    public function listIngestions(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $records = BrandIngestionRecord::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        $extractionStatus = null;
        $pdfState = ['status' => 'pending', 'pages_total' => 0, 'pages_processed' => 0, 'signals_detected' => 0, 'early_complete' => false];
        if ($guidelinesPdfAsset) {
            $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
            $extractionStatus = $extraction?->status ?? 'none';
            $visionBatch = BrandPdfVisionExtraction::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->latest()
                ->first();
            if ($visionBatch) {
                $pdfState = [
                    'status' => $visionBatch->status,
                    'pages_total' => $visionBatch->pages_total,
                    'pages_processed' => $visionBatch->pages_processed,
                    'signals_detected' => $visionBatch->signals_detected,
                    'early_complete' => $visionBatch->early_complete,
                ];
            } elseif ($extraction) {
                $pdfState = [
                    'status' => $extraction->status,
                    'pages_total' => 0,
                    'pages_processed' => 0,
                    'signals_detected' => 0,
                    'early_complete' => false,
                ];
            }
        }

        $ingestionProcessing = $records->contains(fn ($r) => $r->status === BrandIngestionRecord::STATUS_PROCESSING);
        $ingestionStatus = $ingestionProcessing ? 'processing' : ($records->first()?->status ?? 'none');

        $latestSnapshot = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();
        $snapshotExists = $latestSnapshot !== null;

        $websiteState = ['status' => 'pending', 'signals_detected' => 0];
        $socialState = ['status' => 'pending'];
        $materialsState = ['status' => 'pending', 'assets_total' => 0, 'assets_processed' => 0];
        $latestRecord = $records->first();
        if ($latestRecord?->processing_state) {
            $websiteState = array_merge($websiteState, $latestRecord->processing_state['website'] ?? []);
            $socialState = array_merge($socialState, $latestRecord->processing_state['social'] ?? []);
            $materialsState = array_merge($materialsState, $latestRecord->processing_state['materials'] ?? []);
        }

        $overallStatus = $ingestionProcessing ? 'processing' : ($snapshotExists ? 'completed' : 'pending');
        if ($latestRecord?->status === BrandIngestionRecord::STATUS_FAILED) {
            $overallStatus = 'failed';
        }
        $suggestionCount = $latestSnapshot ? count($latestSnapshot->suggestions ?? []) : 0;

        // Consider PDF vision processing as part of overall
        if ($pdfState['status'] === 'processing') {
            $overallStatus = 'processing';
        }

        $items = $records->map(function (BrandIngestionRecord $r) {
            $ext = $r->extraction_json ?? [];
            $explicit = $ext['explicit_signals'] ?? [];
            $signalsCount = 0;
            foreach ($ext['identity'] ?? [] as $v) {
                if ($v !== null && $v !== '' && $v !== []) {
                    $signalsCount++;
                }
            }
            foreach ($ext['personality'] ?? [] as $k => $v) {
                if (($k === 'primary_archetype' && $v) || ($k !== 'primary_archetype' && ! empty($v))) {
                    $signalsCount++;
                }
            }
            if (!empty($ext['visual']['primary_colors'] ?? [])) $signalsCount++;
            if (!empty($ext['visual']['fonts'] ?? [])) $signalsCount++;

            return [
                'id' => $r->id,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'explicit_signals' => $explicit,
                'signals_count' => $signalsCount,
                'confidence' => $ext['confidence'] ?? 0,
            ];
        })->values()->all();

        return response()->json([
            'ingestions' => $items,
            'extraction_status' => $extractionStatus,
            'ingestion_status' => $ingestionStatus,
            'snapshot_exists' => $snapshotExists,
            'suggestion_count' => $suggestionCount,
            'pdf' => $pdfState,
            'website' => $websiteState,
            'social' => $socialState,
            'materials' => $materialsState,
            'overall_status' => $overallStatus,
        ]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/research-insights
     */
    public function researchInsights(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $state = $draft->getOrCreateInsightState();
        $runningSnapshot = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();
        $latestCompletedSnapshot = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $ingestionRecords = BrandIngestionRecord::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
        $ingestionProcessing = $ingestionRecords->contains(fn ($r) => $r->status === BrandIngestionRecord::STATUS_PROCESSING);
        $latestIngestion = $ingestionRecords->first();

        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        $visionBatch = null;
        $pdfState = ['status' => 'pending', 'pages_total' => 0, 'pages_processed' => 0, 'signals_detected' => 0, 'early_complete' => false];
        if ($guidelinesPdfAsset) {
            $visionBatch = BrandPdfVisionExtraction::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->latest()
                ->first();
            if ($visionBatch) {
                $currentProcessed = (int) $visionBatch->pages_processed;
                $sessionKey = 'brand_pdf_max_pages_processed_' . $draft->id . '_' . $visionBatch->batch_id;
                $prevMax = (int) $request->session()->get($sessionKey, 0);
                $monotonicProcessed = max($prevMax, $currentProcessed);
                $request->session()->put($sessionKey, $monotonicProcessed);

                $pdfState = [
                    'status' => $visionBatch->status,
                    'pages_total' => $visionBatch->pages_total,
                    'pages_processed' => $monotonicProcessed,
                    'signals_detected' => $visionBatch->signals_detected,
                    'early_complete' => $visionBatch->early_complete,
                ];
            }
        }

        $sources = $draft->model_payload['sources'] ?? [];
        $hasWebsiteUrl = ! empty(trim((string) ($sources['website_url'] ?? '')));
        $hasSocialUrls = ! empty($sources['social_urls'] ?? []);
        $hasPdf = $guidelinesPdfAsset !== null;
        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
        $hasMaterials = $brandMaterialCount > 0;

        $pdfComplete = ! $hasPdf;
        if ($hasPdf && $guidelinesPdfAsset) {
            if ($visionBatch) {
                $pdfComplete = in_array($visionBatch->status, [BrandPdfVisionExtraction::STATUS_COMPLETED, BrandPdfVisionExtraction::STATUS_FAILED]);
            } else {
                $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
                $pdfComplete = $extraction && ! in_array($extraction->status, ['pending', 'processing']);
            }
        }

        $websiteComplete = ! $hasWebsiteUrl && ! $hasSocialUrls;
        if ($hasWebsiteUrl || $hasSocialUrls) {
            $websiteComplete = $latestCompletedSnapshot !== null && $runningSnapshot === null;
        }

        $materialsComplete = ! $hasMaterials;
        if ($hasMaterials) {
            $materialsComplete = $latestIngestion && $latestIngestion->status !== BrandIngestionRecord::STATUS_PROCESSING;
        }

        $allSourcesComplete = $pdfComplete && $websiteComplete && $materialsComplete;
        $overallStatus = $allSourcesComplete ? 'completed' : 'processing';
        if ($latestIngestion?->status === BrandIngestionRecord::STATUS_FAILED) {
            $overallStatus = 'failed';
        }

        $websiteState = ['status' => 'pending', 'signals_detected' => 0];
        $socialState = ['status' => 'pending'];
        $materialsState = ['status' => 'pending', 'assets_total' => 0, 'assets_processed' => 0];
        if ($latestIngestion?->processing_state) {
            $websiteState = array_merge($websiteState, $latestIngestion->processing_state['website'] ?? []);
            $socialState = array_merge($socialState, $latestIngestion->processing_state['social'] ?? []);
            $materialsState = array_merge($materialsState, $latestIngestion->processing_state['materials'] ?? []);
        }
        if ($runningSnapshot !== null) {
            $websiteState['status'] = 'processing';
        }

        return response()->json([
            'crawlerRunning' => $runningSnapshot !== null,
            'runningSnapshotLite' => $runningSnapshot ? [
                'id' => $runningSnapshot->id,
                'status' => $runningSnapshot->status,
                'created_at' => $runningSnapshot->created_at?->toIso8601String(),
                'source_url' => $runningSnapshot->source_url,
            ] : null,
            'latestSnapshotLite' => $latestCompletedSnapshot ? [
                'id' => $latestCompletedSnapshot->id,
                'status' => $latestCompletedSnapshot->status,
                'created_at' => $latestCompletedSnapshot->created_at?->toIso8601String(),
                'source_url' => $latestCompletedSnapshot->source_url,
            ] : null,
            'latestSuggestions' => $latestCompletedSnapshot?->suggestions ?? [],
            'latestCoherence' => $latestCompletedSnapshot?->coherence ?? null,
            'latestAlignment' => $latestCompletedSnapshot?->alignment ?? null,
            'insightState' => [
                'dismissed' => $state->dismissed ?? [],
                'accepted' => $state->accepted ?? [],
            ],
            'ingestionProcessing' => $ingestionProcessing,
            'ingestionRecords' => $ingestionRecords->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'error' => ($r->extraction_json ?? [])['error'] ?? null,
            ])->values()->all(),
            'latestIngestion' => $latestIngestion ? [
                'id' => $latestIngestion->id,
                'status' => $latestIngestion->status,
            ] : null,
            'pdf' => $pdfState,
            'website' => $websiteState,
            'social' => $socialState,
            'materials' => $materialsState,
            'overall_status' => $overallStatus,
        ]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/research-snapshots
     * List snapshots scoped to the current draft version.
     */
    public function listResearchSnapshots(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $snapshots = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->get();

        $items = $snapshots->map(function (BrandResearchSnapshot $s) {
            $coherence = $s->coherence ?? [];
            $overall = $coherence['overall'] ?? [];
            $alignment = $s->alignment ?? [];
            $findings = $alignment['findings'] ?? [];
            $suggestions = $s->suggestions ?? [];

            return [
                'id' => $s->id,
                'created_at' => $s->created_at?->toIso8601String(),
                'source_url' => $s->source_url,
                'status' => $s->status,
                'coherence_overall' => $overall['score'] ?? null,
                'alignment_findings_count' => count($findings),
                'suggestions_count' => count($suggestions),
            ];
        })->values()->all();

        return response()->json(['snapshots' => $items]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/research-snapshots/{snapshot}/compare/{otherSnapshot}
     * Compare two snapshots. Both must belong to same brand and same brand_model_version_id.
     */
    public function compareResearchSnapshots(Request $request, Brand $brand, BrandResearchSnapshot $snapshot, BrandResearchSnapshot $otherSnapshot): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($snapshot->brand_id !== $brand->id || $otherSnapshot->brand_id !== $brand->id) {
            abort(404);
        }
        if ($snapshot->brand_model_version_id !== $otherSnapshot->brand_model_version_id) {
            return response()->json(['error' => 'Snapshots must belong to the same draft version.'], 422);
        }

        $prevCoherence = $snapshot->coherence ?? [];
        $currCoherence = $otherSnapshot->coherence ?? [];
        $coherenceDelta = $this->coherenceDeltaService->calculate($prevCoherence, $currCoherence);

        $prevFindings = $snapshot->alignment['findings'] ?? [];
        $currFindings = $otherSnapshot->alignment['findings'] ?? [];
        $prevFindingIds = array_map(fn ($f) => $f['id'] ?? json_encode($f), $prevFindings);
        $currFindingIds = array_map(fn ($f) => $f['id'] ?? json_encode($f), $currFindings);
        $alignmentResolved = array_values(array_filter($prevFindings, fn ($f) => ! in_array($f['id'] ?? json_encode($f), $currFindingIds)));
        $alignmentNew = array_values(array_filter($currFindings, fn ($f) => ! in_array($f['id'] ?? json_encode($f), $prevFindingIds)));

        $prevSuggestions = $snapshot->suggestions ?? [];
        $currSuggestions = $otherSnapshot->suggestions ?? [];
        $prevSuggestionKeys = array_column($prevSuggestions, 'key');
        $currSuggestionKeys = array_column($currSuggestions, 'key');
        $suggestionRemoved = array_values(array_filter($prevSuggestions, fn ($s) => ! in_array($s['key'] ?? '', $currSuggestionKeys)));
        $suggestionAdded = array_values(array_filter($currSuggestions, fn ($s) => ! in_array($s['key'] ?? '', $prevSuggestionKeys)));

        return response()->json([
            'coherence_delta' => $coherenceDelta,
            'alignment_diff' => [
                'resolved' => $alignmentResolved,
                'new' => $alignmentNew,
            ],
            'suggestion_diff' => [
                'added' => $suggestionAdded,
                'removed' => $suggestionRemoved,
            ],
        ]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/research-snapshots/{snapshot}
     * Snapshot detail: raw snapshot, coherence, alignment, suggestions, insightState decisions.
     */
    public function showResearchSnapshot(Request $request, Brand $brand, BrandResearchSnapshot $snapshot): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($snapshot->brand_id !== $brand->id) {
            abort(404);
        }

        $draft = $snapshot->brandModelVersion;
        $insightState = $draft?->insightState ?? null;
        $dismissed = $insightState?->dismissed ?? [];
        $suggestions = array_values(array_filter(
            $snapshot->suggestions ?? [],
            fn ($s) => ! in_array($s['key'] ?? '', $dismissed)
        ));

        return response()->json([
            'snapshot' => [
                'id' => $snapshot->id,
                'source_url' => $snapshot->source_url,
                'status' => $snapshot->status,
                'created_at' => $snapshot->created_at?->toIso8601String(),
                'snapshot' => $snapshot->snapshot,
                'coherence' => $snapshot->coherence,
                'alignment' => $snapshot->alignment,
                'suggestions' => $suggestions,
            ],
            'insightState' => $insightState ? [
                'dismissed' => $insightState->dismissed ?? [],
                'accepted' => $insightState->accepted ?? [],
            ] : ['dismissed' => [], 'accepted' => []],
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/snapshots/{snapshot}/apply
     * Apply a snapshot suggestion to the draft. Draft changes only when Apply is called.
     */
    public function applySuggestion(Request $request, Brand $brand, BrandResearchSnapshot $snapshot): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($snapshot->brand_id !== $brand->id) {
            abort(404);
        }

        $validated = $request->validate(['key' => 'required|string|max:255']);
        $key = $validated['key'];

        $suggestions = $snapshot->suggestions ?? [];
        $suggestion = collect($suggestions)->firstWhere('key', $key);
        if (! $suggestion) {
            return response()->json(['error' => 'Suggestion not found in snapshot.'], 404);
        }

        $draft = $snapshot->brandModelVersion;
        if (! $draft) {
            return response()->json(['error' => 'Draft not found for snapshot.'], 404);
        }

        $state = $draft->getOrCreateInsightState($snapshot->id);
        $dismissed = $state->dismissed ?? [];
        if (in_array($key, $dismissed)) {
            return response()->json(['error' => 'Suggestion was dismissed and cannot be applied.'], 422);
        }

        if ($this->publishedGuard->isPublished($draft) && $this->publishedGuard->suggestionTouchesStructuralField($suggestion)) {
            return response()->json([
                'error' => 'Cannot edit structural fields on published version. Create a new version to make changes.',
            ], 403);
        }

        $previousCoherence = $snapshot->coherence ?? [];

        try {
            $draft = $this->suggestionApplier->apply($draft, $suggestion);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Cannot override user-defined value.') {
                return response()->json(['error' => 'Cannot override user-defined value.'], 422);
            }
            throw $e;
        }

        $accepted = $state->accepted ?? [];
        if (! in_array($key, $accepted)) {
            $accepted[] = $key;
        }
        $dismissed = array_values(array_filter($dismissed, fn ($k) => $k !== $key));
        $state->update(['accepted' => $accepted, 'dismissed' => $dismissed]);

        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
        $snapshotData = $snapshot->snapshot ?? [];
        $conflicts = $snapshotData['conflicts'] ?? [];
        $currentCoherence = $this->coherenceService->score(
            $draft->model_payload ?? [],
            $suggestions,
            $snapshotData,
            $brand,
            $brandMaterialCount,
            $conflicts
        );
        $coherenceDelta = $this->coherenceDeltaService->calculate($previousCoherence, $currentCoherence);

        return response()->json([
            'draft' => [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
                'status' => $draft->status,
                'model_payload' => $draft->model_payload,
            ],
            'insightState' => [
                'dismissed' => $state->dismissed ?? [],
                'accepted' => $state->accepted ?? [],
            ],
            'coherence_delta' => $coherenceDelta,
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/snapshots/{snapshot}/dismiss
     * Dismiss a snapshot suggestion. Does not modify the draft.
     */
    public function dismissSuggestion(Request $request, Brand $brand, BrandResearchSnapshot $snapshot): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        if ($snapshot->brand_id !== $brand->id) {
            abort(404);
        }

        $validated = $request->validate(['key' => 'required|string|max:255']);
        $key = $validated['key'];

        $suggestions = $snapshot->suggestions ?? [];
        $suggestion = collect($suggestions)->firstWhere('key', $key);
        if (! $suggestion) {
            return response()->json(['error' => 'Suggestion not found in snapshot.'], 404);
        }

        $draft = $snapshot->brandModelVersion;
        if (! $draft) {
            return response()->json(['error' => 'Draft not found for snapshot.'], 404);
        }

        $state = $draft->getOrCreateInsightState($snapshot->id);
        $dismissed = $state->dismissed ?? [];
        if (! in_array($key, $dismissed)) {
            $dismissed[] = $key;
            $state->update(['dismissed' => $dismissed]);
        }

        return response()->json([
            'insightState' => [
                'dismissed' => $state->dismissed ?? [],
                'accepted' => $state->accepted ?? [],
            ],
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/insights/dismiss
     */
    public function dismissInsight(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $validated = $request->validate(['key' => 'required|string|max:255']);
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $state = $draft->getOrCreateInsightState();
        $dismissed = $state->dismissed ?? [];
        if (! in_array($validated['key'], $dismissed)) {
            $dismissed[] = $validated['key'];
            $state->update(['dismissed' => $dismissed]);
        }

        return response()->json(['dismissed' => $state->dismissed]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/insights/accept
     */
    public function acceptInsight(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $validated = $request->validate(['key' => 'required|string|max:255']);
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $state = $draft->getOrCreateInsightState();
        $accepted = $state->accepted ?? [];
        if (! in_array($validated['key'], $accepted)) {
            $accepted[] = $validated['key'];
            $state->update(['accepted' => $accepted]);
        }

        return response()->json(['accepted' => $state->accepted]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/trigger-research
     */
    public function triggerResearch(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $validated = $request->validate(['url' => 'required|string|url']);
        $url = $validated['url'];
        $draftService = app(\App\Services\BrandDNA\BrandDnaDraftService::class);
        $draft = $draftService->getOrCreateDraftVersion($brand);
        RunBrandResearchJob::dispatch($brand->id, $draft->id, $url);
        return response()->json(['triggered' => true]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/trigger-ingestion
     * Process PDF, materials, and optionally website. Dispatches RunBrandIngestionJob.
     */
    public function triggerIngestion(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }
        $validated = $request->validate([
            'pdf_asset_id' => 'nullable|string|exists:assets,id',
            'website_url' => 'nullable|string|url',
            'material_asset_ids' => 'nullable|array',
            'material_asset_ids.*' => 'string|exists:assets,id',
        ]);
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $pdfAssetId = $validated['pdf_asset_id'] ?? null;
        $websiteUrl = $validated['website_url'] ?? null;
        $materialIds = $validated['material_asset_ids'] ?? [];
        if (empty($materialIds)) {
            $materialIds = $draft->assetsForContext('brand_material')->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        }
        $hasWork = $pdfAssetId || $websiteUrl || ! empty($materialIds);
        if (! $hasWork) {
            return response()->json(['error' => 'No sources to process. Add a PDF, website URL, or brand materials.'], 422);
        }
        RunBrandIngestionJob::dispatch($brand->id, $draft->id, $pdfAssetId, $websiteUrl, $materialIds);
        return response()->json(['triggered' => true]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/research-debug
     * Debug endpoint: full snapshot, draft, coherence, alignment. Only when APP_DEBUG=true.
     */
    public function researchDebug(Request $request, Brand $brand): JsonResponse
    {
        if (! config('app.debug')) {
            abort(404);
        }
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $draftPayload = $draft->model_payload ?? [];
        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();

        $latestResearch = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $runningSnapshot = BrandResearchSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();

        // Recompute coherence and alignment for debugging (same values as job)
        $snapshot = $latestResearch?->snapshot ?? [];
        $suggestions = $latestResearch?->suggestions ?? [];
        $coherence = $this->coherenceService->score(
            $draftPayload,
            $suggestions,
            $snapshot,
            $brand,
            $brandMaterialCount
        );
        $alignment = $this->alignmentEngine->analyze($draftPayload);

        return response()->json([
            'crawler_running' => $runningSnapshot !== null,
            'latest_snapshot' => $latestResearch ? [
                'id' => $latestResearch->id,
                'source_url' => $latestResearch->source_url,
                'status' => $latestResearch->status,
                'created_at' => $latestResearch->created_at?->toIso8601String(),
                'snapshot' => $latestResearch->snapshot,
                'suggestions' => $latestResearch->suggestions,
                'coherence' => $latestResearch->coherence,
                'alignment' => $latestResearch->alignment,
            ] : null,
            'draft_payload' => $draftPayload,
            'brand_material_count' => $brandMaterialCount,
            'brand_colors' => [
                'primary' => $brand->primary_color,
                'secondary' => $brand->secondary_color,
                'accent' => $brand->accent_color,
            ],
            'recomputed' => [
                'coherence' => $coherence,
                'alignment' => $alignment,
            ],
            'snapshot_schema' => [
                'logo_url' => 'URL of detected logo (currently stub: null)',
                'primary_colors' => 'Hex colors from site (currently stub: [])',
                'detected_fonts' => 'Font families from CSS (currently stub: [])',
                'hero_headlines' => 'Main headlines (currently stub: [])',
                'brand_bio' => 'About/description text (currently stub: null)',
            ],
        ]);
    }

    protected function getPayloadSnippetForStep(array $payload, string $stepKey): array
    {
        $step = BrandGuidelinesBuilderSteps::stepByKey($stepKey);
        if (! $step) {
            return [];
        }
        $paths = $step['allowed_paths'] ?? [];
        $snippet = [];
        foreach ($paths as $path) {
            if ($path !== 'brand_colors' && array_key_exists($path, $payload)) {
                $snippet[$path] = $payload[$path];
            }
        }

        return $snippet;
    }
}
