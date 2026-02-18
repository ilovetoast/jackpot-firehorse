<?php

namespace App\Services\BrandDNA;

use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\AssetEmbedding;
use App\Models\Brand;
use App\Models\BrandComplianceScore;
use App\Models\BrandVisualReference;
use App\Services\ActivityRecorder;
use App\Services\AnalysisStatusLogger;
use App\Services\AssetCompletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Brand Compliance Service — deterministic scoring against Brand DNA rules.
 * No AI. Compares asset metadata to scoring_rules from active BrandModelVersion.
 *
 * evaluation_status semantics:
 * - pending_processing: AI pipeline not finished (analysis_status not complete)
 * - incomplete: AI finished but insufficient brand data (e.g. malformed dominant colors)
 * - evaluated: Fully scored
 * - not_configured: Brand DNA missing
 * - not_applicable: Asset excluded
 */
class BrandComplianceService
{
    public function __construct(
        private BrandModelService $brandModelService,
        private AssetCompletionService $completionService
    ) {}

    /**
     * Score an asset against the brand's DNA rules.
     * Returns null if brand model is disabled or no active version.
     *
     * Evaluation status rules:
     * - CASE 0: Asset processing not complete → upsert pending_processing, return null
     * - CASE 1: Brand DNA disabled → return null, no row
     * - CASE 2: No scoring dimensions configured → upsert not_applicable, return null
     * - CASE 3: Rules configured but metadata missing → upsert incomplete, return null
     * - CASE 4: At least one dimension scored → upsert evaluated, return result
     *
     * @return array{overall_score: int, color_score: int, typography_score: int, tone_score: int, imagery_score: int, breakdown_payload: array}|null
     */
    public function scoreAsset(Asset $asset, Brand $brand): ?array
    {
        $startTime = microtime(true);

        if ($asset->brand_id !== $brand->id) {
            return null;
        }

        $brandModel = $brand->brandModel;
        if (! $brandModel || ! $brandModel->is_enabled) {
            return null;
        }

        $activeVersion = $brandModel->activeVersion;
        if (! $activeVersion) {
            return null;
        }

        $analysisStatus = $asset->analysis_status ?? 'uploading';

        // pending_processing: AI pipeline not finished
        // incomplete: AI finished (analysis_status=complete) but insufficient/corrupt brand data
        $evaluationStatusForNotReady = $analysisStatus === 'complete' ? 'incomplete' : 'pending_processing';
        $reasonForNotReady = $analysisStatus === 'complete'
            ? 'Visual analysis incomplete (data missing or corrupt after pipeline completed)'
            : 'Visual processing incomplete';

        // Centralized readiness gate: only allow scoring when analysis_status is 'scoring' or 'complete'.
        if (! in_array($analysisStatus, ['scoring', 'complete'], true)) {
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => 0,
                'typography_score' => 0,
                'tone_score' => 0,
                'imagery_score' => 0,
                'breakdown_payload' => [
                    'color' => ['score' => null, 'weight' => 0, 'reason' => 'Visual processing incomplete', 'status' => 'pending_processing'],
                    'typography' => ['score' => null, 'weight' => 0, 'reason' => 'Visual processing incomplete', 'status' => 'pending_processing'],
                    'tone' => ['score' => null, 'weight' => 0, 'reason' => 'Visual processing incomplete', 'status' => 'pending_processing'],
                    'imagery' => ['score' => null, 'weight' => 0, 'reason' => 'Visual processing incomplete', 'status' => 'pending_processing'],
                ],
                'evaluation_status' => 'pending_processing',
            ]);

            return null;
        }

        // STEP 1: Asset processing guard — do not score before processing is complete
        if (! $this->completionService->isComplete($asset)) {
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => 0,
                'typography_score' => 0,
                'tone_score' => 0,
                'imagery_score' => 0,
                'breakdown_payload' => [
                    'color' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $evaluationStatusForNotReady],
                    'typography' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $evaluationStatusForNotReady],
                    'tone' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $evaluationStatusForNotReady],
                    'imagery' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $evaluationStatusForNotReady],
                ],
                'evaluation_status' => $evaluationStatusForNotReady,
            ]);

            return null;
        }

        // STEP 2: Image analysis guard — image assets require dominant colors, hue group, embedding
        // If we fail here: incomplete (rules exist but data insufficient to evaluate)
        if (! $this->isImageAnalysisReady($asset)) {
            $statusForImageGuard = in_array($analysisStatus, ['scoring', 'complete'], true)
                ? 'incomplete'
                : $evaluationStatusForNotReady;
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => 0,
                'typography_score' => 0,
                'tone_score' => 0,
                'imagery_score' => 0,
                'breakdown_payload' => [
                    'color' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $statusForImageGuard],
                    'typography' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $statusForImageGuard],
                    'tone' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $statusForImageGuard],
                    'imagery' => ['score' => null, 'weight' => 0, 'reason' => $reasonForNotReady, 'status' => $statusForImageGuard],
                ],
                'evaluation_status' => $statusForImageGuard,
            ]);

            return null;
        }

        $payload = $activeVersion->model_payload ?? [];
        if (! is_array($payload)) {
            $payload = [];
        }
        $scoringRules = $payload['scoring_rules'] ?? [];
        $scoringConfig = $payload['scoring_config'] ?? [];
        $weights = [
            'color' => (float) ($scoringConfig['color_weight'] ?? 0.1),
            'typography' => (float) ($scoringConfig['typography_weight'] ?? 0.2),
            'tone' => (float) ($scoringConfig['tone_weight'] ?? 0.2),
            'imagery' => (float) ($scoringConfig['imagery_weight'] ?? 0.5),
        ];
        $applicableDimensions = $this->getApplicableDimensions($asset, $brand);

        // Wrap each dimension in try-catch so a failure in one never aborts scoring.
        // Dominant color (and other metadata) can be null, missing, or malformed.
        $colorResult = $this->safeScoreDimension(fn () => $this->scoreColor($asset, $scoringRules), 'color');
        $typographyResult = $this->safeScoreDimension(fn () => $this->scoreTypography($asset, $scoringRules), 'typography');
        $toneResult = $this->safeScoreDimension(fn () => $this->scoreTone($asset, $scoringRules), 'tone');
        // Imagery: category-aware — Photography uses embedding similarity; Graphics uses heuristic
        $imageryResultWithStrategy = $this->safeScoreImageryContextual(
            $asset,
            $brand,
            $scoringRules,
            $colorResult,
            $typographyResult,
            $applicableDimensions
        );
        $imageryResult = array_slice($imageryResultWithStrategy, 0, 3);
        $imageryStrategy = $imageryResultWithStrategy[3] ?? null;

        $applicable = [];
        $breakdown = [];
        $hasAnyRules = false;
        $hasRulesButNotEvaluated = false;

        foreach ([
            'color' => [$colorResult, $weights['color']],
            'typography' => [$typographyResult, $weights['typography']],
            'tone' => [$toneResult, $weights['tone']],
            'imagery' => [$imageryResult, $weights['imagery']],
        ] as $key => [$res, $weight]) {
            [$score, $reason, $status] = $res;
            $breakdown[$key] = ['score' => $score, 'weight' => $weight, 'reason' => $reason, 'status' => $status];
            if ($key === 'imagery' && $imageryStrategy !== null) {
                $breakdown[$key]['imagery_strategy_used'] = $imageryStrategy;
            }
            // Only include in weighted average if dimension is applicable AND scored
            if ($applicableDimensions[$key] && $status === 'scored') {
                $applicable[] = ['score' => $score, 'weight' => $weight];
            }
            if ($status !== 'not_configured') {
                $hasAnyRules = true;
            }
            if ($status === 'not_evaluated') {
                $hasRulesButNotEvaluated = true;
            }
        }

        // CASE 2: No scoring dimensions configured
        if (! $hasAnyRules) {
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => $breakdown['color']['score'],
                'typography_score' => $breakdown['typography']['score'],
                'tone_score' => $breakdown['tone']['score'],
                'imagery_score' => $breakdown['imagery']['score'],
                'breakdown_payload' => $breakdown,
                'evaluation_status' => 'not_applicable',
                'applicable_dimensions' => $applicableDimensions,
                'total_weight_used' => 0.0,
            ]);
            $this->logComplianceTimelineEvent($asset, EventType::ASSET_BRAND_COMPLIANCE_NOT_APPLICABLE, [
                'evaluation_status' => 'not_applicable',
            ]);

            return null;
        }

        // CASE 3: Rules configured but required metadata missing (no dimension scored)
        if (empty($applicable) && $hasRulesButNotEvaluated) {
            $totalWeightUsedForIncomplete = array_sum(array_column($applicable, 'weight'));
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => $breakdown['color']['score'],
                'typography_score' => $breakdown['typography']['score'],
                'tone_score' => $breakdown['tone']['score'],
                'imagery_score' => $breakdown['imagery']['score'],
                'breakdown_payload' => $breakdown,
                'evaluation_status' => 'incomplete',
                'applicable_dimensions' => $applicableDimensions,
                'total_weight_used' => $totalWeightUsedForIncomplete,
            ]);
            $this->logComplianceTimelineEvent($asset, EventType::ASSET_BRAND_COMPLIANCE_INCOMPLETE, [
                'evaluation_status' => 'incomplete',
            ]);

            return null;
        }

        // CASE 4: At least one dimension successfully scored (context-aware: only applicable dimensions)
        $totalWeightUsed = array_sum(array_column($applicable, 'weight'));
        $weightedSum = 0;
        foreach ($applicable as $a) {
            $w = $totalWeightUsed > 0 ? $a['weight'] / $totalWeightUsed : 0;
            $weightedSum += $a['score'] * $w;
        }
        $visualScore = (int) round($weightedSum);
        $visualScore = min(100, max(0, $visualScore));

        // Governance Boost: additive human signals (starred, quality rating, approved)
        // Scale down when visual score is low to avoid inflating obviously off-brand content
        $governanceBoost = 0;
        $metadata = $asset->metadata ?? [];
        if (filter_var($metadata['starred'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
            $governanceBoost += 5;
        }
        if ((int) ($metadata['quality_rating'] ?? 0) >= 4) {
            $governanceBoost += 8;
        }
        if ($asset->approved_at !== null) {
            $governanceBoost += 15;
        }

        $boostMultiplier = $visualScore >= 50 ? 1.0 : 0.5;
        $governanceBoost = (int) round($governanceBoost * $boostMultiplier);

        $boostedScore = min(100, $visualScore + $governanceBoost);

        // Nonlinear score curve: exponent 1.25 spreads midrange values
        $normalized = $boostedScore / 100;
        $curved = pow($normalized, 1.25);
        $finalScore = (int) round($curved * 100);
        $overallScore = max(0, min(100, $finalScore));

        // Alignment confidence: result metadata (high/medium/low) — not a readiness check.
        // Scoring assumes analysis-ready; this only classifies confidence in the result.
        $referenceCount = BrandVisualReference::where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->whereIn('type', BrandVisualReference::IMAGERY_TYPES)
            ->count();
        $assetEmbedding = AssetEmbedding::where('asset_id', $asset->id)->first();
        $hasEmbedding = $assetEmbedding !== null && ! empty($assetEmbedding->embedding_vector ?? []);
        $hasColorData = $this->hasDominantColors($asset);

        if ($referenceCount >= 6 && $hasEmbedding && $hasColorData) {
            $confidence = 'high';
        } elseif ($referenceCount >= 3 && $hasEmbedding) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        $result = [
            'overall_score' => $overallScore,
            'alignment_confidence' => $confidence,
            'color_score' => $breakdown['color']['score'],
            'typography_score' => $breakdown['typography']['score'],
            'tone_score' => $breakdown['tone']['score'],
            'imagery_score' => $breakdown['imagery']['score'],
            'breakdown_payload' => $breakdown,
            'evaluation_status' => 'evaluated',
            'applicable_dimensions' => $applicableDimensions,
            'total_weight_used' => $totalWeightUsed,
        ];

        $this->upsertScore($asset, $brand, $result);

        // Snapshot consistency assertion: if analysis_status will be complete, debug_snapshot must have has_embedding
        $snapshot = $this->buildDebugSnapshot($asset, $result);
        if ($snapshot['has_embedding'] === false && str_starts_with($asset->mime_type ?? '', 'image/')) {
            Log::critical('[BrandComplianceService] State inconsistency: evaluated image asset has no embedding', [
                'asset_id' => $asset->id,
                'analysis_status' => $asset->analysis_status,
                'debug_snapshot' => $snapshot,
            ]);
        }

        // 5. When scoring finishes successfully: set analysis_status = 'complete'
        // Guard: only mutate when in expected previous state
        $currentStatus = $asset->analysis_status ?? 'uploading';
        if ($currentStatus !== 'scoring') {
            Log::warning('[BrandComplianceService] Invalid analysis_status transition aborted', [
                'asset_id' => $asset->id,
                'expected' => 'scoring',
                'actual' => $currentStatus,
            ]);
            return $result;
        }
        $asset->update(['analysis_status' => 'complete']);
        AnalysisStatusLogger::log($asset, 'scoring', 'complete', 'BrandComplianceService');

        $duration = microtime(true) - $startTime;
        if ($duration > 2.0) {
            Log::warning('[BrandComplianceService] Scoring duration exceeded 2s', [
                'asset_id' => $asset->id,
                'duration_seconds' => round($duration, 3),
            ]);
        }
        $this->logComplianceTimelineEvent($asset, EventType::ASSET_BRAND_COMPLIANCE_EVALUATED, [
            'overall_score' => $overallScore,
            'evaluation_status' => 'evaluated',
        ]);

        return $result;
    }

    /**
     * Context-aware dimension applicability. Returns which dimensions apply to this asset/brand.
     *
     * COLOR: Applicable if dominant colors exist.
     * IMAGERY: Applicable if (Photography category AND brand has visual references)
     *          OR (Graphics category AND color dimension applicable).
     * TYPOGRAPHY: Applicable if asset contains extracted text OR category in [Graphics, Print, Packaging].
     * TONE: Applicable if extracted text exists.
     *
     * @return array{color: bool, imagery: bool, typography: bool, tone: bool}
     */
    protected function getApplicableDimensions(Asset $asset, Brand $brand): array
    {
        $hasDominantColors = $this->hasDominantColors($asset);
        $extractedText = trim($this->getAssetTextForTone($asset));
        $hasExtractedText = $extractedText !== '';

        $category = $asset->category;
        $categoryName = $category?->name ?? '';
        $categorySlug = $category?->slug ?? '';
        $isPhotography = in_array($categoryName, ['Photography'], true)
            || in_array($categorySlug, ['photography'], true);
        $isGraphics = in_array($categoryName, ['Graphics'], true)
            || in_array($categorySlug, ['graphics'], true);
        $isGraphicsPrintPackaging = in_array($categoryName, ['Graphics', 'Print', 'Packaging'], true)
            || in_array($categorySlug, ['graphics', 'print', 'packaging'], true);

        $hasVisualRefs = $this->brandHasVisualReferencesWithEmbeddings($brand);

        $imageryApplicable = ($isPhotography && $hasVisualRefs)
            || ($isGraphics && $hasDominantColors);

        return [
            'color' => $hasDominantColors,
            'imagery' => $imageryApplicable,
            'typography' => $hasExtractedText || $isGraphicsPrintPackaging,
            'tone' => $hasExtractedText,
        ];
    }

    /**
     * Score color using top 5 dominant colors from asset metadata (not bucket).
     * Uses LAB ΔE perceptual matching: close colors score high even without exact hex match.
     *
     * @return array{0: int, 1: string, 2: string} [score, reason, status]
     */
    protected function scoreColor(Asset $asset, array $rules): array
    {
        $allowed = $rules['allowed_color_palette'] ?? [];
        $banned = $rules['banned_colors'] ?? [];

        $allowedHexes = collect($allowed)
            ->map(fn ($c) => is_array($c) ? ($c['hex'] ?? null) : $c)
            ->filter()
            ->map(fn ($h) => $this->normalizeHex((string) $h))
            ->filter()
            ->values()
            ->all();

        $bannedHexes = collect($banned)
            ->map(fn ($c) => is_array($c) ? ($c['hex'] ?? null) : $c)
            ->filter()
            ->map(fn ($h) => $this->normalizeHex((string) $h))
            ->filter()
            ->values()
            ->all();

        if (empty($allowedHexes) && empty($bannedHexes)) {
            return [0, 'No color rules configured.', 'not_configured'];
        }

        $dominantColors = $this->getAssetDominantColors($asset);
        if (empty($dominantColors)) {
            return [0, 'No dominant color data available.', 'not_evaluated'];
        }

        // Banned: strict hex match (explicit exclusion)
        foreach ($dominantColors as $color) {
            $hex = is_array($color) ? ($color['hex'] ?? null) : $color;
            if (! is_string($hex) || $hex === '') {
                continue;
            }
            if (! empty($bannedHexes) && $this->hexInList($hex, $bannedHexes)) {
                return [0, "Dominant color {$hex} is in banned colors list.", 'scored'];
            }
        }

        // Allowed: LAB ΔE perceptual matching with weighted score
        if (empty($allowedHexes)) {
            return [0, 'No allowed color palette configured.', 'not_configured'];
        }

        $allowedLabs = [];
        foreach ($allowedHexes as $h) {
            $allowedLabs[] = $this->hexToLab($h);
        }

        $weightedSum = 0.0;
        $totalWeight = 0.0;
        $bestDeltaE = null;
        $bestHex = null;

        foreach ($dominantColors as $color) {
            $hex = is_array($color) ? ($color['hex'] ?? null) : $color;
            $weight = is_array($color) ? (float) ($color['weight'] ?? 1) : 1;
            if (! is_string($hex) || $hex === '') {
                continue;
            }

            $dominantLab = $this->hexToLab($hex);
            $minDeltaE = null;
            foreach ($allowedLabs as $paletteLab) {
                $d = $this->deltaE($dominantLab, $paletteLab);
                if ($minDeltaE === null || $d < $minDeltaE) {
                    $minDeltaE = $d;
                }
            }

            if ($minDeltaE !== null) {
                if ($bestDeltaE === null || $minDeltaE < $bestDeltaE) {
                    $bestDeltaE = $minDeltaE;
                    $bestHex = $hex;
                }
                $score = $this->deltaEToScore($minDeltaE);
                $weightedSum += $score * $weight;
                $totalWeight += $weight;
            }
        }

        if ($totalWeight <= 0) {
            return [0, 'Dominant colors not found in allowed palette.', 'scored'];
        }

        $finalScore = (int) round($weightedSum / $totalWeight);
        $finalScore = min(100, max(0, $finalScore));

        $reason = $bestDeltaE !== null && $bestDeltaE < 10
            ? "Dominant color {$bestHex} matches allowed palette (ΔE={$bestDeltaE})."
            : "Dominant colors scored by perceptual distance (best ΔE=" . round($bestDeltaE ?? 999, 1) . ').';

        return [$finalScore, $reason, 'scored'];
    }

    /**
     * Normalize hex string for comparison (#RRGGBB, uppercase).
     */
    protected function normalizeHex(string $hex): ?string
    {
        $hex = strtoupper(trim(str_replace(' ', '', $hex)));
        if ($hex === '') {
            return null;
        }
        if ($hex[0] !== '#') {
            $hex = '#' . $hex;
        }
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return null;
        }

        return '#' . $hex;
    }

    /**
     * Check if hex matches any in a list of normalized hex strings (for banned colors).
     */
    protected function hexInList(string $hex, array $normalizedHexList): bool
    {
        $normalized = $this->normalizeHex($hex);
        if ($normalized === null) {
            return false;
        }

        return in_array($normalized, $normalizedHexList, true);
    }

    /**
     * Safely run imagery contextual scorer. Returns [score, reason, status, strategy].
     * Strategy is 'photography_similarity' | 'graphics_heuristic' | null when not scored.
     */
    protected function safeScoreImageryContextual(
        Asset $asset,
        Brand $brand,
        array $scoringRules,
        array $colorResult,
        array $typographyResult,
        array $applicableDimensions
    ): array {
        try {
            return $this->scoreImageryContextual(
                $asset,
                $brand,
                $scoringRules,
                $colorResult,
                $typographyResult,
                $applicableDimensions
            );
        } catch (\Throwable $e) {
            Log::warning('[BrandComplianceService] Imagery dimension failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [0, "Imagery failed: {$e->getMessage()}", 'not_evaluated', null];
        }
    }

    /**
     * Category-aware imagery scoring. Photography: embedding similarity. Graphics: color+typography heuristic.
     * Returns [score, reason, status, imagery_strategy_used].
     */
    protected function scoreImageryContextual(
        Asset $asset,
        Brand $brand,
        array $scoringRules,
        array $colorResult,
        array $typographyResult,
        array $applicableDimensions
    ): array {
        $category = $asset->category;
        $categoryName = $category?->name ?? '';
        $categorySlug = $category?->slug ?? '';
        $isPhotography = in_array($categoryName, ['Photography'], true)
            || in_array($categorySlug, ['photography'], true);
        $isGraphics = in_array($categoryName, ['Graphics'], true)
            || in_array($categorySlug, ['graphics'], true);
        $hasVisualRefs = $this->brandHasVisualReferencesWithEmbeddings($brand);

        if ($isPhotography && $hasVisualRefs) {
            [$score, $reason, $status] = $this->scoreImagerySimilarity($asset, $brand);

            return [$score, $reason, $status, 'photography_similarity'];
        }

        // Guard: Graphics with no dominant colors → not applicable (absence of metadata must never equal punishment)
        if ($isGraphics && ! $this->hasDominantColors($asset)) {
            return [0, 'Graphics imagery requires dominant colors; missing metadata.', 'not_configured', null];
        }

        if ($isGraphics && $applicableDimensions['color']) {
            $result = $this->scoreImageryGraphicsHeuristic(
                $asset,
                $scoringRules,
                $colorResult,
                $typographyResult,
                $applicableDimensions
            );

            return [...$result, 'graphics_heuristic'];
        }

        return [0, 'Imagery not applicable for this category/asset.', 'not_configured', null];
    }

    /**
     * Graphics imagery heuristic: nuanced base from color score + typography match (+15) + logo placeholder (+15).
     * Base: colorScore >= 80 → 75, >= 60 → 65, >= 40 → 50, else → 0.
     * Score 0 only when color is banned or colorScore < 40.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected function scoreImageryGraphicsHeuristic(
        Asset $asset,
        array $rules,
        array $colorResult,
        array $typographyResult,
        array $applicableDimensions
    ): array {
        $allowed = $rules['allowed_color_palette'] ?? [];
        $banned = $rules['banned_colors'] ?? [];
        if (empty($allowed) && empty($banned)) {
            return [0, 'No brand color references for Graphics imagery.', 'not_configured'];
        }

        $dominantColors = $this->getAssetDominantColors($asset);
        if (empty($dominantColors)) {
            return [0, 'No dominant colors for Graphics imagery.', 'not_evaluated'];
        }

        // Color banned → imagery 0
        $bannedHexes = collect($banned)
            ->map(fn ($c) => is_array($c) ? ($c['hex'] ?? null) : $c)
            ->filter()
            ->map(fn ($h) => $this->normalizeHex((string) $h))
            ->filter()
            ->values()
            ->all();
        foreach ($dominantColors as $color) {
            $hex = is_array($color) ? ($color['hex'] ?? null) : $color;
            if (is_string($hex) && $hex !== '' && ! empty($bannedHexes) && $this->hexInList($hex, $bannedHexes)) {
                return [0, "Dominant color {$hex} is banned; Graphics imagery score 0.", 'scored'];
            }
        }

        $score = 0;
        $reasons = [];

        // Nuanced base from color dimension score (not binary)
        if (! empty($allowed)) {
            [$colorScore] = $colorResult;
            if ($colorScore >= 80) {
                $score = 75;
                $reasons[] = 'Strong color alignment (≥80)';
            } elseif ($colorScore >= 60) {
                $score = 65;
                $reasons[] = 'Color aligned (≥60)';
            } elseif ($colorScore >= 40) {
                $score = 50;
                $reasons[] = 'Partial color alignment (≥40)';
            }
            // else: score stays 0
        }

        // +15 if typography applicable and matches
        if ($applicableDimensions['typography'] && $typographyResult[2] === 'scored' && $typographyResult[0] >= 100) {
            $score += 15;
            $reasons[] = 'Typography matches allowed fonts';
        }

        // +15 if logo detected (future placeholder — always 0 for now)
        // $score += 15 when logo detected;

        $score = min(100, $score);
        $reason = ! empty($reasons) ? implode('. ', $reasons) : 'Graphics heuristic: colors do not align with palette.';

        return [$score, $reason, 'scored'];
    }

    /**
     * Safely run a dimension scorer. On any exception, return not_evaluated so other dimensions can score.
     */
    protected function safeScoreDimension(callable $scorer, string $dimension): array
    {
        try {
            return $scorer();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("[BrandComplianceService] Dimension {$dimension} failed", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [0, "Dimension failed: {$e->getMessage()}", 'not_evaluated'];
        }
    }

    /**
     * Check if asset has dominant colors (for scoring readiness).
     */
    public function hasDominantColors(Asset $asset): bool
    {
        try {
            return count($this->getAssetDominantColors($asset)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Centralized readiness gate: image assets must not be scored until ALL required background jobs complete.
     * Non-image assets return true (bypass).
     *
     * Requirements for image assets:
     * - mime_type starts with "image/"
     * - thumbnail_status === ThumbnailStatus::COMPLETED
     * - dominant_colors present in metadata
     * - dominant_hue_group present on assets table
     * - AssetEmbedding exists with non-empty embedding_vector
     *
     * @return bool False if any requirement missing (or not an image); true if ready to score
     */
    protected function isImageAnalysisReady(Asset $asset): bool
    {
        if (! str_starts_with($asset->mime_type ?? '', 'image/')) {
            return true; // Non-image assets bypass
        }

        if ($asset->thumbnail_status !== ThumbnailStatus::COMPLETED) {
            return false;
        }

        if (! $this->hasDominantColors($asset)) {
            return false;
        }

        $dominantHueGroup = $asset->dominant_hue_group ?? null;
        if ($dominantHueGroup === null || $dominantHueGroup === '') {
            return false;
        }

        $assetEmbedding = AssetEmbedding::where('asset_id', $asset->id)->first();
        if (! $assetEmbedding || empty($assetEmbedding->embedding_vector ?? [])) {
            return false;
        }

        return true;
    }

    /**
     * Get top 5 dominant colors from asset metadata, sorted by weight/coverage descending.
     */
    protected function getAssetDominantColors(Asset $asset): array
    {
        $row = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->where('metadata_fields.key', 'dominant_colors')
            ->whereNotNull('asset_metadata.approved_at')
            ->select('asset_metadata.value_json')
            ->first();

        if (! $row || ! $row->value_json) {
            $metadata = $asset->metadata ?? [];
            $colors = $metadata['dominant_colors'] ?? null;

            return $this->normalizeAndSortDominantColors($colors);
        }

        $decoded = json_decode($row->value_json, true);
        return $this->normalizeAndSortDominantColors($decoded);
    }

    protected function normalizeAndSortDominantColors(mixed $colors): array
    {
        // Guard: dominant_colors can be null, missing, string, or not hydrated yet.
        if (! is_array($colors) || empty($colors)) {
            return [];
        }

        $withWeight = [];
        foreach ($colors as $c) {
            $hex = is_array($c) ? ($c['hex'] ?? null) : $c;
            if (! is_string($hex) || trim($hex) === '') {
                continue;
            }
            $weight = is_array($c) ? (float) ($c['coverage'] ?? $c['weight'] ?? 1) : 1;
            $withWeight[] = ['hex' => $hex, 'weight' => $weight];
        }
        usort($withWeight, fn ($a, $b) => $b['weight'] <=> $a['weight']);

        return array_slice($withWeight, 0, 5);
    }

    /**
     * Convert hex to RGB. Accepts #RRGGBB or RRGGBB.
     *
     * @return array{0: int, 1: int, 2: int} [r, g, b] 0-255
     */
    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return [0, 0, 0];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Convert sRGB to XYZ (D65 illuminant).
     *
     * @return array{0: float, 1: float, 2: float} [x, y, z]
     */
    protected function rgbToXyz(int $r, int $g, int $b): array
    {
        $rs = $r / 255.0;
        $gs = $g / 255.0;
        $bs = $b / 255.0;

        $rs = $rs <= 0.04045 ? $rs / 12.92 : pow(($rs + 0.055) / 1.055, 2.4);
        $gs = $gs <= 0.04045 ? $gs / 12.92 : pow(($gs + 0.055) / 1.055, 2.4);
        $bs = $bs <= 0.04045 ? $bs / 12.92 : pow(($bs + 0.055) / 1.055, 2.4);

        $x = $rs * 0.4124564 + $gs * 0.3575761 + $bs * 0.1804375;
        $y = $rs * 0.2126729 + $gs * 0.7151522 + $bs * 0.0721750;
        $z = $rs * 0.0193339 + $gs * 0.1191920 + $bs * 0.9503041;

        return [$x * 100, $y * 100, $z * 100];
    }

    /**
     * Convert XYZ to LAB (D65 reference white).
     *
     * @return array{0: float, 1: float, 2: float} [L, a, b]
     */
    protected function xyzToLab(float $x, float $y, float $z): array
    {
        $xn = 95.047;
        $yn = 100.000;
        $zn = 108.883;

        $fx = $x / $xn > 0.008856 ? pow($x / $xn, 1 / 3) : (7.787 * $x / $xn + 16 / 116);
        $fy = $y / $yn > 0.008856 ? pow($y / $yn, 1 / 3) : (7.787 * $y / $yn + 16 / 116);
        $fz = $z / $zn > 0.008856 ? pow($z / $zn, 1 / 3) : (7.787 * $z / $zn + 16 / 116);

        $L = 116 * $fy - 16;
        $a = 500 * ($fx - $fy);
        $b = 200 * ($fy - $fz);

        return [$L, $a, $b];
    }

    /**
     * Convert hex to LAB (D65).
     *
     * @return array{0: float, 1: float, 2: float} [L, a, b]
     */
    protected function hexToLab(string $hex): array
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        [$x, $y, $z] = $this->rgbToXyz($r, $g, $b);

        return $this->xyzToLab($x, $y, $z);
    }

    /**
     * CIE76 delta E between two LAB colors.
     */
    protected function deltaE(array $lab1, array $lab2): float
    {
        return sqrt(
            pow($lab1[0] - $lab2[0], 2) +
            pow($lab1[1] - $lab2[1], 2) +
            pow($lab1[2] - $lab2[2], 2)
        );
    }

    /**
     * Map delta E to score: ΔE < 10 → 100, < 15 → 85, < 20 → 70, < 30 → 40, else → 0.
     */
    protected function deltaEToScore(float $deltaE): int
    {
        if ($deltaE < 10) {
            return 100;
        }
        if ($deltaE < 15) {
            return 85;
        }
        if ($deltaE < 20) {
            return 70;
        }
        if ($deltaE < 30) {
            return 40;
        }

        return 0;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    protected function scoreTypography(Asset $asset, array $rules): array
    {
        $allowedFonts = $rules['allowed_fonts'] ?? [];
        if (empty($allowedFonts)) {
            return [0, 'No typography rules configured.', 'not_configured'];
        }

        $assetFont = $this->getAssetFontValue($asset);
        if (empty($assetFont)) {
            return [0, 'No font metadata found.', 'not_evaluated'];
        }

        $assetFontLower = strtolower(trim($assetFont));
        foreach ($allowedFonts as $f) {
            $fontStr = is_string($f) ? $f : ($f['name'] ?? $f['value'] ?? '');
            if ($fontStr && str_contains($assetFontLower, strtolower(trim($fontStr)))) {
                return [100, "Font \"{$assetFont}\" matches allowed fonts.", 'scored'];
            }
        }

        return [40, "Font \"{$assetFont}\" not found in allowed fonts list.", 'scored'];
    }

    protected function getAssetFontValue(Asset $asset): ?string
    {
        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->where(function ($q) {
                $q->where('metadata_fields.key', 'like', '%font%')
                    ->orWhere('metadata_fields.key', 'like', '%typography%');
            })
            ->select('asset_metadata.value_json')
            ->limit(1)
            ->get();

        foreach ($rows as $row) {
            $str = $this->extractStringFromValueJson($row->value_json);
            if ($str) {
                return $str;
            }
        }
        return null;
    }

    protected function extractStringFromValueJson(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            return is_string($decoded) ? $decoded : (is_array($decoded) ? ($decoded['value'] ?? $decoded['text'] ?? null) : null);
        }
        if (is_array($v)) {
            return $v['value'] ?? $v['text'] ?? null;
        }
        return null;
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    protected function scoreTone(Asset $asset, array $rules): array
    {
        $toneKeywords = $rules['tone_keywords'] ?? [];
        $bannedKeywords = $rules['banned_keywords'] ?? [];
        if (empty($toneKeywords) && empty($bannedKeywords)) {
            return [0, 'No tone rules configured.', 'not_configured'];
        }

        $text = $this->getAssetTextForTone($asset);
        if (empty($text)) {
            return [0, 'No text content to evaluate.', 'not_evaluated'];
        }

        $textLower = strtolower($text);
        $score = 70;
        $reasons = [];

        foreach ($bannedKeywords as $kw) {
            if (is_string($kw) && str_contains($textLower, strtolower(trim($kw)))) {
                $score -= 30;
                $reasons[] = "Contains banned keyword: \"{$kw}\"";
            }
        }
        foreach ($toneKeywords as $kw) {
            if (is_string($kw) && str_contains($textLower, strtolower(trim($kw)))) {
                $score += 10;
                $reasons[] = "Matches tone keyword: \"{$kw}\"";
            }
        }

        $score = min(100, max(0, $score));
        $reason = ! empty($reasons) ? implode('. ', $reasons) : 'No tone keywords matched.';

        return [$score, $reason, 'scored'];
    }

    protected function getAssetTextForTone(Asset $asset): string
    {
        $parts = [];
        if (! empty($asset->title)) {
            $parts[] = $asset->title;
        }

        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->whereIn('metadata_fields.type', ['text', 'textarea', 'richtext'])
            ->select('asset_metadata.value_json')
            ->get();

        foreach ($rows as $row) {
            $str = $this->extractStringFromValueJson($row->value_json);
            if ($str) {
                $parts[] = $str;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    protected function scoreImagery(Asset $asset, array $rules): array
    {
        $attrs = $rules['photography_attributes'] ?? [];
        if (empty($attrs)) {
            return [0, 'No photography rules configured.', 'not_configured'];
        }

        $assetStyle = $this->getAssetPhotographyStyle($asset);
        if (empty($assetStyle)) {
            return [0, 'No photography style metadata found.', 'not_evaluated'];
        }

        $styleLower = strtolower($assetStyle);
        foreach ($attrs as $a) {
            if (is_string($a) && str_contains($styleLower, strtolower(trim($a)))) {
                return [100, "Style \"{$assetStyle}\" matches allowed photography attributes.", 'scored'];
            }
        }

        return [50, "Style \"{$assetStyle}\" not found in allowed photography attributes.", 'scored'];
    }

    /**
     * Check if brand has visual references with embedding vectors for imagery similarity scoring.
     */
    protected function brandHasVisualReferencesWithEmbeddings(Brand $brand): bool
    {
        return BrandVisualReference::where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->whereIn('type', BrandVisualReference::IMAGERY_TYPES)
            ->exists();
    }

    /**
     * Normalize vector to unit length (L2 norm).
     */
    private function normalizeVector(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));
        if ($norm < 1e-10) {
            return $v;
        }

        return array_map(fn ($x) => $x / $norm, $v);
    }

    /**
     * Cosine similarity between two vectors. Uses standard dot product formula.
     * Returns value in [-1, 1]. Assumes vectors are normalized.
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (empty($a) || empty($b) || count($a) !== count($b)) {
            return 0.0;
        }
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        foreach ($a as $i => $v) {
            $w = $b[$i] ?? 0;
            $dot += $v * $w;
            $normA += $v * $v;
            $normB += $w * $w;
        }
        $denom = sqrt($normA) * sqrt($normB);
        if ($denom < 1e-10) {
            return 0.0;
        }

        return (float) ($dot / $denom);
    }

    /**
     * Score imagery using centroid-based similarity against brand reference embeddings.
     * Compares asset to the centroid (average) of all brand visual references.
     * When no references configured, returns not_configured (caller should use scoreImagery instead).
     *
     * Assumes analysis-ready for image assets (isImageAnalysisReady gate guarantees embedding).
     * For non-image assets when brand has visual refs, embedding may be absent → not_evaluated.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected function scoreImagerySimilarity(Asset $asset, Brand $brand): array
    {
        $refs = BrandVisualReference::where('brand_id', $brand->id)
            ->whereNotNull('embedding_vector')
            ->whereIn('type', BrandVisualReference::IMAGERY_TYPES)
            ->get();

        if ($refs->isEmpty()) {
            return [0, 'No visual references configured.', 'not_configured'];
        }

        $assetEmbedding = AssetEmbedding::where('asset_id', $asset->id)->first();
        $assetVec = array_values($assetEmbedding?->embedding_vector ?? []);
        if (empty($assetVec)) {
            return [0, 'Asset embedding not yet generated.', 'not_evaluated'];
        }

        $embeddings = [];
        foreach ($refs as $ref) {
            $refVec = array_values($ref->embedding_vector ?? []);
            if (! empty($refVec) && count($refVec) === count($assetVec)) {
                $embeddings[] = $refVec;
            }
        }

        if (empty($embeddings)) {
            return [0, 'No valid reference embeddings.', 'not_configured'];
        }

        // Centroid-based similarity: compute average of reference vectors
        $dimensionCount = count($embeddings[0]);
        $centroid = array_fill(0, $dimensionCount, 0);

        foreach ($embeddings as $vector) {
            for ($i = 0; $i < $dimensionCount; $i++) {
                $centroid[$i] += $vector[$i] ?? 0;
            }
        }

        for ($i = 0; $i < $dimensionCount; $i++) {
            $centroid[$i] /= count($embeddings);
        }

        $similarity = $this->cosineSimilarity($assetVec, $centroid);
        $score = (int) round(max(0.0, $similarity) * 100);
        $score = min(100, max(0, $score));

        return [$score, 'Visual similarity to brand centroid', 'scored'];
    }

    protected function getAssetPhotographyStyle(Asset $asset): ?string
    {
        $rows = DB::table('asset_metadata')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('asset_metadata.asset_id', $asset->id)
            ->whereNotNull('asset_metadata.approved_at')
            ->where(function ($q) {
                $q->where('metadata_fields.key', 'like', '%photography%')
                    ->orWhere('metadata_fields.key', 'like', '%style%')
                    ->orWhere('metadata_fields.key', 'like', '%imagery%');
            })
            ->select('asset_metadata.value_json')
            ->limit(1)
            ->get();

        foreach ($rows as $row) {
            $str = $this->extractStringFromValueJson($row->value_json);
            if ($str) {
                return $str;
            }
        }
        return null;
    }

    public function deleteScoreIfExists(Asset $asset, Brand $brand): void
    {
        BrandComplianceScore::where('brand_id', $brand->id)
            ->where('asset_id', $asset->id)
            ->delete();
    }

    /**
     * Log a brand compliance timeline event, with duplicate prevention.
     * If the latest event for this asset already has the same event_type, skip insertion.
     */
    protected function logComplianceTimelineEvent(Asset $asset, string $eventType, array $metadata): void
    {
        try {
            $latest = ActivityEvent::where('tenant_id', $asset->tenant_id)
                ->where('subject_type', Asset::class)
                ->where('subject_id', $asset->id)
                ->whereIn('event_type', [
                    EventType::ASSET_BRAND_COMPLIANCE_EVALUATED,
                    EventType::ASSET_BRAND_COMPLIANCE_INCOMPLETE,
                    EventType::ASSET_BRAND_COMPLIANCE_NOT_APPLICABLE,
                ])
                ->orderBy('created_at', 'desc')
                ->first();

            if ($latest && $latest->event_type === $eventType) {
                return;
            }

            ActivityRecorder::logAsset($asset, $eventType, $metadata);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[BrandComplianceService] Failed to log timeline event', [
                'asset_id' => $asset->id,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function upsertScore(Asset $asset, Brand $brand, array $result): void
    {
        $data = [
            'overall_score' => $result['overall_score'],
            'color_score' => $result['color_score'],
            'typography_score' => $result['typography_score'],
            'tone_score' => $result['tone_score'],
            'imagery_score' => $result['imagery_score'],
            'breakdown_payload' => $result['breakdown_payload'],
            'debug_snapshot' => $this->buildDebugSnapshot($asset, $result),
        ];
        if (isset($result['evaluation_status'])) {
            $data['evaluation_status'] = $result['evaluation_status'];
        }
        if (array_key_exists('alignment_confidence', $result)) {
            $data['alignment_confidence'] = $result['alignment_confidence'];
        }

        BrandComplianceScore::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'asset_id' => $asset->id,
            ],
            $data
        );
    }

    /**
     * Build structured debug snapshot for scoreAsset() runs.
     */
    protected function buildDebugSnapshot(Asset $asset, array $result): array
    {
        $embedding = AssetEmbedding::where('asset_id', $asset->id)->first();
        $hasEmbedding = $embedding !== null && ! empty($embedding->embedding_vector ?? []);

        $snapshot = [
            'analysis_status' => $asset->analysis_status ?? 'uploading',
            'thumbnail_status' => $asset->thumbnail_status?->value ?? null,
            'has_dominant_colors' => $this->hasDominantColors($asset),
            'has_dominant_hue_group' => ! empty($asset->dominant_hue_group ?? null),
            'has_embedding' => $hasEmbedding,
            'evaluation_status' => $result['evaluation_status'] ?? null,
            'overall_score' => $result['overall_score'] ?? null,
        ];

        if (isset($result['applicable_dimensions'])) {
            $snapshot['applicable_dimensions'] = $result['applicable_dimensions'];
        }
        if (array_key_exists('total_weight_used', $result)) {
            $snapshot['total_weight_used'] = (float) ($result['total_weight_used'] ?? 0);
        }
        $imageryStrategy = $result['breakdown_payload']['imagery']['imagery_strategy_used'] ?? null;
        if ($imageryStrategy !== null) {
            $snapshot['imagery_strategy_used'] = $imageryStrategy;
        }

        return $snapshot;
    }
}
