<?php

namespace App\Services\BrandDNA;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Models\Brand;
use App\Models\BrandModelVersion;

/**
 * Resolves the correct page/step for resuming based on draft lifecycle_stage.
 * Used by Brand Guidelines index "Continue" CTA.
 *
 * Primary signal: lifecycle_stage (research | review | build | published).
 * Fallback: builder_progress for backward compatibility.
 */
class BuilderResumeStepService
{
    protected const BUILDER_STEPS = ['archetype', 'purpose_promise', 'expression', 'positioning', 'standards'];

    public function __construct(
        protected PipelineFinalizationService $finalizationService
    ) {}

    /**
     * Resolve the resume destination for a brand's current draft.
     *
     * @return array{step: string, label: string, route: string, route_params: array}
     */
    public function resolve(Brand $brand, ?BrandModelVersion $draft): array
    {
        if (! $draft) {
            return [
                'step' => 'research',
                'label' => 'Start Brand Guidelines',
                'route' => 'brands.research.show',
                'route_params' => ['brand' => $brand->id],
            ];
        }

        $stage = $draft->lifecycle_stage ?? BrandModelVersion::LIFECYCLE_RESEARCH;

        // Route by lifecycle stage
        if ($stage === BrandModelVersion::LIFECYCLE_RESEARCH) {
            return [
                'step' => 'research',
                'label' => $draft->research_status === BrandModelVersion::RESEARCH_RUNNING ? 'Continue Processing' : 'Continue Research',
                'route' => 'brands.research.show',
                'route_params' => ['brand' => $brand->id],
            ];
        }

        if ($stage === BrandModelVersion::LIFECYCLE_REVIEW) {
            return [
                'step' => 'review',
                'label' => 'Review Research',
                'route' => 'brands.review.show',
                'route_params' => ['brand' => $brand->id],
            ];
        }

        if ($stage === BrandModelVersion::LIFECYCLE_BUILD || $stage === BrandModelVersion::LIFECYCLE_PUBLISHED) {
            $builderStep = $this->resolveBuilderStep($draft);

            return [
                'step' => $builderStep,
                'label' => $this->labelForStep($builderStep),
                'route' => 'brands.brand-guidelines.builder',
                'route_params' => ['brand' => $brand->id, 'step' => $builderStep],
            ];
        }

        // Fallback for legacy rows without lifecycle_stage
        return $this->resolveLegacy($brand, $draft);
    }

    protected function resolveBuilderStep(BrandModelVersion $draft): string
    {
        $progress = $draft->builder_progress ?? [];
        $lastVisited = $progress['last_visited_step'] ?? null;
        $lastCompleted = $progress['last_completed_step'] ?? null;

        if ($lastVisited && in_array($lastVisited, self::BUILDER_STEPS, true)) {
            return $lastVisited;
        }

        if ($lastCompleted && in_array($lastCompleted, self::BUILDER_STEPS, true)) {
            return $this->nextBuilderStepAfter($lastCompleted);
        }

        return BrandGuidelinesBuilderSteps::STEP_ARCHETYPE;
    }

    /**
     * Legacy fallback for versions that don't yet have lifecycle_stage set.
     */
    protected function resolveLegacy(Brand $brand, BrandModelVersion $draft): array
    {
        $researchFinalized = $this->isResearchFinalized($brand, $draft);

        if (! $researchFinalized) {
            return [
                'step' => 'research',
                'label' => 'Continue Processing',
                'route' => 'brands.research.show',
                'route_params' => ['brand' => $brand->id],
            ];
        }

        $progress = $draft->builder_progress ?? [];
        $lastVisited = $progress['last_visited_step'] ?? null;

        if ($lastVisited && in_array($lastVisited, self::BUILDER_STEPS, true)) {
            return [
                'step' => $lastVisited,
                'label' => $this->labelForStep($lastVisited),
                'route' => 'brands.brand-guidelines.builder',
                'route_params' => ['brand' => $brand->id, 'step' => $lastVisited],
            ];
        }

        return [
            'step' => 'review',
            'label' => 'Review Research',
            'route' => 'brands.review.show',
            'route_params' => ['brand' => $brand->id],
        ];
    }

    protected function isResearchFinalized(Brand $brand, BrandModelVersion $draft): bool
    {
        $guidelinesPdfAsset = $draft->assetsForContext('guidelines_pdf')->first();
        $sources = $draft->model_payload['sources'] ?? [];
        $hasWebsiteUrl = ! empty(trim((string) ($sources['website_url'] ?? '')));
        $hasSocialUrls = ! empty($sources['social_urls'] ?? []);
        $brandMaterialCount = $draft->assetsForContext('brand_material')->count();

        $finalization = $this->finalizationService->compute(
            $brand->id,
            $draft->id,
            $guidelinesPdfAsset,
            $hasWebsiteUrl,
            $hasSocialUrls,
            $brandMaterialCount
        );

        return (bool) ($finalization['research_finalized'] ?? false);
    }

    protected function nextBuilderStepAfter(string $step): string
    {
        $idx = array_search($step, self::BUILDER_STEPS, true);
        if ($idx === false || $idx >= count(self::BUILDER_STEPS) - 1) {
            return self::BUILDER_STEPS[0];
        }

        return self::BUILDER_STEPS[$idx + 1];
    }

    protected function labelForStep(string $step): string
    {
        return match ($step) {
            'research' => 'Continue Research',
            'review' => 'Review Research',
            'archetype' => 'Continue Archetype',
            'purpose_promise' => 'Continue Purpose',
            'expression' => 'Continue Expression',
            'positioning' => 'Continue Positioning',
            'standards' => 'Continue Standards',
            default => 'Continue Builder',
        };
    }
}
