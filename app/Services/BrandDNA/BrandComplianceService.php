<?php

namespace App\Services\BrandDNA;

use App\Models\Asset;
use App\Models\Brand;
use App\Models\BrandComplianceScore;
use Illuminate\Support\Facades\DB;

/**
 * Brand Compliance Service — deterministic scoring against Brand DNA rules.
 * No AI. Compares asset metadata to scoring_rules from active BrandModelVersion.
 */
class BrandComplianceService
{
    public function __construct(
        private BrandModelService $brandModelService
    ) {}

    /**
     * Score an asset against the brand's DNA rules.
     * Returns null if brand model is disabled or no active version.
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

        $payload = $activeVersion->model_payload ?? [];
        $scoringRules = $payload['scoring_rules'] ?? [];
        $scoringConfig = $payload['scoring_config'] ?? [];
        $colorWeight = (float) ($scoringConfig['color_weight'] ?? 0.3);
        $typographyWeight = (float) ($scoringConfig['typography_weight'] ?? 0.2);
        $toneWeight = (float) ($scoringConfig['tone_weight'] ?? 0.3);
        $imageryWeight = (float) ($scoringConfig['imagery_weight'] ?? 0.2);

        $colorResult = $this->scoreColor($asset, $scoringRules);
        $typographyResult = $this->scoreTypography($asset, $scoringRules);
        $toneResult = $this->scoreTone($asset, $scoringRules);
        $imageryResult = $this->scoreImagery($asset, $scoringRules);

        $applicable = [];
        $breakdown = [];

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
        }

        if (empty($applicable)) {
            $this->deleteScoreIfExists($asset, $brand);

            return null;
        }

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
        ];

        $this->upsertScore($asset, $brand, $result);

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
     * Check if asset has dominant colors (for scoring readiness).
     */
    public function hasDominantColors(Asset $asset): bool
    {
        return count($this->getAssetDominantColors($asset)) > 0;
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
            $colors = $metadata['dominant_colors'] ?? [];

            return $this->normalizeAndSortDominantColors($colors);
        }

        $decoded = json_decode($row->value_json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return $this->normalizeAndSortDominantColors($decoded);
    }

    protected function normalizeAndSortDominantColors(array $colors): array
    {
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

    protected function upsertScore(Asset $asset, Brand $brand, array $result): void
    {
        BrandComplianceScore::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'asset_id' => $asset->id,
            ],
            [
                'overall_score' => $result['overall_score'],
                'color_score' => $result['color_score'],
                'typography_score' => $result['typography_score'],
                'tone_score' => $result['tone_score'],
                'imagery_score' => $result['imagery_score'],
                'breakdown_payload' => $result['breakdown_payload'],
            ]
        );
    }
}
