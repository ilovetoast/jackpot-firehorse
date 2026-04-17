<?php

namespace App\Http\Controllers;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Jobs\GenerateBrandLogoVariantsJob;
use App\Jobs\BrandPipelineRunnerJob;
use App\Jobs\BrandPipelineSnapshotJob;
use App\Jobs\ExtractPdfTextJob;
use App\Jobs\RunBrandResearchJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
use App\Models\PdfTextExtraction;
use App\Services\BrandDNA\BrandAlignmentEngine;
use App\Services\BrandDNA\BrandCoherenceScoringService;
use App\Services\BrandDNA\BrandGuidelinesPublishValidator;
use App\Services\BrandDNA\BrandModelService;
use App\Services\BrandDNA\BrandResearchNotificationService;
use App\Services\BrandDNA\BrandResearchReportBuilder;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\BrandDNA\CoherenceDeltaService;
use App\Services\BrandDNA\PipelineDurationEstimateService;
use App\Services\BrandDNA\PipelineFinalizationService;
use App\Services\BrandDNA\SuggestionApplier;
use App\Services\BrandDNA\SuggestionViewTransformer;
use App\Support\AssetVariant;
use App\Support\DeliveryContext;
use App\Support\WebsiteUrlNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Guidelines Builder v1 — backend API + wizard.
 */
class BrandDNABuilderController extends Controller
{
    public function __construct(
        private BrandVersionService $draftService,
        private BrandModelService $brandModelService,
        private BrandGuidelinesPublishValidator $publishValidator,
        private BrandCoherenceScoringService $coherenceService,
        private BrandAlignmentEngine $alignmentEngine,
        private SuggestionApplier $suggestionApplier,
        private CoherenceDeltaService $coherenceDeltaService,
        private \App\Services\BrandDNA\PublishedVersionGuard $publishedGuard,
        private PipelineFinalizationService $finalizationService,
        private \App\Services\BrandDNA\ResearchProgressService $progressService
    ) {}

    /**
     * GET /brands/{brand}/brand-guidelines/builder
     * Wizard UI shell.
     */
    public function show(Request $request, Brand $brand): Response|RedirectResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        // Plan gate: free plan users get redirected to the Brand Portal settings for DIY mode
        $planName = app(\App\Services\PlanService::class)->getCurrentPlan($tenant);
        if ($planName === 'free') {
            return redirect()->route('brands.edit', ['brand' => $brand->id, 'tab' => 'strategy'])
                ->with('warning', 'The AI-powered Brand Guidelines Builder requires a paid plan. You can manually configure your Brand DNA below.');
        }

        // Use existing draft or create one from active version's model_payload
        $draft = $this->draftService->getWorkingVersion($brand);

        // Lifecycle gate: redirect to research/review if not yet in build stage
        if ($draft->isInLifecycleStage(BrandModelVersion::LIFECYCLE_RESEARCH)) {
            return redirect()->route('brands.research.show', ['brand' => $brand->id]);
        }
        if ($draft->isInLifecycleStage(BrandModelVersion::LIFECYCLE_REVIEW)) {
            return redirect()->route('brands.review.show', ['brand' => $brand->id]);
        }

        // Active-processing gate: redirect to Research if any pipeline work is still in-flight.
        // This catches the case where a user re-runs analysis from the Research page and then
        // navigates to the Builder before it completes (lifecycle_stage is already 'build').
        if ($this->hasActiveResearchPipeline($draft)) {
            return redirect()->route('brands.research.show', ['brand' => $brand->id]);
        }

        // Unseen-results gate: redirect to Research if the latest completed run finished
        // AFTER the user last reviewed research results. Forces the user to click through
        // the Research completion CTA before entering the Builder.
        // Only applies when research_reviewed_at is already tracked (set by the
        // advanceToReview flow). Old users without this key skip the gate — they'll
        // start being protected after their next Research → Review transition.
        $builderProgress = $draft->builder_progress ?? [];
        if (array_key_exists('research_reviewed_at', $builderProgress) && ! empty($builderProgress['research_reviewed_at'])) {
            $latestCompletedRun = BrandPipelineRun::where('brand_model_version_id', $draft->id)
                ->where('status', BrandPipelineRun::STATUS_COMPLETED)
                ->latest('completed_at')
                ->first();
            $latestCompletedSnap = BrandPipelineSnapshot::where('brand_model_version_id', $draft->id)
                ->where('status', BrandPipelineSnapshot::STATUS_COMPLETED)
                ->latest()
                ->first();
            $latestFinishedAt = max(
                $latestCompletedRun?->completed_at?->timestamp ?? 0,
                $latestCompletedSnap?->updated_at?->timestamp ?? 0
            );
            try {
                $reviewedAt = \Carbon\Carbon::parse($builderProgress['research_reviewed_at'])->timestamp;
            } catch (\Throwable) {
                $reviewedAt = 0;
            }
            if ($latestFinishedAt > 0 && $latestFinishedAt > $reviewedAt) {
                return redirect()->route('brands.research.show', ['brand' => $brand->id]);
            }
        }

        $stepKeys = BrandGuidelinesBuilderSteps::stepKeys();
        $steps = BrandGuidelinesBuilderSteps::steps();
        $currentStep = $request->query('step', BrandGuidelinesBuilderSteps::STEP_ARCHETYPE);
        if ($currentStep === 'purpose') {
            $currentStep = BrandGuidelinesBuilderSteps::STEP_PURPOSE_PROMISE;
        }
        // Legacy step redirects: background/processing/research-summary now live on Research page
        if (in_array($currentStep, ['background', 'processing', 'research-summary'])) {
            $currentStep = BrandGuidelinesBuilderSteps::STEP_ARCHETYPE;
        }
        if (! BrandGuidelinesBuilderSteps::isValidStepKey($currentStep)) {
            $currentStep = BrandGuidelinesBuilderSteps::STEP_ARCHETYPE;
        }

        $anchor = $request->query('anchor');

        $this->recordBuilderStepVisit($draft, $currentStep);

        $crawlerRunning = false;
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
            $runningSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->whereIn('status', ['pending', 'running'])
                ->latest()
                ->first();
            $crawlerRunning = $runningSnapshot !== null;
            $latestResearch = BrandPipelineSnapshot::where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->where('status', 'completed')
                ->latest()
                ->first();
            $latestSnapshot = $latestResearch?->snapshot ?? [];
            $latestSuggestions = SuggestionViewTransformer::forFrontend($latestResearch?->suggestions ?? []);
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
            // Clean orphaned pivot rows (asset soft-deleted; pivot remains, causes 404 when frontend fetches)
            $pdfPivotAssetIds = BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
                ->where('builder_context', 'guidelines_pdf')
                ->pluck('asset_id');
            $existingIds = Asset::withoutTrashed()->whereIn('id', $pdfPivotAssetIds)->pluck('id');
            $orphanedIds = $pdfPivotAssetIds->diff($existingIds);
            if ($orphanedIds->isNotEmpty()) {
                BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
                    ->where('builder_context', 'guidelines_pdf')
                    ->whereIn('asset_id', $orphanedIds)
                    ->delete();
            }
            $guidelinesPdfAssetId = $guidelinesPdfAsset?->id;
            $guidelinesPdfFilename = $guidelinesPdfAsset?->original_filename;

            // Compute real overallStatus for processing gate (Background allows Next; processing step blocks until complete)
            $sources = $draft->model_payload['sources'] ?? [];
            $hasWebsiteUrl = ! empty(trim((string) ($sources['website_url'] ?? '')));
            $hasSocialUrls = ! empty($sources['social_urls'] ?? []);
            $hasPdf = $guidelinesPdfAsset !== null;
            $hasMaterials = $brandMaterialCount > 0;

            $pdfComplete = ! $hasPdf;
            if ($hasPdf && $guidelinesPdfAsset) {
                $latestRun = BrandPipelineRun::where('asset_id', $guidelinesPdfAsset->id)
                    ->where('brand_id', $brand->id)
                    ->where('brand_model_version_id', $draft->id)
                    ->latest()
                    ->first();
                if ($latestRun) {
                    $pdfComplete = in_array($latestRun->status, [BrandPipelineRun::STATUS_COMPLETED, BrandPipelineRun::STATUS_FAILED]);
                } else {
                    $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
                    $pdfComplete = $extraction && ! in_array($extraction->status, ['pending', 'processing']);
                }
            }

            $latestCompletedSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->where('status', 'completed')
                ->latest()
                ->first();
            $websiteComplete = ! $hasWebsiteUrl && ! $hasSocialUrls;
            if ($hasWebsiteUrl || $hasSocialUrls) {
                $websiteComplete = $latestCompletedSnapshot !== null && $runningSnapshot === null;
            }

            $materialsComplete = ! $hasMaterials || $latestCompletedSnapshot !== null;
            $allSourcesComplete = $pdfComplete && $websiteComplete && $materialsComplete;
            $overallStatus = $allSourcesComplete ? 'completed' : 'processing';

            $finalization = $this->finalizationService->compute(
                $brand->id,
                $draft->id,
                $guidelinesPdfAsset,
                $hasWebsiteUrl,
                $hasSocialUrls,
                $brandMaterialCount
            );
        } catch (\Throwable $e) {
            // Tables may not exist yet
            $overallStatus = $overallStatus ?? 'pending';
            $finalization = [
                'pipeline_status' => [
                    'pdf_render_complete' => true,
                    'page_classification_complete' => true,
                    'page_extraction_complete' => true,
                    'text_extraction_complete' => true,
                    'fusion_complete' => true,
                    'snapshot_persisted' => false,
                    'suggestions_ready' => false,
                    'coherence_ready' => false,
                    'alignment_ready' => false,
                    'research_finalized' => false,
                ],
                'research_finalized' => false,
            ];
        }

        // Logo: prefer logo_reference attached to this draft, fall back to brand.logo_id
        $logoAsset = $draft->assetsForContext('logo_reference')->first();
        $logoThumbnailUrl = null;
        $logoPreviewUrl = null;
        $logoAssetId = null;
        $logoOriginalFilename = null;
        if ($logoAsset) {
            $logoAssetId = $logoAsset->id;
            $logoThumbnailUrl = $logoAsset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED) ?: null;
            $logoPreviewUrl = $logoAsset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;
            $logoOriginalFilename = $logoAsset->original_filename;
        } elseif ($brand->logo_id) {
            $brandLogoAsset = Asset::withoutTrashed()->find($brand->logo_id);
            if ($brandLogoAsset) {
                $logoAssetId = $brandLogoAsset->id;
                $logoThumbnailUrl = $brandLogoAsset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED) ?: null;
                $logoPreviewUrl = $brandLogoAsset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;
                $logoOriginalFilename = $brandLogoAsset->original_filename;
            }
        }

        $buildLogoVariantProp = function (string $context) use ($draft): ?array {
            $asset = $draft->assetsForContext($context)->first();
            if (! $asset) {
                return null;
            }
            $thumbUrl = $asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED) ?: null;
            $previewUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;

            return [
                'id' => $asset->id,
                'thumbnail_url' => $thumbUrl,
                'preview_url' => $previewUrl,
                'original_filename' => $asset->original_filename,
                'thumbnail_status' => $asset->thumbnail_status ?? 'pending',
            ];
        };
        $logoOnDarkAsset = $buildLogoVariantProp('logo_on_dark');
        $logoOnLightAsset = $buildLogoVariantProp('logo_on_light');
        $logoHorizontalAsset = $buildLogoVariantProp('logo_horizontal');

        return Inertia::render('BrandGuidelines/Builder', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
                'secondary_color' => $brand->secondary_color,
                'accent_color' => $brand->accent_color,
            ],
            'logoAsset' => $logoAssetId ? [
                'id' => $logoAssetId,
                'thumbnail_url' => $logoThumbnailUrl,
                'preview_url' => $logoPreviewUrl,
                'original_filename' => $logoOriginalFilename,
                'thumbnail_status' => ($logoAsset ?? $brandLogoAsset ?? null)?->thumbnail_status ?? 'pending',
            ] : null,
            'logoOnDarkAsset' => $logoOnDarkAsset,
            'logoOnLightAsset' => $logoOnLightAsset,
            'logoHorizontalAsset' => $logoHorizontalAsset,
            'draft' => [
                'id' => $draft->id,
                'version_number' => $draft->version_number,
                'status' => $draft->status,
                'research_status' => $draft->research_status,
                'lifecycle_stage' => $draft->lifecycle_stage,
            ],
            'modelPayload' => $draft->model_payload ?? [],
            'steps' => $steps,
            'stepKeys' => $stepKeys,
            'currentStep' => $currentStep,
            'anchor' => $anchor,
            'crawlerRunning' => $crawlerRunning,
            'ingestionProcessing' => false,
            'ingestionRecords' => [],
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
            'researchFinalized' => $finalization['research_finalized'] ?? false,
            'pipelineStatus' => $finalization['pipeline_status'] ?? [],
            'isLocal' => app()->environment('local'),
            'brandResearchGate' => $this->getBrandResearchGate($tenant),
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

        $draft = $this->draftService->createNewVersion($brand);

        return redirect()->route('brands.research.show', [
            'brand' => $brand->id,
        ]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/discard-draft
     * Deletes all in-progress (draft) brand guideline versions. Published/active versions are unchanged.
     */
    public function discardDraft(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $removed = $this->draftService->discardAllDrafts($brand);

        $redirect = redirect()->route('brands.edit', [
            'brand' => $brand->id,
            'tab' => 'strategy',
        ]);

        if ($removed > 0) {
            return $redirect->with('success', 'In-progress brand guidelines were removed.');
        }

        return $redirect->with('info', 'There was no in-progress draft to remove.');
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
            'step_key' => ['required', 'string', 'in:'.implode(',', $stepKeys)],
            'payload' => 'required|array',
        ]);

        $draft = $this->draftService->getWorkingVersion($brand);
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

        $this->recordBuilderStepCompleted($draft, $validated['step_key']);

        if (in_array($validated['step_key'], [BrandGuidelinesBuilderSteps::STEP_STANDARDS, BrandGuidelinesBuilderSteps::STEP_EXPRESSION], true)) {
            GenerateBrandLogoVariantsJob::dispatch($brand->id, $draft->id);
        }

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
     *
     * When the user finishes the wizard: sets brand_models.active_version_id = draft_id,
     * marks the draft version as active, archives the previous active version.
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

        if ($version->status !== 'draft') {
            return response()->json([
                'error' => 'Version must be a draft to publish.',
            ], 422);
        }

        $validation = $this->publishValidator->validate($version, $brand);
        $hasErrors = ! empty($validation['errors']);
        $hasWarnings = ! empty($validation['warnings']);
        $acknowledgeWarnings = (bool) $request->input('acknowledge_warnings', false);

        if ($hasErrors) {
            return response()->json([
                'error' => 'validation_failed',
                'message' => 'Please complete all required fields before publishing.',
                'missing_fields' => $validation['errors'],
                'warnings' => $validation['warnings'],
            ], 422);
        }

        if ($hasWarnings && ! $acknowledgeWarnings) {
            return response()->json([
                'error' => 'warnings_unacknowledged',
                'message' => 'Some recommended fields are incomplete.',
                'warnings' => $validation['warnings'],
            ], 422);
        }

        $enableScoring = $request->input('enable_scoring');
        if ($enableScoring !== null) {
            $brandModel->update(['brand_dna_scoring_enabled' => (bool) $enableScoring]);
        }

        $this->brandModelService->activateVersion($version);
        $brandModel->update(['is_enabled' => true]);

        $this->finalizeAllDraftAssets($version, $brand);

        return response()->json([
            'active_version_id' => $version->id,
            'brand_dna_enabled' => true,
            'brand_dna_scoring_enabled' => $brandModel->fresh()->brand_dna_scoring_enabled ?? true,
        ]);
    }

    /**
     * On publish, finalize any builder-staged assets still attached to the draft.
     */
    protected function finalizeAllDraftAssets(BrandModelVersion $version, Brand $brand): void
    {
        $draftAssets = BrandModelVersionAsset::where('brand_model_version_id', $version->id)->get();
        foreach ($draftAssets as $pivot) {
            $asset = Asset::withoutTrashed()->find($pivot->asset_id);
            if ($asset && $asset->builder_staged) {
                $this->finalizeBuilderStagedAsset($asset, $brand, $pivot->builder_context ?? 'brand_material');
            }
        }
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
            'builder_context' => 'required|string|in:brand_material,visual_reference,typography_reference,logo_reference,guidelines_pdf,logo_on_dark,logo_on_light,logo_horizontal,crawled_logo_variant',
        ]);
        $draft = $this->draftService->getWorkingVersion($brand);
        $assetId = $validated['asset_id'];
        $context = $validated['builder_context'];

        // Single-asset contexts: only one per draft — replace any existing
        if (in_array($context, ['guidelines_pdf', 'logo_reference', 'logo_on_dark', 'logo_on_light', 'logo_horizontal'])) {
            BrandModelVersionAsset::where('brand_model_version_id', $draft->id)
                ->where('builder_context', $context)
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
            if ($context === 'logo_reference') {
                $brand->update(['logo_id' => $assetId, 'logo_path' => null]);
            }
            if ($context === 'logo_on_dark') {
                $brand->update(['logo_dark_id' => $assetId, 'logo_dark_path' => null]);
            }
            if ($context === 'logo_horizontal') {
                $brand->update(['logo_horizontal_id' => $assetId, 'logo_horizontal_path' => null]);
            }
        }

        $asset = Asset::withoutTrashed()->find($assetId);
        if ($asset && $asset->builder_staged) {
            $this->finalizeBuilderStagedAsset($asset, $brand, $context);
            $asset->refresh();
        }

        $count = $draft->assetsForContext($context)->count();

        $extra = [];
        if ($asset && in_array($context, ['logo_reference', 'logo_on_dark', 'logo_on_light', 'logo_horizontal', 'crawled_logo_variant'])) {
            $thumbStatus = $asset->thumbnail_status ?? 'pending';
            $thumbUrl = null;
            $previewUrl = null;
            try {
                $thumbUrl = ($thumbStatus === 'completed')
                    ? ($asset->deliveryUrl(AssetVariant::THUMB_MEDIUM, DeliveryContext::AUTHENTICATED) ?: null)
                    : null;
                $previewUrl = $asset->deliveryUrl(AssetVariant::ORIGINAL, DeliveryContext::AUTHENTICATED) ?: null;
            } catch (\Throwable $e) {
                Log::warning('[BrandDNA] attach-asset delivery URL failed', [
                    'asset_id' => $asset->id,
                    'context' => $context,
                    'error' => $e->getMessage(),
                ]);
            }
            $assetData = [
                'id' => $asset->id,
                'thumbnail_url' => $thumbUrl,
                'preview_url' => $previewUrl,
                'original_filename' => $asset->original_filename,
                'thumbnail_status' => $thumbStatus,
            ];
            if ($context === 'logo_reference') {
                $extra['logo_asset'] = $assetData;
            } elseif ($context !== 'crawled_logo_variant') {
                $extra['variant_asset'] = $assetData;
            } else {
                $extra['crawled_logo_asset'] = $assetData;
            }
        }

        if ($context === 'logo_reference') {
            GenerateBrandLogoVariantsJob::dispatch($brand->id, $draft->id);
        }

        return response()->json(array_merge(['attached' => true, 'count' => $count], $extra));
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
            'builder_context' => 'required|string|in:brand_material,visual_reference,typography_reference,logo_reference,guidelines_pdf,logo_on_dark,logo_on_light,logo_horizontal,crawled_logo_variant',
        ]);
        $draft = $this->draftService->getWorkingVersion($brand);
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
        if ($context === 'logo_on_dark' && $brand->logo_dark_id === $assetId) {
            $brand->update(['logo_dark_id' => null, 'logo_dark_path' => null]);
        }
        if ($context === 'logo_horizontal' && $brand->logo_horizontal_id === $assetId) {
            $brand->update(['logo_horizontal_id' => null, 'logo_horizontal_path' => null]);
        }
        $count = $draft->assetsForContext($context)->count();

        return response()->json(['detached' => true, 'count' => $count]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/generate-logo-guidelines
     * AI-generates logo usage guidelines based on the brand's identity.
     */
    public function generateLogoGuidelines(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getWorkingVersion($brand);
        $payload = $draft->model_payload ?? [];
        $identity = $payload['identity'] ?? [];
        $personality = $payload['personality'] ?? [];

        $brandName = $brand->name;
        $archetype = self::unwrapPayloadValue($personality['primary_archetype'] ?? '');
        $industry = self::unwrapPayloadValue($identity['industry'] ?? '');
        $brandLook = self::unwrapPayloadValue($personality['brand_look'] ?? '');

        $prompt = <<<PROMPT
You are a brand guidelines specialist. Generate logo usage guidelines for "{$brandName}".

Brand context:
- Industry: {$industry}
- Archetype: {$archetype}
- Brand look: {$brandLook}

Generate a JSON object with exactly these keys and practical, specific guidelines as string values:
- clear_space: Rules about minimum whitespace around the logo
- minimum_size: Minimum display size in pixels (digital) and inches (print)
- color_usage: When to use primary vs reversed/white logo versions
- dont_stretch: Rule about maintaining proportions
- dont_rotate: Rule about not tilting or rotating
- dont_recolor: Rule about not applying unauthorized colors
- dont_crop: Rule about not cropping or obscuring
- dont_add_effects: Rule about not adding shadows, outlines, etc.
- background_contrast: Rules about background selection for logo placement

Make each guideline 1-2 sentences, specific to the brand's industry and style. Be professional and direct.

Return ONLY the JSON object, no other text.
PROMPT;

        try {
            $ai = app(\App\Services\AI\Providers\AnthropicProvider::class);
            $result = $ai->generateText($prompt, ['max_tokens' => 1024]);
            $text = $result['text'] ?? '';
            $jsonMatch = [];
            if (preg_match('/\{[\s\S]*\}/', $text, $jsonMatch)) {
                $guidelines = json_decode($jsonMatch[0], true);
                if (is_array($guidelines) && count($guidelines) > 0) {
                    return response()->json(['guidelines' => $guidelines]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['guidelines' => [
            'clear_space' => 'Maintain a minimum clear space equal to the height of the logo mark on all sides.',
            'minimum_size' => 'The logo should never be displayed smaller than 24px in height on digital, or 0.5 inches in print.',
            'color_usage' => 'Use the primary brand color version on light backgrounds. Use the reversed (white) version on dark or busy backgrounds.',
            'dont_stretch' => 'Never stretch, compress, or distort the logo in any direction.',
            'dont_rotate' => 'Never rotate or tilt the logo at an angle.',
            'dont_recolor' => 'Never apply unapproved colors, gradients, or effects to the logo.',
            'dont_crop' => 'Never crop or partially obscure the logo.',
            'dont_add_effects' => 'Never add shadows, outlines, glows, or other visual effects to the logo.',
            'background_contrast' => 'Ensure sufficient contrast between the logo and its background. Avoid placing on busy imagery without a container.',
        ]]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/suggest-field
     * On-demand AI suggestion for a single brand field.
     */
    public function suggestField(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $usageService = app(\App\Services\AiUsageService::class);
        try {
            $usageService->checkUsage($tenant, 'suggestions');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json(['error' => 'Monthly AI suggestion limit reached for your plan.'], 429);
        }

        $validated = $request->validate([
            'field_path' => 'required|string|max:100',
        ]);

        $fieldPath = $validated['field_path'];
        $draft = $this->draftService->getWorkingVersion($brand);
        $payload = $draft->model_payload ?? [];

        $identity = $payload['identity'] ?? [];
        $personality = $payload['personality'] ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];

        $u = fn ($v) => self::unwrapPayloadValue($v);

        // Safely unwrap array fields that may be wrapped as { value: [...], source: '...' }
        $unwrapArray = function ($field) use ($u): array {
            if (is_array($field) && array_key_exists('value', $field) && isset($field['source'])) {
                $field = $field['value'] ?? [];
            }
            if (! is_array($field)) {
                return $field ? [strval($field)] : [];
            }

            return array_map(fn ($item) => $u($item), $field);
        };

        $brandContext = array_filter([
            'brand_name' => $brand->name,
            'industry' => $u($identity['industry'] ?? ''),
            'target_audience' => $u($identity['target_audience'] ?? ''),
            'mission' => $u($identity['mission'] ?? ''),
            'positioning' => $u($identity['positioning'] ?? ''),
            'tagline' => $u($identity['tagline'] ?? ''),
            'archetype' => $u($personality['primary_archetype'] ?? ''),
            'brand_look' => $u($personality['brand_look'] ?? ''),
            'voice' => $u($personality['voice_description'] ?? ''),
            'traits' => implode(', ', $unwrapArray($personality['traits'] ?? [])),
            'tone' => implode(', ', $unwrapArray($scoringRules['tone_keywords'] ?? [])),
            'beliefs' => implode('; ', $unwrapArray($identity['beliefs'] ?? [])),
            'values' => implode(', ', $unwrapArray($identity['values'] ?? [])),
        ]);

        $contextBlock = collect($brandContext)
            ->map(fn ($v, $k) => ucfirst(str_replace('_', ' ', $k)).': '.$v)
            ->implode("\n");

        // Pull research snapshot data if available for richer context
        $researchBlock = '';
        $latestSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        if ($latestSnapshot) {
            $snap = $latestSnapshot->snapshot ?? [];
            $researchParts = array_filter([
                ! empty($snap['mission']) ? 'Extracted mission: '.(is_string($snap['mission']) ? $snap['mission'] : json_encode($snap['mission'])) : null,
                ! empty($snap['tone']) ? 'Extracted tone: '.(is_array($snap['tone']) ? implode(', ', $snap['tone']) : $snap['tone']) : null,
                ! empty($snap['colors']) ? 'Extracted colors: '.(is_array($snap['colors']) ? implode(', ', $snap['colors']) : $snap['colors']) : null,
                ! empty($snap['fonts']) ? 'Extracted fonts: '.(is_array($snap['fonts']) ? implode(', ', $snap['fonts']) : $snap['fonts']) : null,
                ! empty($snap['positioning']) ? 'Extracted positioning: '.(is_string($snap['positioning']) ? $snap['positioning'] : json_encode($snap['positioning'])) : null,
                ! empty($snap['voice']) ? 'Extracted voice: '.(is_string($snap['voice']) ? $snap['voice'] : json_encode($snap['voice'])) : null,
                ! empty($snap['values']) ? 'Extracted values: '.(is_array($snap['values']) ? implode(', ', $snap['values']) : $snap['values']) : null,
            ]);
            if ($researchParts) {
                $researchBlock = "\n\nRESEARCH DATA (from website/PDF analysis):\n".implode("\n", $researchParts);
            }
        }

        $fieldLabels = [
            'identity.mission' => ['label' => 'brand mission (WHY the brand exists)', 'type' => 'string', 'example' => 'We\'re in business to save our home planet'],
            'identity.positioning' => ['label' => 'brand positioning / value proposition (WHAT it delivers)', 'type' => 'string', 'example' => 'Premium technology that just works'],
            'identity.industry' => ['label' => 'market industry / sector', 'type' => 'string', 'example' => 'Premium Outdoor Equipment'],
            'identity.target_audience' => ['label' => 'target audience', 'type' => 'string', 'example' => 'Health-conscious millennials who value sustainability'],
            'identity.market_category' => ['label' => 'market category', 'type' => 'string', 'example' => 'Premium Consumer Electronics'],
            'identity.competitive_position' => ['label' => 'competitive position / differentiation', 'type' => 'string', 'example' => 'The only brand that combines precision engineering with accessible pricing'],
            'identity.tagline' => ['label' => 'brand tagline / slogan', 'type' => 'string', 'example' => 'Just Do It'],
            'identity.beliefs' => ['label' => 'core brand beliefs', 'type' => 'array', 'example' => '["Quality over quantity", "Innovation drives progress"]'],
            'identity.values' => ['label' => 'brand values', 'type' => 'array', 'example' => '["Integrity", "Innovation", "Excellence"]'],
            'personality.voice_description' => ['label' => 'brand voice description (how the brand communicates)', 'type' => 'string', 'example' => 'Bold and direct with a coaching mentality. Uses short, punchy sentences.'],
            'personality.brand_look' => ['label' => 'brand visual look description', 'type' => 'string', 'example' => 'Clean and geometric with strong contrast. Bold accents against dark backgrounds.'],
            'personality.traits' => ['label' => 'personality traits (adjectives)', 'type' => 'array', 'example' => '["Bold", "Authentic", "Precise", "Fearless"]'],
            'scoring_rules.tone_keywords' => ['label' => 'tone of voice keywords', 'type' => 'array', 'example' => '["Confident", "Direct", "Warm", "Expert"]'],
        ];

        $fieldDef = $fieldLabels[$fieldPath] ?? null;
        if (! $fieldDef) {
            return response()->json(['error' => 'Field not supported for suggestions.'], 422);
        }

        $isArray = $fieldDef['type'] === 'array';
        $formatInstruction = $isArray
            ? 'Return ONLY a JSON array of 3-5 short strings. Example: '.$fieldDef['example']
            : 'Return ONLY a single string value, no JSON wrapping, no quotes. Example: '.$fieldDef['example'];

        $prompt = <<<PROMPT
You are a senior brand strategist advising a client. Based on everything you know about this brand, recommend a {$fieldDef['label']}.

BRAND CONTEXT:
{$contextBlock}{$researchBlock}

TASK: Suggest a {$fieldDef['label']} for "{$brand->name}".
{$formatInstruction}

Be specific, strategic, and tailored to this brand. Avoid generic answers. Return ONLY the value.
PROMPT;

        try {
            $ai = app(\App\Services\AI\Providers\OpenAIProvider::class);
            $result = $ai->generateText($prompt, [
                'model' => 'gpt-4o-mini',
                'max_tokens' => 300,
                'temperature' => 0.7,
            ]);
            $text = trim($result['text'] ?? '');

            $usageService->trackUsageWithCost(
                $tenant,
                'suggestions',
                1,
                ($result['tokens_in'] ?? 0) * 0.00000015 + ($result['tokens_out'] ?? 0) * 0.0000006,
                $result['tokens_in'] ?? null,
                $result['tokens_out'] ?? null,
                $result['model'] ?? 'gpt-4o-mini'
            );

            if ($isArray) {
                $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
                $text = preg_replace('/\s*```$/', '', $text);
                $parsed = json_decode($text, true);
                if (is_array($parsed)) {
                    return response()->json(['suggestion' => $parsed, 'type' => 'array']);
                }
                $lines = array_filter(array_map('trim', preg_split('/[\n,]+/', $text)));

                return response()->json(['suggestion' => array_values($lines), 'type' => 'array']);
            }

            $text = trim($text, '"\'');

            return response()->json(['suggestion' => $text, 'type' => 'string']);
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            return response()->json(['error' => 'AI suggestion limit reached for your plan.'], 429);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Failed to generate suggestion. Please try again.'], 500);
        }
    }

    private static function unwrapPayloadValue(mixed $val): string
    {
        if (is_array($val) && array_key_exists('value', $val) && isset($val['source'])) {
            $inner = $val['value'] ?? '';

            return is_array($inner) ? json_encode($inner) : (string) $inner;
        }

        if (is_array($val)) {
            return json_encode($val);
        }

        return (string) ($val ?? '');
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

        $draft = $this->draftService->getWorkingVersion($brand);
        $runs = BrandPipelineRun::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        $extractionStatus = null;
        $pdfState = ['status' => 'pending'];
        if ($guidelinesPdfAsset) {
            $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
            $extractionStatus = $extraction?->status ?? 'none';
            $latestRun = BrandPipelineRun::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->latest()
                ->first();
            if ($latestRun) {
                $pdfState = ['status' => $latestRun->status];
            } elseif ($extraction) {
                $pdfState = ['status' => $extraction->status];
            }
        }

        $pipelineProcessing = $runs->contains(fn ($r) => $r->status === BrandPipelineRun::STATUS_PROCESSING);
        $ingestionStatus = $pipelineProcessing ? 'processing' : ($runs->first()?->status ?? 'none');

        $latestSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();
        $snapshotExists = $latestSnapshot !== null;

        $websiteUrl = $draft->model_payload['sources']['website_url'] ?? null;
        $hasWebsite = ! empty(trim((string) $websiteUrl));
        $websiteStatus = 'pending';
        if ($hasWebsite) {
            if ($pipelineProcessing) {
                $websiteStatus = 'processing';
            } elseif ($snapshotExists) {
                $websiteStatus = 'completed';
            }
        }
        $websiteState = ['status' => $websiteStatus, 'signals_detected' => 0];
        $socialState = ['status' => 'pending'];
        $materialsState = ['status' => 'pending', 'assets_total' => 0, 'assets_processed' => 0];

        $overallStatus = $pipelineProcessing ? 'processing' : ($snapshotExists ? 'completed' : 'pending');
        $latestRun = $runs->first();
        if ($latestRun?->status === BrandPipelineRun::STATUS_FAILED) {
            $overallStatus = 'failed';
        }
        $suggestionCount = $latestSnapshot ? count($latestSnapshot->suggestions ?? []) : 0;

        if ($pdfState['status'] === 'processing') {
            $overallStatus = 'processing';
        }

        [$pipelineError, $pipelineErrorKind, $canRetry] = $this->detectPipelineError($latestRun);
        if ($pipelineErrorKind === 'stuck') {
            $overallStatus = 'failed';
            $pdfState['status'] = 'failed';
        }

        $items = $runs->map(function (BrandPipelineRun $r) {
            return [
                'id' => $r->id,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'explicit_signals' => [],
                'signals_count' => 0,
                'confidence' => 0,
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
            'pipeline_error' => $pipelineError,
            'pipeline_error_kind' => $pipelineErrorKind,
            'can_retry' => $canRetry,
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
        $draft = $this->draftService->getWorkingVersion($brand);
        $state = $draft->getOrCreateInsightState();
        $runningSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();
        $latestCompletedSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $pipelineRuns = BrandPipelineRun::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
        $pipelineProcessing = $pipelineRuns->contains(fn ($r) => $r->status === BrandPipelineRun::STATUS_PROCESSING);

        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        /** Latest run for this draft (PDF-only, website-only, or mixed) — used for progress + errors */
        $latestRunForDraft = $pipelineRuns->first();
        $pdfScopedRun = null;
        $pdfState = ['status' => 'pending'];
        if ($guidelinesPdfAsset) {
            $pdfScopedRun = BrandPipelineRun::where('asset_id', $guidelinesPdfAsset->id)
                ->where('brand_id', $brand->id)
                ->where('brand_model_version_id', $draft->id)
                ->latest()
                ->first();
            if ($pdfScopedRun) {
                $pdfState = [
                    'status' => $pdfScopedRun->status,
                    'run_id' => $pdfScopedRun->id,
                    'stage' => $pdfScopedRun->stage,
                    'extraction_mode' => $pdfScopedRun->extraction_mode,
                    'pages_processed' => (int) ($pdfScopedRun->pages_processed ?? 0),
                    'pages_total' => (int) ($pdfScopedRun->pages_total ?? 0),
                    'progress_percent' => $pdfScopedRun->progress_percent,
                    'error_message' => $pdfScopedRun->error_message,
                ];
            } else {
                $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
                if ($extraction) {
                    $extStatus = $extraction->status;
                    $pdfState = [
                        'status' => $extStatus === 'complete' ? 'completed' : ($extStatus === 'failed' ? 'failed' : $extStatus),
                    ];
                }
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
            if ($pdfScopedRun) {
                $pdfComplete = in_array($pdfScopedRun->status, [BrandPipelineRun::STATUS_COMPLETED, BrandPipelineRun::STATUS_FAILED]);
            } else {
                $extraction = $guidelinesPdfAsset->getLatestPdfTextExtractionForVersion($guidelinesPdfAsset->currentVersion?->id);
                $pdfComplete = $extraction && ! in_array($extraction->status, ['pending', 'processing']);
            }
        }

        $websiteComplete = ! $hasWebsiteUrl && ! $hasSocialUrls;
        if ($hasWebsiteUrl || $hasSocialUrls) {
            $websiteComplete = $latestCompletedSnapshot !== null && $runningSnapshot === null;
        }

        $materialsComplete = ! $hasMaterials || $latestCompletedSnapshot !== null;

        $allSourcesComplete = $pdfComplete && $websiteComplete && $materialsComplete;
        $overallStatus = $allSourcesComplete ? 'completed' : 'processing';
        if ($latestRunForDraft?->status === BrandPipelineRun::STATUS_FAILED) {
            $overallStatus = 'failed';
        }

        $finalization = $this->finalizationService->compute(
            $brand->id,
            $draft->id,
            $guidelinesPdfAsset,
            $hasWebsiteUrl,
            $hasSocialUrls,
            $brandMaterialCount
        );

        if ($finalization['research_finalized'] ?? false) {
            app(BrandResearchNotificationService::class)->maybeNotifyResearchReady($brand, $draft);
            $this->draftService->markResearchComplete($draft);
        }

        $websiteUrlForState = $draft->model_payload['sources']['website_url'] ?? null;
        $hasWebsiteForState = ! empty(trim((string) $websiteUrlForState));
        $websiteStatusForState = 'pending';
        if ($hasWebsiteForState) {
            if ($runningSnapshot !== null || ($latestRunForDraft && $latestRunForDraft->status === BrandPipelineRun::STATUS_PROCESSING)) {
                $websiteStatusForState = 'processing';
            } elseif ($latestCompletedSnapshot) {
                $websiteStatusForState = 'completed';
            }
        }
        $websiteState = ['status' => $websiteStatusForState, 'signals_detected' => 0];
        $socialState = ['status' => 'pending'];
        $materialsState = ['status' => 'pending', 'assets_total' => 0, 'assets_processed' => 0];

        $snapshotData = $latestCompletedSnapshot?->snapshot ?? [];

        if (config('app.env') !== 'production' && $latestCompletedSnapshot) {
            \Illuminate\Support\Facades\Log::info('[BrandDNABuilderController::researchInsights] Snapshot loaded', [
                'snapshot_id' => $latestCompletedSnapshot->id,
                'extraction_mode' => $snapshotData['extraction_mode'] ?? null,
            ]);
        }

        $suggestionsData = $latestCompletedSnapshot?->suggestions ?? [];
        $coherenceData = $latestCompletedSnapshot?->coherence ?? null;
        $alignmentData = $latestCompletedSnapshot?->alignment ?? null;

        $report = $latestCompletedSnapshot?->report;
        if ($report === null && $latestCompletedSnapshot) {
            $report = BrandResearchReportBuilder::build(
                $snapshotData,
                $suggestionsData,
                $coherenceData ?? [],
                $alignmentData ?? [],
                $this->deriveReportSources($draft)
            );
        }
        $report = $report ?? BrandResearchReportBuilder::build([], [], [], [], []);

        $processingProgress = $this->progressService->compute(
            [
                'pipeline_status' => $finalization['pipeline_status'],
                'pdf' => $pdfState,
            ],
            $latestRunForDraft,
            $guidelinesPdfAsset
        );

        [$pipelineError, $pipelineErrorKind, $canRetry] = $this->detectPipelineError($latestRunForDraft);

        $pdfByteSizeForEstimate = null;
        if ($guidelinesPdfAsset && $guidelinesPdfAsset->size_bytes) {
            $pdfByteSizeForEstimate = (int) $guidelinesPdfAsset->size_bytes;
        } elseif ($latestRunForDraft?->source_size_bytes) {
            $pdfByteSizeForEstimate = (int) $latestRunForDraft->source_size_bytes;
        }
        $modeForEstimate = $pdfScopedRun?->extraction_mode
            ?? $latestRunForDraft?->extraction_mode
            ?? BrandPipelineRun::EXTRACTION_MODE_TEXT;
        $pipelineDurationEstimate = app(PipelineDurationEstimateService::class)->estimate(
            $tenant->id,
            $pdfByteSizeForEstimate,
            $modeForEstimate
        );
        $pipelineTiming = app(PipelineDurationEstimateService::class)->computeActiveRunTiming(
            $latestRunForDraft,
            $pipelineDurationEstimate,
            $modeForEstimate
        );

        return response()->json([
            'processing_progress' => $processingProgress,
            'pipeline_duration_estimate' => $pipelineDurationEstimate,
            'pipeline_timing' => $pipelineTiming,
            'pipeline_error' => $pipelineError,
            'pipeline_error_kind' => $pipelineErrorKind,
            'can_retry' => $canRetry,
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
            'report' => $report,
            'latestSuggestions' => SuggestionViewTransformer::forFrontend($suggestionsData, $snapshotData),
            'researchFinalized' => $finalization['research_finalized'],
            'pipelineStatus' => $finalization['pipeline_status'],
            'developer_data' => array_filter(array_merge([
                'snapshot' => $snapshotData,
                'pipeline_status' => $finalization['pipeline_status'],
                'coherence' => $coherenceData,
                'alignment' => $alignmentData,
                'sections' => $latestCompletedSnapshot?->sections_json ?? null,
                'extraction_debug' => $snapshotData['extraction_debug'] ?? null,
                'evidence_map' => $snapshotData['evidence_map'] ?? null,
                'rejected_field_candidates' => $snapshotData['rejected_field_candidates'] ?? null,
                'narrative_field_debug' => $snapshotData['narrative_field_debug'] ?? null,
                'explicit_signals' => $snapshotData['explicit_signals'] ?? null,
                'pipeline_version' => $snapshotData['pipeline_version'] ?? null,
                'snapshot_generated_at' => $snapshotData['snapshot_generated_at'] ?? null,
                'stale_snapshot_warning' => $this->computeStaleSnapshotWarning($snapshotData),
            ], []), fn ($v) => $v !== null),
            'latestSnapshot' => $snapshotData,
            'latestCoherence' => $coherenceData,
            'latestAlignment' => $alignmentData,
            'insightState' => [
                'dismissed' => $state->dismissed ?? [],
                'accepted' => $state->accepted ?? [],
            ],
            'ingestionProcessing' => $pipelineProcessing,
            'ingestionRecords' => $pipelineRuns->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
                'error' => $r->error_message,
            ])->values()->all(),
            'latestIngestion' => $pipelineRuns->first() ? [
                'id' => $pipelineRuns->first()->id,
                'status' => $pipelineRuns->first()->status,
            ] : null,
            'pdf' => $pdfState,
            'website' => $websiteState,
            'social' => $socialState,
            'materials' => $materialsState,
            'overall_status' => $overallStatus,
        ]);
    }

    protected function deriveReportSources(BrandModelVersion $draft): array
    {
        $sources = [];
        if ($draft->assetsForContext('guidelines_pdf')->exists()) {
            $sources[] = 'pdf';
        }
        $payload = $draft->model_payload ?? [];
        if (! empty(trim((string) ($payload['sources']['website_url'] ?? '')))) {
            $sources[] = 'website';
        }
        if ($draft->assetsForContext('brand_material')->exists()) {
            $sources[] = 'materials';
        }

        return array_values(array_unique($sources));
    }

    protected function computeStaleSnapshotWarning(array $snapshotData): ?string
    {
        $hasVersion = ! empty($snapshotData['pipeline_version']);
        if (! $hasVersion) {
            return 'This research snapshot was generated with an older pipeline version. Re-run analysis to use the latest extraction logic.';
        }

        return null;
    }

    /**
     * Record builder step visit for resume flow. Updates last_visited_step.
     * When transitioning from research-summary to a later step, sets research_reviewed_at.
     */
    protected function recordBuilderStepVisit(BrandModelVersion $draft, string $currentStep): void
    {
        try {
            $progress = $draft->builder_progress ?? [];
            $lastVisited = $progress['last_visited_step'] ?? null;

            if ($currentStep === 'research-summary') {
                $state = $draft->insightState;
                if ($state && ! $state->viewed_at) {
                    $state->update(['viewed_at' => now()]);
                }
            }

            if ($lastVisited === 'research-summary' && in_array($currentStep, ['archetype', 'purpose_promise', 'expression', 'positioning', 'standards'], true)) {
                $progress['research_reviewed_at'] = now()->toIso8601String();
                $progress['last_completed_step'] = 'research-summary';
            }

            $progress['last_visited_step'] = $currentStep;
            $draft->update(['builder_progress' => $progress]);
        } catch (\Throwable $e) {
            // Non-critical; do not block builder load
        }
    }

    /**
     * Record that user completed a builder step (saved via patch).
     */
    protected function recordBuilderStepCompleted(BrandModelVersion $draft, string $stepKey): void
    {
        try {
            $progress = $draft->builder_progress ?? [];
            $progress['last_completed_step'] = $stepKey;
            $progress['last_visited_step'] = $stepKey;
            $draft->update(['builder_progress' => $progress]);
        } catch (\Throwable $e) {
            // Non-critical
        }
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

        $draft = $this->draftService->getWorkingVersion($brand);
        $snapshots = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->get();

        $items = $snapshots->map(function (BrandPipelineSnapshot $s) {
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
    public function compareResearchSnapshots(Request $request, Brand $brand, BrandPipelineSnapshot $snapshot, BrandPipelineSnapshot $otherSnapshot): JsonResponse
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
    public function showResearchSnapshot(Request $request, Brand $brand, BrandPipelineSnapshot $snapshot): JsonResponse
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
    public function applySuggestion(Request $request, Brand $brand, BrandPipelineSnapshot $snapshot): JsonResponse
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
    public function dismissSuggestion(Request $request, Brand $brand, BrandPipelineSnapshot $snapshot): JsonResponse
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
        $draft = $this->draftService->getWorkingVersion($brand);
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
        $draft = $this->draftService->getWorkingVersion($brand);
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
        $request->merge([
            'url' => WebsiteUrlNormalizer::normalize($request->input('url')) ?? '',
        ]);
        $validated = $request->validate(['url' => 'required|string|url']);
        $url = $validated['url'];
        $draft = $this->draftService->getWorkingVersion($brand);
        RunBrandResearchJob::dispatch($brand->id, $draft->id, $url);

        return response()->json(['triggered' => true]);
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/trigger-ingestion
     * Process PDF, materials, and optionally website. Dispatches BrandPipelineRunnerJob.
     */
    public function triggerIngestion(Request $request, Brand $brand): JsonResponse
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

            if (($status['is_disabled'] ?? false) || ($status['cap'] ?? 0) === 0) {
                return response()->json([
                    'error' => 'AI brand research requires a paid plan. Upgrade to Starter or above to analyze brand guidelines with AI.',
                    'gate' => 'plan_required',
                    'plan' => $planName,
                ], 403);
            }

            return response()->json([
                'error' => "Monthly brand research limit reached ({$status['usage']}/{$status['cap']}). Usage resets next month, or upgrade your plan for more.",
                'gate' => 'usage_exceeded',
                'usage' => $status['usage'] ?? 0,
                'cap' => $status['cap'] ?? 0,
                'plan' => $planName,
            ], 429);
        }

        $request->merge([
            'website_url' => WebsiteUrlNormalizer::normalize($request->input('website_url')),
        ]);
        $validated = $request->validate([
            'pdf_asset_id' => 'nullable|string|exists:assets,id',
            'website_url' => 'nullable|string|url',
            'material_asset_ids' => 'nullable|array',
            'material_asset_ids.*' => 'string|exists:assets,id',
        ]);
        $draft = $this->draftService->getWorkingVersion($brand);
        $pdfAssetId = $validated['pdf_asset_id'] ?? null;
        $websiteUrl = $validated['website_url'] ?? null;
        $materialIds = $validated['material_asset_ids'] ?? [];
        if (empty($materialIds)) {
            $materialIds = $draft->assetsForContext('brand_material')->get()->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        }
        $hasWork = $pdfAssetId || $websiteUrl || ! empty($materialIds);
        if (! $hasWork) {
            return response()->json(['error' => 'No sources to process. Add a PDF, website URL, or brand materials.'], 422);
        }
        $extractionMode = BrandPipelineRun::EXTRACTION_MODE_TEXT;
        $pageCount = null;
        $pdfAsset = null;
        if ($pdfAssetId) {
            $pdfAsset = Asset::find($pdfAssetId);
            if ($pdfAsset && str_contains(strtolower($pdfAsset->mime_type ?? ''), 'pdf')) {
                $extractionMode = BrandPipelineRun::resolveExtractionMode($pdfAsset);
            }
        }
        \Illuminate\Support\Facades\Log::channel('pipeline')->info('[triggerIngestion] Pipeline start', [
            'brand_id' => $brand->id,
            'pdf_asset_id' => $pdfAssetId,
            'page_count' => $pageCount,
            'extraction_mode' => $extractionMode,
        ]);
        $run = BrandPipelineRun::create([
            'brand_id' => $brand->id,
            'brand_model_version_id' => $draft->id,
            'asset_id' => $pdfAssetId,
            'source_size_bytes' => BrandPipelineRun::sourceSizeBytesFromAsset($pdfAsset ?? null),
            'stage' => BrandPipelineRun::STAGE_INIT,
            'extraction_mode' => $extractionMode,
            'status' => BrandPipelineRun::STATUS_PENDING,
        ]);
        BrandPipelineRunnerJob::dispatch($run->id);

        try {
            $aiUsageService->trackUsage($tenant, 'brand_research');
        } catch (\App\Exceptions\PlanLimitExceededException $e) {
            \Illuminate\Support\Facades\Log::warning('[triggerIngestion] Usage tracking failed after dispatch', [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['triggered' => true]);
    }

    /**
     * GET /brands/{brand}/brand-dna/builder/pipeline-diagnostics
     * Debug endpoint: extraction mode, page count, whether page analysis was produced. Only when APP_DEBUG=true.
     */
    public function pipelineDiagnostics(Request $request, Brand $brand): JsonResponse
    {
        if (! config('app.debug')) {
            abort(404);
        }
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getWorkingVersion($brand);
        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();

        $pdfPageCount = null;
        $pdfPageCountError = null;
        if ($guidelinesPdfAsset) {
            try {
                $pdfPageCount = app(\App\Services\PdfPageRenderingService::class)->getPdfPageCount($guidelinesPdfAsset, true);
            } catch (\Throwable $e) {
                $pdfPageCountError = $e->getMessage();
            }
        }

        $runs = BrandPipelineRun::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'asset_id', 'extraction_mode', 'stage', 'status', 'pages_total', 'pages_processed', 'error_message', 'created_at', 'completed_at']);

        $latestRun = $runs->first();
        $latestCompletedSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $wouldUseVision = $pdfPageCountError !== null || ($pdfPageCount !== null && $pdfPageCount > 1);
        $extractionModeWouldBe = $wouldUseVision ? BrandPipelineRun::EXTRACTION_MODE_VISION : BrandPipelineRun::EXTRACTION_MODE_TEXT;

        return response()->json([
            'brand_id' => $brand->id,
            'draft_id' => $draft->id,
            'guidelines_pdf_asset_id' => $guidelinesPdfAsset?->id,
            'pdf_page_count' => $pdfPageCount,
            'pdf_page_count_error' => $pdfPageCountError,
            'extraction_mode_would_be' => $extractionModeWouldBe,
            'latest_run' => $latestRun ? [
                'id' => $latestRun->id,
                'extraction_mode' => $latestRun->extraction_mode,
                'stage' => $latestRun->stage,
                'status' => $latestRun->status,
                'pages_total' => $latestRun->pages_total,
                'pages_processed' => $latestRun->pages_processed,
                'error_message' => $latestRun->error_message,
                'created_at' => $latestRun->created_at?->toIso8601String(),
                'completed_at' => $latestRun->completed_at?->toIso8601String(),
            ] : null,
            'latest_completed_snapshot' => $latestCompletedSnapshot ? [
                'id' => $latestCompletedSnapshot->id,
            ] : null,
            'recent_runs' => $runs->map(fn ($r) => [
                'id' => $r->id,
                'extraction_mode' => $r->extraction_mode,
                'stage' => $r->stage,
                'status' => $r->status,
                'pages_total' => $r->pages_total,
                'pages_processed' => $r->pages_processed,
            ])->all(),
        ]);
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

        $draft = $this->draftService->getWorkingVersion($brand);
        $draftPayload = $draft->model_payload ?? [];
        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();

        $latestResearch = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $runningSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
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

    /**
     * Finalize a builder-staged asset: unstage, assign category, publish.
     * Makes the asset visible in the main Assets library.
     */
    protected function finalizeBuilderStagedAsset(Asset $asset, Brand $brand, string $context): void
    {
        if (! $asset->builder_staged) {
            return;
        }

        $categorySlug = match ($context) {
            'logo_reference' => 'logos',
            'crawled_logo_variant' => 'logos',
            'visual_reference' => 'photography',
            'guidelines_pdf' => 'reference_material',
            default => null,
        };

        $promoteToAsset = in_array($context, ['logo_reference', 'crawled_logo_variant', 'visual_reference'], true);

        DB::transaction(function () use ($asset, $brand, $categorySlug, $promoteToAsset) {
            if ($categorySlug) {
                $category = \App\Models\Category::where('brand_id', $brand->id)
                    ->where('slug', $categorySlug)
                    ->first();
                if ($category) {
                    $metadata = $asset->metadata ?? [];
                    $metadata['category_id'] = $category->id;
                    $asset->metadata = $metadata;
                }
            }

            $asset->builder_staged = false;
            $asset->intake_state = 'normal';

            if ($promoteToAsset) {
                $asset->type = \App\Enums\AssetType::ASSET;
            }

            $asset->save();

            if (! $asset->isPublished() && auth()->user()) {
                try {
                    app(\App\Services\AssetPublicationService::class)->publish($asset, auth()->user());
                } catch (\Throwable $e) {
                    \Log::warning('[BrandDNA] Could not publish builder asset', [
                        'asset_id' => $asset->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Detect if the latest pipeline run has failed or is stuck.
     *
     * @return array{0: string|null, 1: string|null, 2: bool} [error_message, error_kind, can_retry]
     */
    protected function detectPipelineError(?BrandPipelineRun $run): array
    {
        if (! $run) {
            return [null, null, false];
        }

        if ($run->status === BrandPipelineRun::STATUS_FAILED) {
            $message = app()->environment('production')
                ? 'Processing failed. You can retry to re-analyze your brand materials.'
                : ($run->error_message ?: 'Processing failed with an unknown error.');

            return [$message, 'failed', true];
        }

        if ($run->status === BrandPipelineRun::STATUS_PROCESSING) {
            $stuckThreshold = now()->subMinutes(5);
            $lastActivity = $run->updated_at ?? $run->created_at;
            if ($lastActivity && $lastActivity->lt($stuckThreshold)) {
                $minutesAgo = (int) $lastActivity->diffInMinutes(now());

                return [
                    "Processing appears to be stuck (no progress for {$minutesAgo} minutes). You can retry to restart the analysis.",
                    'stuck',
                    true,
                ];
            }
        }

        return [null, null, false];
    }

    /**
     * POST /brands/{brand}/brand-dna/builder/retry-pipeline
     * Reset a failed/stuck pipeline run and re-dispatch it.
     */
    public function retryPipeline(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $draft = $this->draftService->getWorkingVersion($brand);
        $latestRun = BrandPipelineRun::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $draft->id)
            ->latest()
            ->first();

        if (! $latestRun) {
            return response()->json(['error' => 'No pipeline run found to retry.'], 404);
        }

        $isFailed = $latestRun->status === BrandPipelineRun::STATUS_FAILED;
        $isStuck = $latestRun->status === BrandPipelineRun::STATUS_PROCESSING
            && $latestRun->updated_at?->lt(now()->subMinutes(5));

        if (! $isFailed && ! $isStuck) {
            return response()->json(['error' => 'Pipeline is still actively processing.'], 409);
        }

        $hasExtraction = ! empty($latestRun->merged_extraction_json);
        if ($hasExtraction && $latestRun->stage === BrandPipelineRun::STAGE_ANALYZING) {
            $latestRun->update([
                'status' => BrandPipelineRun::STATUS_PROCESSING,
                'error_message' => null,
            ]);
            BrandPipelineSnapshotJob::dispatch($latestRun->id);

            \Illuminate\Support\Facades\Log::channel('pipeline')->info('[retryPipeline] Re-dispatching snapshot job (extraction data exists)', [
                'run_id' => $latestRun->id,
                'brand_id' => $brand->id,
            ]);

            return response()->json(['retried' => true, 'strategy' => 'snapshot_only']);
        }

        $latestRun->update([
            'stage' => BrandPipelineRun::STAGE_INIT,
            'status' => BrandPipelineRun::STATUS_PENDING,
            'error_message' => null,
            'merged_extraction_json' => null,
            'raw_api_response_json' => null,
            'pages_processed' => 0,
        ]);
        BrandPipelineRunnerJob::dispatch($latestRun->id);

        \Illuminate\Support\Facades\Log::channel('pipeline')->info('[retryPipeline] Full pipeline restart', [
            'run_id' => $latestRun->id,
            'brand_id' => $brand->id,
        ]);

        return response()->json(['retried' => true, 'strategy' => 'full_restart']);
    }

    protected function hasActiveResearchPipeline(BrandModelVersion $version): bool
    {
        if ($version->research_status === BrandModelVersion::RESEARCH_RUNNING) {
            return true;
        }

        $activeRun = BrandPipelineRun::where('brand_model_version_id', $version->id)
            ->whereIn('status', [BrandPipelineRun::STATUS_PENDING, BrandPipelineRun::STATUS_PROCESSING])
            ->exists();
        if ($activeRun) {
            return true;
        }

        return BrandPipelineSnapshot::where('brand_model_version_id', $version->id)
            ->whereIn('status', [BrandPipelineSnapshot::STATUS_PENDING, BrandPipelineSnapshot::STATUS_RUNNING])
            ->exists();
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
}
