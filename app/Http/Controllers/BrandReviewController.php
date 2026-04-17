<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\BrandModelVersion;
use App\Models\BrandPipelineRun;
use App\Models\BrandPipelineSnapshot;
use App\Services\BrandDNA\BrandVersionService;
use App\Services\BrandDNA\SuggestionViewTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brand Review — AI output validation before entering the builder.
 */
class BrandReviewController extends Controller
{
    public function __construct(
        private BrandVersionService $versionService
    ) {}

    /**
     * GET /brands/{brand}/review
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
                ->with('warning', 'Brand Review requires a paid plan. You can manually configure your Brand DNA below.');
        }

        $version = $this->versionService->getWorkingVersion($brand);

        // If still in research, redirect there
        if ($version->isInLifecycleStage(BrandModelVersion::LIFECYCLE_RESEARCH)) {
            return redirect()->route('brands.research.show', ['brand' => $brand->id]);
        }

        // Active-processing gate: bounce to Research if a pipeline run is in-flight
        if ($this->hasActiveResearchPipeline($version)) {
            return redirect()->route('brands.research.show', ['brand' => $brand->id]);
        }

        $latestSnapshot = BrandPipelineSnapshot::where('brand_id', $brand->id)
            ->where('brand_model_version_id', $version->id)
            ->where('status', 'completed')
            ->latest()
            ->first();

        $snapshotData = $latestSnapshot?->snapshot ?? [];
        $suggestions = $latestSnapshot?->suggestions ?? [];
        $coherence = $latestSnapshot?->coherence;
        $alignment = $latestSnapshot?->alignment;

        $insightState = ['dismissed' => [], 'accepted' => []];
        try {
            $state = $version->getOrCreateInsightState($latestSnapshot?->id);
            $insightState = [
                'dismissed' => $state->dismissed ?? [],
                'accepted' => $state->accepted ?? [],
            ];
        } catch (\Throwable $e) {
            // Table may not exist
        }

        return Inertia::render('Brand/Review', [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'primary_color' => $brand->primary_color,
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'lifecycle_stage' => $version->lifecycle_stage,
                'review_status' => $version->review_status,
            ],
            'snapshot' => $snapshotData,
            'suggestions' => SuggestionViewTransformer::forFrontend($suggestions, $snapshotData),
            'coherence' => $coherence,
            'alignment' => $alignment,
            'insightState' => $insightState,
            'snapshotId' => $latestSnapshot?->id,
            'modelPayload' => $version->model_payload ?? [],
        ]);
    }

    /**
     * POST /brands/{brand}/review/advance-to-build
     */
    public function advanceToBuild(Request $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);
        $tenant = app('tenant');
        if ($brand->tenant_id !== $tenant->id) {
            abort(403, 'Brand does not belong to this tenant.');
        }

        $version = $this->versionService->getWorkingVersion($brand);

        if ($this->hasActiveResearchPipeline($version)) {
            return response()->json(['error' => 'Research is still processing. Please wait for it to finish.'], 422);
        }

        $this->versionService->advanceToBuild($version);

        return response()->json([
            'advanced' => true,
            'lifecycle_stage' => $version->fresh()->lifecycle_stage,
        ]);
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
}
