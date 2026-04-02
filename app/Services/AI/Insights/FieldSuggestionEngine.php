<?php

namespace App\Services\AI\Insights;

use App\Models\Asset;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Suggests new metadata fields per category from merged signals (tags + metadata + candidates).
 *
 * Uses {@see MetadataInsightsAnalyzer::mergeFieldSignals()}; skips when existing fields/options model the pattern.
 */
class FieldSuggestionEngine
{
    private const ANCHOR_MIN_RATIO = 0.6;

    private const ANCHOR_MIN_SOURCES = 2;

    /** Skip only when both field-name overlap and option overlap are high (avoids false negatives). */
    private const FIELD_OVERLAP_THRESHOLD = 0.30;

    private const OPTION_OVERLAP_THRESHOLD = 0.50;

    private const MIN_OPTION_OCCURRENCE = 2;

    public function __construct(
        protected FieldNamingService $fieldNaming,
        protected AiSuggestionSuppressionService $suppression
    ) {}

    /**
     * @return int Number of rows inserted (insert-ignore)
     */
    public function sync(int $tenantId): int
    {
        $log = Log::channel('insights');

        $minAssets = (int) config('ai_metadata_field_suggestions.min_assets', 25);
        $maxOptions = (int) config('ai_metadata_field_suggestions.max_suggested_options', 25);
        $maxAnchors = (int) config('ai_metadata_field_suggestions.max_anchors_per_category', 5);
        $stopTags = array_map('strtolower', config('ai_metadata_field_suggestions.stop_tags', []));

        $totalTenantAssets = $this->countTenantAssets($tenantId);
        if ($totalTenantAssets < 1) {
            return 0;
        }

        $analyzer = new MetadataInsightsAnalyzer;
        $mergedBySlug = $analyzer->mergeFieldSignals($tenantId);

        $existingFieldKeys = $this->applicableMetadataFieldKeysLower($tenantId);
        $existingOptionLabels = $this->selectOptionLabelsLowerForTenant($tenantId);
        $metadataFieldRows = $this->applicableMetadataFieldRows($tenantId);
        $overlapCorpus = $this->buildOverlapCorpus($metadataFieldRows, $existingOptionLabels);
        $existingFieldSuggestionKeys = $this->loadExistingFieldSuggestionKeysLower($tenantId);

        $inserted = 0;

        $categories = Category::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get(['id', 'slug', 'name']);

        $assetCountsByCategory = Asset::countNonDeletedByCategoryForTenant($tenantId);

        foreach ($categories as $category) {
            $categoryId = (int) $category->id;
            $slug = (string) $category->slug;
            if ($slug === '') {
                continue;
            }

            $totalInCategory = (int) ($assetCountsByCategory[$categoryId] ?? 0);
            if ($totalInCategory < $minAssets) {
                continue;
            }

            $block = $mergedBySlug[$slug] ?? null;
            if ($block === null || empty($block['signals'])) {
                continue;
            }

            $anchors = [];
            foreach ($block['signals'] as $signal) {
                if (($signal['field_key'] ?? null) !== null) {
                    continue;
                }

                $anchorValue = strtolower(trim((string) ($signal['value'] ?? '')));
                if ($anchorValue === '' || in_array($anchorValue, $stopTags, true)) {
                    continue;
                }

                $distinct = (int) ($signal['distinct_asset_count'] ?? 0);
                $ratio = $distinct / max($totalInCategory, 1);
                if ($ratio < self::ANCHOR_MIN_RATIO) {
                    continue;
                }

                $sources = $signal['source'] ?? [];
                if (count($sources) < self::ANCHOR_MIN_SOURCES) {
                    $log->debug('[field_suggestions] Skipped anchor: fewer than two source types', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'value' => $anchorValue,
                        'sources' => $sources,
                    ]);

                    continue;
                }

                if ($this->shouldSkipForOverlap($anchorValue, $overlapCorpus)) {
                    $log->debug('[field_suggestions] Skipped anchor: high field + option overlap with catalog', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'value' => $anchorValue,
                    ]);

                    continue;
                }

                if ($this->fieldAlreadyModelsAnchor($anchorValue, $metadataFieldRows)) {
                    $log->debug('[field_suggestions] Skipped anchor: existing field models pattern', [
                        'tenant_id' => $tenantId,
                        'value' => $anchorValue,
                    ]);

                    continue;
                }

                $anchors[] = [
                    'tag' => $anchorValue,
                    'supporting' => $distinct,
                    'ratio' => $ratio,
                    'sources' => $sources,
                    'consistency_score' => (float) ($signal['consistency_score'] ?? 1.0),
                ];
            }

            usort($anchors, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);
            $anchors = array_slice($anchors, 0, max(1, $maxAnchors));

            foreach ($anchors as $row) {
                $anchor = $row['tag'];
                $supportingCount = $row['supporting'];
                $ratio = $row['ratio'];

                $named = $this->fieldNaming->inferFieldName($anchor, $category);
                if ($named === null) {
                    $log->debug('[field_suggestions] Skipped anchor: naming layer (broad or suppressed)', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'anchor' => $anchor,
                    ]);

                    continue;
                }

                $fieldKey = $named['field_key'];
                $fieldName = $named['field_name'];

                $suppressKey = AiSuggestionSuppressionService::normalizeFieldKey($slug, $fieldKey, $anchor);
                if ($this->suppression->isSuppressed($tenantId, 'field', $suppressKey)) {
                    $log->debug('[field_suggestions] Skipped: suppressed after repeated rejections', [
                        'normalized_key' => $suppressKey,
                    ]);

                    continue;
                }

                if (isset($existingFieldKeys[strtolower($fieldKey)])) {
                    $log->debug('[field_suggestions] Skipped: field key already exists', [
                        'field_key' => $fieldKey,
                    ]);

                    continue;
                }

                if (isset($existingFieldSuggestionKeys[strtolower($fieldKey)])) {
                    $log->debug('[field_suggestions] Skipped: duplicate pending/accepted field suggestion', [
                        'field_key' => $fieldKey,
                    ]);

                    continue;
                }

                $coValues = $this->collectCoValuesFromMergedSignals(
                    $block['signals'],
                    $anchor,
                    self::MIN_OPTION_OCCURRENCE,
                    $maxOptions,
                    $stopTags
                );

                if (count($coValues) < (int) config('ai_metadata_field_suggestions.min_co_occurring_tags', 3)) {
                    $log->debug('[field_suggestions] Skipped: not enough co-occurring values', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'anchor' => $anchor,
                        'count' => count($coValues),
                    ]);

                    continue;
                }

                if ($this->optionCoverageTooHigh($coValues, $existingOptionLabels, (float) config('ai_metadata_field_suggestions.max_option_coverage_ratio', 0.45))) {
                    $log->debug('[field_suggestions] Skipped field suggestion due to high option overlap with catalog', [
                        'anchor' => $anchor,
                        'overlap_sample' => array_slice($coValues, 0, 5),
                    ]);

                    continue;
                }

                $multiSourcePresence = count(array_unique($row['sources'] ?? [])) / 3.0;
                $consistency = (float) ($row['consistency_score'] ?? 1.0);
                $confidence = (($ratio * 0.6) + ($multiSourcePresence * 0.4)) * $consistency;
                $confidence = min(1.0, $confidence);
                $priority = SuggestionPriority::score($confidence, $supportingCount);

                $n = (int) DB::table('ai_metadata_field_suggestions')->insertOrIgnore([
                    'tenant_id' => $tenantId,
                    'category_slug' => $slug,
                    'field_name' => $fieldName,
                    'field_key' => $fieldKey,
                    'suggested_options' => json_encode(array_values($coValues)),
                    'supporting_asset_count' => $supportingCount,
                    'confidence' => round($confidence, 4),
                    'priority_score' => $priority,
                    'consistency_score' => round($consistency, 4),
                    'source_cluster' => $anchor,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $inserted += $n;

                if ($n > 0) {
                    $log->debug('[field_suggestions] Inserted field suggestion', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'field_key' => $fieldKey,
                        'anchor' => $anchor,
                        'options_count' => count($coValues),
                    ]);
                }
            }
        }

        return $inserted;
    }

    /**
     * @param  array<string, true>  $optionLabelsLower
     * @return array{field_rows: \Illuminate\Support\Collection, option_strings: list<string>}
     */
    protected function buildOverlapCorpus($metadataFieldRows, array $optionLabelsLower): array
    {
        return [
            'field_rows' => $metadataFieldRows,
            'option_strings' => array_keys($optionLabelsLower),
        ];
    }

    /**
     * @return array{0: float, 1: float} field overlap ratio, option overlap ratio
     */
    protected function overlapRatios(string $anchorLower, array $corpus): array
    {
        if (strlen($anchorLower) < 3) {
            return [0.0, 0.0];
        }

        $fieldChecks = [];
        foreach ($corpus['field_rows'] ?? [] as $f) {
            $fieldChecks[] = strtolower((string) $f->key);
            $fieldChecks[] = strtolower((string) ($f->system_label ?? ''));
        }
        $fieldChecks = array_values(array_filter($fieldChecks, fn ($s) => $s !== ''));

        $optionChecks = array_values(array_filter($corpus['option_strings'] ?? [], fn ($s) => (string) $s !== ''));

        $fieldHits = 0;
        foreach ($fieldChecks as $c) {
            if ($c !== '' && str_contains($c, $anchorLower)) {
                $fieldHits++;
            }
        }
        $optionHits = 0;
        foreach ($optionChecks as $c) {
            if ($c !== '' && str_contains((string) $c, $anchorLower)) {
                $optionHits++;
            }
        }

        $fieldRatio = count($fieldChecks) ? $fieldHits / count($fieldChecks) : 0.0;
        $optionRatio = count($optionChecks) ? $optionHits / count($optionChecks) : 0.0;

        return [$fieldRatio, $optionRatio];
    }

    /**
     * Skip when both field overlap and option overlap exceed thresholds (single-axis overlap allowed).
     */
    protected function shouldSkipForOverlap(string $anchorLower, array $corpus): bool
    {
        [$fr, $or] = $this->overlapRatios(strtolower(trim($anchorLower)), $corpus);

        return $fr > self::FIELD_OVERLAP_THRESHOLD && $or > self::OPTION_OVERLAP_THRESHOLD;
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @param  list<string>  $stopTags
     * @return list<string>
     */
    protected function collectCoValuesFromMergedSignals(
        array $signals,
        string $anchorLower,
        int $minOccurrence,
        int $maxOptions,
        array $stopTags
    ): array {
        $scores = [];
        foreach ($signals as $signal) {
            $v = strtolower(trim((string) ($signal['value'] ?? '')));
            if ($v === '' || $v === $anchorLower) {
                continue;
            }
            if (in_array($v, $stopTags, true)) {
                continue;
            }
            $d = (int) ($signal['distinct_asset_count'] ?? 0);
            if ($d < $minOccurrence) {
                continue;
            }
            $scores[$v] = ($scores[$v] ?? 0) + $d;
        }

        arsort($scores);
        $out = [];
        foreach (array_keys($scores) as $v) {
            $out[] = $v;
            if (count($out) >= $maxOptions) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array<string, true> lowercased suggested field_key
     */
    protected function loadExistingFieldSuggestionKeysLower(int $tenantId): array
    {
        $rows = DB::table('ai_metadata_field_suggestions')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'accepted'])
            ->pluck('field_key');

        $map = [];
        foreach ($rows as $k) {
            $map[strtolower((string) $k)] = true;
        }

        return $map;
    }

    /**
     * @return array<string, true> lowercased keys for system + tenant fields
     */
    protected function applicableMetadataFieldKeysLower(int $tenantId): array
    {
        $rows = DB::table('metadata_fields')
            ->whereNull('deprecated_at')
            ->where(function ($q) use ($tenantId) {
                $q->where(function ($q2) use ($tenantId) {
                    $q2->where('scope', 'tenant')
                        ->where('tenant_id', $tenantId);
                })->orWhere(function ($q2) {
                    $q2->where('scope', 'system')
                        ->whereNull('tenant_id');
                });
            })
            ->pluck('key');

        $map = [];
        foreach ($rows as $k) {
            $map[strtolower((string) $k)] = true;
        }

        return $map;
    }

    /**
     * Lowercased option labels + values for select/multiselect fields applicable to this tenant.
     *
     * @return array<string, true>
     */
    protected function selectOptionLabelsLowerForTenant(int $tenantId): array
    {
        $rows = DB::table('metadata_options')
            ->join('metadata_fields', 'metadata_options.metadata_field_id', '=', 'metadata_fields.id')
            ->whereNull('metadata_fields.deprecated_at')
            ->whereIn('metadata_fields.type', ['select', 'multiselect'])
            ->where(function ($q) use ($tenantId) {
                $q->where(function ($q2) use ($tenantId) {
                    $q2->where('metadata_fields.scope', 'tenant')
                        ->where('metadata_fields.tenant_id', $tenantId);
                })->orWhere(function ($q2) {
                    $q2->where('metadata_fields.scope', 'system')
                        ->whereNull('metadata_fields.tenant_id');
                });
            })
            ->select(['metadata_options.system_label', 'metadata_options.value'])
            ->get();

        $set = [];
        foreach ($rows as $row) {
            foreach (['system_label', 'value'] as $col) {
                $v = $row->{$col} ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $set[strtolower(trim((string) $v))] = true;
            }
        }

        return $set;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{key: string, system_label: string|null}>
     */
    protected function applicableMetadataFieldRows(int $tenantId)
    {
        return DB::table('metadata_fields')
            ->whereNull('deprecated_at')
            ->where(function ($q) use ($tenantId) {
                $q->where(function ($q2) use ($tenantId) {
                    $q2->where('scope', 'tenant')
                        ->where('tenant_id', $tenantId);
                })->orWhere(function ($q2) {
                    $q2->where('scope', 'system')
                        ->whereNull('tenant_id');
                });
            })
            ->select(['key', 'system_label'])
            ->get();
    }

    /**
     * True if an existing field key/label clearly corresponds to this anchor (do not duplicate).
     *
     * @param  \Illuminate\Support\Collection<int, object{key: string, system_label: string|null}>  $fields
     */
    protected function fieldAlreadyModelsAnchor(string $anchorTag, $fields): bool
    {
        $anchorLower = strtolower(trim($anchorTag));
        $anchorSlug = Str::slug($anchorTag, '_');

        foreach ($fields as $f) {
            $keyLower = strtolower((string) $f->key);
            $labelLower = strtolower((string) ($f->system_label ?? ''));

            if ($keyLower === $anchorSlug || str_contains($keyLower, $anchorSlug)) {
                return true;
            }
            if ($anchorLower !== '' && str_contains($labelLower, $anchorLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $coTags
     * @param  array<string, true>  $optionLabelsLower
     */
    protected function optionCoverageTooHigh(array $coTags, array $optionLabelsLower, float $maxCoverageRatio): bool
    {
        if ($coTags === []) {
            return true;
        }

        $hit = 0;
        foreach ($coTags as $t) {
            $l = strtolower(trim($t));
            if ($l !== '' && isset($optionLabelsLower[$l])) {
                $hit++;
            }
        }

        return ($hit / count($coTags)) > $maxCoverageRatio;
    }

    protected function countTenantAssets(int $tenantId): int
    {
        return (int) DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->count();
    }

}
