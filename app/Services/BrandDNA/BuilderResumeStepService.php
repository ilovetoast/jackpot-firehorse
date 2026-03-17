<?php

namespace App\Services\BrandDNA;

use App\BrandDNA\Builder\BrandGuidelinesBuilderSteps;
use App\Models\Brand;
use App\Models\BrandModelVersion;

/**
 * Resolves the correct builder step for resuming based on draft/version state.
 * Used by Brand Guidelines index "Continue" CTA.
 */
class BuilderResumeStepService
{
    protected const VALID_RESUME_STEPS = [
        'background',
        'processing',
        'research-summary',
        'archetype',
        'purpose_promise',
        'expression',
        'positioning',
        'standards',
    ];

    protected const STEPS_AFTER_RESEARCH = ['archetype', 'purpose_promise', 'expression', 'positioning', 'standards'];

    public function __construct(
        protected \App\Services\BrandDNA\PipelineFinalizationService $finalizationService
    ) {}

    /**
     * Resolve the resume step for the brand's current draft.
     *
     * @return array{step: string, label: string}
     */
    public function resolve(Brand $brand, ?BrandModelVersion $draft): array
    {
        if (! $draft) {
            return ['step' => 'background', 'label' => 'Start Brand Guidelines'];
        }

        $researchFinalized = $this->isResearchFinalized($brand, $draft);

        // Rule 1: Processing incomplete
        if (! $researchFinalized) {
            return ['step' => 'processing', 'label' => 'Continue Processing'];
        }

        // Rule 2: Research ready but not yet reviewed
        if (! $this->hasResearchBeenReviewed($draft)) {
            return ['step' => 'research-summary', 'label' => 'Review Research'];
        }

        // Rule 3: Resume from last meaningful builder step
        $progress = $draft->builder_progress ?? [];
        $lastVisited = $progress['last_visited_step'] ?? null;
        $lastCompleted = $progress['last_completed_step'] ?? null;

        if ($lastVisited && $this->isValidResumeStep($lastVisited)) {
            return [
                'step' => $lastVisited,
                'label' => $this->labelForStep($lastVisited),
            ];
        }

        if ($lastCompleted && $this->isValidResumeStep($lastCompleted)) {
            $nextStep = $this->nextStepAfter($lastCompleted);
            return [
                'step' => $nextStep,
                'label' => $this->labelForStep($nextStep),
            ];
        }

        // Rule 4: Safe fallback
        return ['step' => 'background', 'label' => 'Continue Builder'];
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

    protected function hasResearchBeenReviewed(BrandModelVersion $draft): bool
    {
        $progress = $draft->builder_progress ?? [];
        $lastCompleted = $progress['last_completed_step'] ?? null;

        if ($lastCompleted && in_array($lastCompleted, self::STEPS_AFTER_RESEARCH, true)) {
            return true;
        }

        $state = $draft->insightState;
        if ($state?->viewed_at) {
            return true;
        }

        return (bool) ($progress['research_reviewed_at'] ?? false);
    }

    protected function isValidResumeStep(string $step): bool
    {
        return in_array($step, self::VALID_RESUME_STEPS, true);
    }

    protected function nextStepAfter(string $step): string
    {
        $allSteps = ['background', 'processing', 'research-summary', ...BrandGuidelinesBuilderSteps::stepKeys()];
        $idx = array_search($step, $allSteps, true);
        if ($idx === false || $idx >= count($allSteps) - 1) {
            return 'background';
        }

        return $allSteps[$idx + 1];
    }

    protected function labelForStep(string $step): string
    {
        return match ($step) {
            'background' => 'Continue Builder',
            'processing' => 'Continue Processing',
            'research-summary' => 'Review Research',
            'archetype' => 'Continue Archetype',
            'purpose_promise' => 'Continue Purpose',
            'expression' => 'Continue Expression',
            'positioning' => 'Continue Positioning',
            'standards' => 'Continue Standards',
            default => 'Continue Builder',
        };
    }
}
