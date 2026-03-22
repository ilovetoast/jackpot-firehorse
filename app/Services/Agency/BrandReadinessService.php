<?php

namespace App\Services\Agency;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandReferenceAsset;
use App\Models\BrandVisualReference;
use Illuminate\Support\Facades\Schema;

/**
 * Computed brand readiness for agency dashboard (0–5 score, task-driven next steps).
 *
 * Criteria:
 * 1. Identity basics: logo + colors
 * 2. Typography (brand DNA)
 * 3. Asset library size ≥ 10
 * 4. Style references at tier 2/3 (promoted / guideline) ≥ 3
 * 5. Photography guidelines (DNA or photography visual references)
 *
 * Each readiness_tasks[] item: label, action (stable id for frontend routing), effort: low|medium|high.
 */
final class BrandReadinessService
{
    public const MIN_ASSETS = 10;

    public const MIN_TIER23_REFERENCES = 3;

    /**
     * @param  list<int>  $brandIds
     * @return array<int, array{
     *     readiness_score: int,
     *     readiness_tasks: list<array{label: string, action: string, effort: 'low'|'medium'|'high'}>,
     *     readiness_tooltip: string,
     *     reference_alert: array{current: int, min: int}|null,
     *     criteria: array{
     *         has_identity_basics: bool,
     *         has_typography: bool,
     *         has_sufficient_assets: bool,
     *         has_sufficient_references: bool,
     *         has_photography_guidelines: bool,
     *     },
     *     counts: array{assets: int, tier23_references: int},
     * }>
     */
    public function forBrandIds(array $brandIds): array
    {
        $brandIds = array_values(array_unique(array_filter($brandIds)));
        if ($brandIds === []) {
            return [];
        }

        $brands = Brand::query()
            ->whereIn('id', $brandIds)
            ->with(['brandModel.activeVersion'])
            ->get()
            ->keyBy('id');

        $assetCounts = Asset::query()
            ->whereIn('brand_id', $brandIds)
            ->whereNull('deleted_at')
            ->selectRaw('brand_id, COUNT(*) as c')
            ->groupBy('brand_id')
            ->pluck('c', 'brand_id');

        $braByBrand = [];
        if (Schema::hasTable('brand_reference_assets')) {
            $braByBrand = BrandReferenceAsset::query()
                ->whereIn('brand_id', $brandIds)
                ->whereIn('tier', [BrandReferenceAsset::TIER_REFERENCE, BrandReferenceAsset::TIER_GUIDELINE])
                ->selectRaw('brand_id, COUNT(*) as c')
                ->groupBy('brand_id')
                ->pluck('c', 'brand_id');
        }

        $bvrStyleTier23 = BrandVisualReference::query()
            ->whereIn('brand_id', $brandIds)
            ->whereIn('reference_tier', [
                BrandVisualReference::TIER_PROMOTED,
                BrandVisualReference::TIER_GUIDELINE,
            ])
            ->get(['brand_id', 'type', 'reference_type']);

        $bvrCounts = [];
        foreach ($bvrStyleTier23 as $row) {
            if (! $row->isStyleReferenceForSimilarity()) {
                continue;
            }
            $bid = (int) $row->brand_id;
            $bvrCounts[$bid] = ($bvrCounts[$bid] ?? 0) + 1;
        }

        $logoBrandIds = BrandVisualReference::query()
            ->whereIn('brand_id', $brandIds)
            ->where('type', BrandVisualReference::TYPE_LOGO)
            ->pluck('brand_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $photoRefCounts = BrandVisualReference::query()
            ->whereIn('brand_id', $brandIds)
            ->whereIn('type', [
                BrandVisualReference::TYPE_PHOTOGRAPHY_REFERENCE,
                BrandVisualReference::TYPE_LIFESTYLE_PHOTOGRAPHY,
                BrandVisualReference::TYPE_PRODUCT_PHOTOGRAPHY,
            ])
            ->selectRaw('brand_id, COUNT(*) as c')
            ->groupBy('brand_id')
            ->pluck('c', 'brand_id');

        $out = [];
        foreach ($brandIds as $bid) {
            $brand = $brands->get($bid);
            if (! $brand) {
                continue;
            }

            $assets = (int) ($assetCounts[$bid] ?? 0);
            $tier23 = (int) ($braByBrand[$bid] ?? 0) + (int) ($bvrCounts[$bid] ?? 0);

            $hasLogo = $this->brandHasLogo($brand, $logoBrandIds);
            $hasColors = $this->brandHasColors($brand);
            $hasIdentity = $hasLogo && $hasColors;

            $hasTypography = $this->brandHasTypographyDna($brand);
            $hasAssets = $assets >= self::MIN_ASSETS;
            $hasRefs = $tier23 >= self::MIN_TIER23_REFERENCES;
            $hasPhoto = $this->brandHasPhotographyGuidelines($brand, (int) ($photoRefCounts[$bid] ?? 0));

            $criteria = [
                'has_identity_basics' => $hasIdentity,
                'has_typography' => $hasTypography,
                'has_sufficient_assets' => $hasAssets,
                'has_sufficient_references' => $hasRefs,
                'has_photography_guidelines' => $hasPhoto,
            ];

            $score = count(array_filter($criteria));

            $tasks = $this->buildReadinessTasks(
                $assets,
                $tier23,
                $hasIdentity,
                $hasTypography,
                $hasAssets,
                $hasRefs,
                $hasPhoto
            );

            $referenceAlert = $tier23 < self::MIN_TIER23_REFERENCES
                ? ['current' => $tier23, 'min' => self::MIN_TIER23_REFERENCES]
                : null;

            $tooltip = sprintf(
                'Score %d/5. Identity %s · Typography %s · Assets (%d/%d) %s · References (%d/%d tier 2–3) %s · Photography %s',
                $score,
                $hasIdentity ? '✓' : '✗',
                $hasTypography ? '✓' : '✗',
                $assets,
                self::MIN_ASSETS,
                $hasAssets ? '✓' : '✗',
                $tier23,
                self::MIN_TIER23_REFERENCES,
                $hasRefs ? '✓' : '✗',
                $hasPhoto ? '✓' : '✗'
            );

            $out[$bid] = [
                'readiness_score' => $score,
                'readiness_tasks' => $tasks,
                'readiness_tooltip' => $tooltip,
                'reference_alert' => $referenceAlert,
                'criteria' => $criteria,
                'counts' => [
                    'assets' => $assets,
                    'tier23_references' => $tier23,
                ],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, action: string, effort: 'low'|'medium'|'high'}>
     */
    private function buildReadinessTasks(
        int $assets,
        int $tier23,
        bool $hasIdentity,
        bool $hasTypography,
        bool $hasAssets,
        bool $hasRefs,
        bool $hasPhoto
    ): array {
        $tasks = [];

        if (! $hasIdentity) {
            $tasks[] = [
                'label' => 'Add logo & brand colors',
                'action' => 'guidelines_identity',
                'effort' => 'medium',
            ];
        }
        if (! $hasTypography) {
            $tasks[] = [
                'label' => 'Define typography in Brand DNA',
                'action' => 'guidelines_typography',
                'effort' => 'medium',
            ];
        }
        if (! $hasAssets) {
            $gap = max(1, self::MIN_ASSETS - $assets);
            $effort = $assets === 0 ? 'high' : ($gap > 5 ? 'high' : 'low');
            $tasks[] = [
                'label' => $assets === 0
                    ? 'Upload your first assets'
                    : 'Upload '.$gap.' more asset'.($gap === 1 ? '' : 's'),
                'action' => 'assets',
                'effort' => $effort,
            ];
        }
        if (! $hasRefs) {
            $gap = max(1, self::MIN_TIER23_REFERENCES - $tier23);
            $tasks[] = [
                'label' => 'Add '.$gap.' promoted reference'.($gap === 1 ? '' : 's').' (tier 2–3)',
                'action' => 'references',
                'effort' => 'medium',
            ];
        }
        if (! $hasPhoto) {
            $tasks[] = [
                'label' => 'Add photography guidelines',
                'action' => 'guidelines_photography',
                'effort' => 'medium',
            ];
        }

        return array_slice($tasks, 0, 3);
    }

    private function brandHasLogo(Brand $brand, array $brandIdsWithBvrLogo): bool
    {
        if (filled($brand->logo_id) || filled($brand->logo_path)) {
            return true;
        }

        return in_array((int) $brand->id, $brandIdsWithBvrLogo, true);
    }

    private function brandHasColors(Brand $brand): bool
    {
        foreach (['primary_color', 'secondary_color', 'accent_color'] as $attr) {
            if (filled($brand->{$attr})) {
                return true;
            }
        }

        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];
        $colors = $visual['colors'] ?? $visual['palette'] ?? $visual['brand_colors'] ?? [];
        if (! is_array($colors)) {
            return false;
        }
        foreach ($colors as $c) {
            if (is_string($c) && trim($c) !== '') {
                return true;
            }
            if (is_array($c) && (! empty($c['hex']) || ! empty($c['value']) || ! empty($c['name']))) {
                return true;
            }
        }

        return false;
    }

    private function brandHasTypographyDna(Brand $brand): bool
    {
        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $typo = is_array($payload['typography'] ?? null) ? $payload['typography'] : [];
        if (! empty($typo['primary_font']) || ! empty($typo['secondary_font'])) {
            return true;
        }
        $fonts = $typo['fonts'] ?? [];
        if (is_array($fonts) && count(array_filter($fonts)) > 0) {
            return true;
        }
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];

        return ! empty($rules['typography_keywords']);
    }

    private function brandHasPhotographyGuidelines(Brand $brand, int $photographyVisualRefCount): bool
    {
        if ($photographyVisualRefCount > 0) {
            return true;
        }

        $brand->loadMissing('brandModel.activeVersion');
        $payload = $brand->brandModel?->activeVersion?->model_payload ?? [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];
        foreach (['photography_style', 'composition_style', 'brand_look', 'style'] as $key) {
            $v = $visual[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return true;
            }
        }
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];
        $attrs = $rules['photography_attributes'] ?? [];
        if (is_array($attrs) && count(array_filter($attrs)) > 0) {
            return true;
        }

        return false;
    }
}
