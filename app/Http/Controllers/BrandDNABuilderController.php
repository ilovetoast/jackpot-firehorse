<?php

namespace App\Http\Controllers;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Jobs\RunBrandResearchJob;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandIngestionRecord;
use App\Models\BrandModelVersionAsset;
use App\Models\BrandResearchSnapshot;
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
        private CoherenceDeltaService $coherenceDeltaService
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
        $latestSnapshot = [];
        $latestSuggestions = [];
        $latestSnapshotLite = null;
        $latestCoherence = null;
        $latestAlignment = null;
        $insightState = ['dismissed' => [], 'accepted' => []];
        $brandMaterialCount = 0;
        $brandMaterials = [];
        $visualReferences = [];
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
        } catch (\Throwable $e) {
            // Tables may not exist yet
        }

        return Inertia::render('BrandGuidelines/Builder', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color ?? '#6366f1',
                'secondary_color' => $brand->secondary_color ?? '#8b5cf6',
                'accent_color' => $brand->accent_color ?? '#06b6d4',
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
            'latestSnapshot' => $latestSnapshot,
            'latestSuggestions' => $latestSuggestions,
            'latestSnapshotLite' => $latestSnapshotLite,
            'latestCoherence' => $latestCoherence,
            'latestAlignment' => $latestAlignment,
            'insightState' => $insightState,
            'brandMaterialCount' => $brandMaterialCount,
            'brandMaterials' => $brandMaterials,
            'visualReferences' => $visualReferences,
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
            'builder_context' => 'required|string|in:brand_material,visual_reference,typography_reference,logo_reference',
        ]);
        $draft = $this->draftService->getOrCreateDraftVersion($brand);
        $assetId = $validated['asset_id'];
        $context = $validated['builder_context'];
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
            'builder_context' => 'required|string|in:brand_material,visual_reference,typography_reference,logo_reference',
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

        return response()->json(['ingestions' => $items]);
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

        $previousCoherence = $snapshot->coherence ?? [];

        $draft = $this->suggestionApplier->apply($draft, $suggestion);

        $accepted = $state->accepted ?? [];
        if (! in_array($key, $accepted)) {
            $accepted[] = $key;
        }
        $dismissed = array_values(array_filter($dismissed, fn ($k) => $k !== $key));
        $state->update(['accepted' => $accepted, 'dismissed' => $dismissed]);

        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();
        $currentCoherence = $this->coherenceService->score(
            $draft->model_payload ?? [],
            $suggestions,
            $snapshot->snapshot ?? [],
            $brand,
            $brandMaterialCount
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
