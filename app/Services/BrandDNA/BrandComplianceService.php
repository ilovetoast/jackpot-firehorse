<?php

namespace App\Services\BrandDNA;

use App\Enums\EventType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandComplianceScore;
use App\Services\ActivityRecorder;
use App\Services\AssetCompletionService;
use Illuminate\Support\Facades\DB;

/**
 * Brand Compliance Service — deterministic scoring against Brand DNA rules.
 * No AI. Compares asset metadata to scoring_rules from active BrandModelVersion.
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

        // STEP 1: Asset processing guard — do not score before processing is complete
        if (! $this->completionService->isComplete($asset)) {
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => null,
                'typography_score' => null,
                'tone_score' => null,
                'imagery_score' => null,
                'breakdown_payload' => [
                    'color' => ['score' => null, 'weight' => 0, 'reason' => 'Processing incomplete', 'status' => 'pending_processing'],
                    'typography' => ['score' => null, 'weight' => 0, 'reason' => 'Processing incomplete', 'status' => 'pending_processing'],
                    'tone' => ['score' => null, 'weight' => 0, 'reason' => 'Processing incomplete', 'status' => 'pending_processing'],
                    'imagery' => ['score' => null, 'weight' => 0, 'reason' => 'Processing incomplete', 'status' => 'pending_processing'],
                ],
                'evaluation_status' => 'pending_processing',
            ]);

            return null;
        }

        $payload = $activeVersion->model_payload ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];
        $scoringConfig = $payload['scoring_config'] ?? [];
        $colorWeight = (float) ($scoringConfig['color_weight'] ?? 0.3);
        $typographyWeight = (float) ($scoringConfig['typography_weight'] ?? 0.2);
        $toneWeight = (float) ($scoringConfig['tone_weight'] ?? 0.3);
        $imageryWeight = (float) ($scoringConfig['imagery_weight'] ?? 0.2);

        // Wrap each dimension in try-catch so a failure in one never aborts scoring.
        // Dominant color (and other metadata) can be null, missing, or malformed.
        $colorResult = $this->safeScoreDimension(fn () => $this->scoreColor($asset, $scoringRules), 'color');
        $typographyResult = $this->safeScoreDimension(fn () => $this->scoreTypography($asset, $scoringRules), 'typography');
        $toneResult = $this->safeScoreDimension(fn () => $this->scoreTone($asset, $scoringRules), 'tone');
        $imageryResult = $this->safeScoreDimension(fn () => $this->scoreImagery($asset, $scoringRules), 'imagery');

        $applicable = [];
        $breakdown = [];
        $hasAnyRules = false;
        $hasRulesButNotEvaluated = false;

        foreach ([
            'color' => [$colorResult, $colorWeight],
            'typography' => [$typographyResult, $typographyWeight],
            'tone' => [$toneResult, $toneWeight],
            'imagery' => [$imageryResult, $imageryWeight],
        ] as $key => [$res, $weight]) {
            [$score, $reason, $status] = $res;
            $breakdown[$key] = ['score' => $score, 'weight' => $weight, 'reason' => $reason, 'status' => $status];
            if ($status === 'scored') {
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
            ]);
            $this->logComplianceTimelineEvent($asset, EventType::ASSET_BRAND_COMPLIANCE_NOT_APPLICABLE, [
                'evaluation_status' => 'not_applicable',
            ]);

            return null;
        }

        // CASE 3: Rules configured but required metadata missing (no dimension scored)
        if (empty($applicable) && $hasRulesButNotEvaluated) {
            $this->upsertScore($asset, $brand, [
                'overall_score' => null,
                'color_score' => $breakdown['color']['score'],
                'typography_score' => $breakdown['typography']['score'],
                'tone_score' => $breakdown['tone']['score'],
                'imagery_score' => $breakdown['imagery']['score'],
                'breakdown_payload' => $breakdown,
                'evaluation_status' => 'incomplete',
            ]);
            $this->logComplianceTimelineEvent($asset, EventType::ASSET_BRAND_COMPLIANCE_INCOMPLETE, [
                'evaluation_status' => 'incomplete',
            ]);

            return null;
        }

        // CASE 4: At least one dimension successfully scored
        $totalWeight = array_sum(array_column($applicable, 'weight'));
        $weightedSum = 0;
        foreach ($applicable as $a) {
            $w = $totalWeight > 0 ? $a['weight'] / $totalWeight : 0;
            $weightedSum += $a['score'] * $w;
        }
        $overallScore = (int) round($weightedSum);
        $overallScore = min(100, max(0, $overallScore));

        $result = [
            'overall_score' => $overallScore,
            'color_score' => $breakdown['color']['score'],
            'typography_score' => $breakdown['typography']['score'],
            'tone_score' => $breakdown['tone']['score'],
            'imagery_score' => $breakdown['imagery']['score'],
            'breakdown_payload' => $breakdown,
            'evaluation_status' => 'evaluated',
        ];

        $this->upsertScore($asset, $brand, $result);
        $this->logComplianceTimelineEvent($asset, EventType::ASSET_BRAND_COMPLIANCE_EVALUATED, [
            'overall_score' => $overallScore,
            'evaluation_status' => 'evaluated',
        ]);

        return $result;
    }

    /**
     * Score color using top 5 dominant colors from asset metadata (not bucket).
     * Compares hex values directly. Case insensitive, accepts #hex or hex.
     *
     * @return array{0: int, 1: string, 2: string} [score, reason, status]
     */
    protected function scoreColor(Asset $asset, array $rules): array
    {
        $allowed = $rules['allowed_color_palette'] ?? [];
        $banned = $rules['banned_colors'] ?? [];

        if (empty($allowed) && empty($banned)) {
            return [0, 'No color rules configured.', 'not_configured'];
        }

        $dominantColors = $this->getAssetDominantColors($asset);
        if (empty($dominantColors)) {
            return [0, 'No dominant color data available.', 'not_evaluated'];
        }

        foreach ($dominantColors as $color) {
            $hex = is_array($color) ? ($color['hex'] ?? null) : $color;
            if (! is_string($hex) || $hex === '') {
                continue;
            }
            if (! empty($banned) && $this->hexMatches($hex, $banned)) {
                return [0, "Dominant color {$hex} is in banned colors list.", 'scored'];
            }
        }

        foreach ($dominantColors as $color) {
            $hex = is_array($color) ? ($color['hex'] ?? null) : $color;
            if (! is_string($hex) || $hex === '') {
                continue;
            }
            if (! empty($allowed) && $this->hexMatches($hex, $allowed)) {
                return [100, "Dominant color {$hex} matches allowed palette.", 'scored'];
            }
        }

        $firstHex = is_array($dominantColors[0]) ? ($dominantColors[0]['hex'] ?? '') : $dominantColors[0];

        return [0, "Dominant colors not found in allowed palette.", 'scored'];
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

        return array_slice(array_map(fn ($x) => $x['hex'], $withWeight), 0, 5);
    }

    protected function hexMatches(string $hex, array $list): bool
    {
        $normalized = strtolower(trim(str_replace(' ', '', $hex)));
        if ($normalized !== '' && $normalized[0] !== '#') {
            $normalized = '#' . $normalized;
        }
        foreach ($list as $c) {
            $itemHex = $c;
            if (is_array($c) && isset($c['hex'])) {
                $itemHex = $c['hex'];
            }
            if (! is_string($itemHex)) {
                continue;
            }
            $itemNorm = strtolower(trim(str_replace(' ', '', $itemHex)));
            if ($itemNorm !== '' && $itemNorm[0] !== '#') {
                $itemNorm = '#' . $itemNorm;
            }
            if ($itemNorm === $normalized) {
                return true;
            }
        }

        return false;
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
        ];
        if (isset($result['evaluation_status'])) {
            $data['evaluation_status'] = $result['evaluation_status'];
        }

        BrandComplianceScore::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'asset_id' => $asset->id,
            ],
            $data
        );
    }
}
