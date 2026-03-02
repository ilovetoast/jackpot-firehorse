<?php

namespace App\Services\BrandDNA;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandModelVersion;

/**
 * Validates a draft BrandModelVersion before publish.
 * Returns list of missing required fields per v1 minimum requirements.
 */
class BrandGuidelinesPublishValidator
{
    public const CONTEXT_GUIDELINE_SOURCE = 'guideline_source';

    /**
     * Validate draft and brand. Returns empty array if valid; otherwise list of missing field descriptions.
     *
     * @return array<string>
     */
    public function validate(BrandModelVersion $draft, Brand $brand): array
    {
        $missing = [];
        $payload = $draft->model_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }

        // 1. Background: website_url OR >= 1 social url OR >= 1 guideline_source asset
        if (! $this->hasBackgroundSource($payload, $brand)) {
            $missing[] = 'Background: Provide website URL, at least one social URL, or upload a communication example';
        }

        // 2. identity.mission (WHY) — Purpose step
        $mission = $payload['identity']['mission'] ?? null;
        if (empty(trim((string) $mission))) {
            $missing[] = 'Purpose: Mission (WHY) is required';
        }

        // 3. identity.positioning (WHAT) — Purpose step
        $positioning = $payload['identity']['positioning'] ?? null;
        if (empty(trim((string) $positioning))) {
            $missing[] = 'Purpose: Positioning statement (WHAT) is required';
        }

        // 4. personality.primary_archetype OR candidate_archetypes length >= 1
        $primary = $payload['personality']['primary_archetype'] ?? null;
        $candidates = $payload['personality']['candidate_archetypes'] ?? [];
        if (empty(trim((string) $primary)) && (! is_array($candidates) || count($candidates) < 1)) {
            $missing[] = 'Archetype: Select a primary archetype or at least one candidate';
        }

        // 5. At least ONE of: brand.primary_color, typography.primary_font, visual.approved_references >= 1
        if (! $this->hasVisualStandard($payload, $brand)) {
            $missing[] = 'Standards: Add at least one of: brand primary color, typography primary font, or a photography reference';
        }

        return $missing;
    }

    protected function hasBackgroundSource(array $payload, Brand $brand): bool
    {
        $sources = $payload['sources'] ?? [];
        $websiteUrl = trim((string) ($sources['website_url'] ?? ''));
        if ($websiteUrl !== '') {
            return true;
        }

        $socialUrls = $sources['social_urls'] ?? [];
        if (is_array($socialUrls) && count($socialUrls) >= 1) {
            $nonEmpty = array_filter($socialUrls, fn ($u) => trim((string) $u) !== '');
            if (count($nonEmpty) >= 1) {
                return true;
            }
        }

        $count = Asset::where('brand_id', $brand->id)
            ->where('builder_staged', true)
            ->where('builder_context', self::CONTEXT_GUIDELINE_SOURCE)
            ->count();

        return $count >= 1;
    }

    protected function hasVisualStandard(array $payload, Brand $brand): bool
    {
        if (! empty(trim((string) ($brand->primary_color ?? '')))) {
            return true;
        }

        $primaryFont = $payload['typography']['primary_font'] ?? null;
        if (! empty(trim((string) $primaryFont))) {
            return true;
        }

        $refs = $payload['visual']['approved_references'] ?? [];
        if (is_array($refs) && count($refs) >= 1) {
            return true;
        }

        return false;
    }
}
