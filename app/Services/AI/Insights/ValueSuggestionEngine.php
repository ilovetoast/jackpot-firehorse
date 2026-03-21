<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Proposes new metadata option values from merged signals (tags + approved metadata + candidates).
 * Does not attach values to assets; inserts rows into ai_metadata_value_suggestions only.
 */
class ValueSuggestionEngine
{
    private const MIN_SCORE = 8.0;

    private const MIN_DISTINCT_ASSETS = 5;

    public function __construct(
        protected AiSuggestionSuppressionService $suppression
    ) {}

    /**
     * Populate ai_metadata_value_suggestions for a tenant. New rows only (insert-ignore on unique key).
     *
     * @return int Number of rows inserted
     */
    public function sync(int $tenantId): int
    {
        $log = Log::channel('insights');

        $analyzer = new MetadataInsightsAnalyzer;
        $mergedBySlug = $analyzer->mergeFieldSignals($tenantId);

        $fields = $this->loadFieldsWithOptions($tenantId);
        if ($fields->isEmpty()) {
            $log->debug('[value_suggestions] No select/multiselect fields; skipping', ['tenant_id' => $tenantId]);

            return 0;
        }

        $fieldByKeyLower = $fields->keyBy(fn ($f) => strtolower((string) $f->key));
        $fieldIds = $fields->pluck('id')->all();
        $optionValuesByFieldId = $this->loadOptionValueSets($fieldIds);
        $existingSuggestionKeys = $this->loadExistingValueSuggestionKeysLower($tenantId);

        $inserted = 0;

        foreach ($mergedBySlug as $slug => $block) {
            $totalInCategory = (int) ($block['total_assets'] ?? 0);
            foreach ($block['signals'] ?? [] as $signal) {
                $fieldKeyRaw = $signal['field_key'] ?? null;
                if ($fieldKeyRaw === null || $fieldKeyRaw === '') {
                    continue;
                }

                $fieldKeyLower = strtolower((string) $fieldKeyRaw);
                if (! isset($fieldByKeyLower[$fieldKeyLower])) {
                    continue;
                }

                $field = $fieldByKeyLower[$fieldKeyLower];
                $fieldId = (int) $field->id;
                $optionSet = $optionValuesByFieldId[$fieldId] ?? [];

                $canonical = $this->normalizeSuggestedValue((string) ($signal['value'] ?? ''));
                if ($canonical === '') {
                    continue;
                }

                if ($this->valueInOptionSet($canonical, $optionSet)) {
                    $log->debug('[value_suggestions] Skipped: value already in metadata_options', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'field_key' => $field->key,
                        'value' => $canonical,
                    ]);

                    continue;
                }

                if ($this->isDuplicateSuggestion($existingSuggestionKeys, (string) $field->key, $canonical)) {
                    $log->debug('[value_suggestions] Skipped: duplicate pending/accepted suggestion', [
                        'tenant_id' => $tenantId,
                        'field_key' => $field->key,
                        'value' => $canonical,
                    ]);

                    continue;
                }

                $suppressKey = AiSuggestionSuppressionService::normalizeValueKey((string) $field->key, $canonical);
                if ($this->suppression->isSuppressed($tenantId, 'value', $suppressKey)) {
                    $log->debug('[value_suggestions] Skipped: suppressed after repeated rejections', [
                        'normalized_key' => $suppressKey,
                    ]);

                    continue;
                }

                $score = (float) ($signal['score'] ?? 0);
                $distinct = (int) ($signal['distinct_asset_count'] ?? 0);

                if ($score < self::MIN_SCORE) {
                    $log->debug('[value_suggestions] Skipped: score below threshold', [
                        'tenant_id' => $tenantId,
                        'field_key' => $field->key,
                        'value' => $canonical,
                        'score' => $score,
                        'min' => self::MIN_SCORE,
                    ]);

                    continue;
                }

                if ($distinct < self::MIN_DISTINCT_ASSETS) {
                    $log->debug('[value_suggestions] Skipped: distinct assets below threshold', [
                        'tenant_id' => $tenantId,
                        'field_key' => $field->key,
                        'value' => $canonical,
                        'distinct' => $distinct,
                        'min' => self::MIN_DISTINCT_ASSETS,
                    ]);

                    continue;
                }

                $md = (int) ($signal['metadata_count'] ?? 0);
                $cd = (int) ($signal['candidate_count'] ?? 0);
                $td = (int) ($signal['tag_count'] ?? 0);
                $baseConfidence = ($md * 1.0 + $cd * 0.7 + $td * 0.5) / max($totalInCategory, 1);
                $baseConfidence = min(1.0, $baseConfidence);
                $consistency = (float) ($signal['consistency_score'] ?? 1.0);
                $confidence = min(1.0, $baseConfidence * $consistency);
                $priority = SuggestionPriority::score($confidence, $distinct);

                $n = $this->insertIgnoreRow(
                    $tenantId,
                    (string) $field->key,
                    $canonical,
                    $distinct,
                    $confidence,
                    $priority,
                    $consistency,
                    'merged_signals'
                );
                $inserted += $n;

                if ($n > 0) {
                    $log->debug('[value_suggestions] Inserted suggestion', [
                        'tenant_id' => $tenantId,
                        'category_slug' => $slug,
                        'field_key' => $field->key,
                        'value' => $canonical,
                        'supporting_asset_count' => $distinct,
                        'score' => $score,
                        'sources' => $signal['source'] ?? [],
                    ]);
                }
            }
        }

        return $inserted;
    }

    /**
     * @return array<string, array<string, true>> field_key lower -> set of value lower
     */
    protected function loadExistingValueSuggestionKeysLower(int $tenantId): array
    {
        $rows = DB::table('ai_metadata_value_suggestions')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'accepted'])
            ->select('field_key', 'suggested_value')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $fk = strtolower((string) $row->field_key);
            if (! isset($map[$fk])) {
                $map[$fk] = [];
            }
            $map[$fk][strtolower(trim((string) $row->suggested_value))] = true;
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, true>>  $existingSuggestionKeys
     */
    protected function isDuplicateSuggestion(array $existingSuggestionKeys, string $fieldKey, string $canonicalLower): bool
    {
        $fk = strtolower($fieldKey);

        return isset($existingSuggestionKeys[$fk][$canonicalLower]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object{id: int, key: string}>
     */
    protected function loadFieldsWithOptions(int $tenantId)
    {
        return DB::table('metadata_fields')
            ->join('metadata_options', 'metadata_fields.id', '=', 'metadata_options.metadata_field_id')
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
            ->select('metadata_fields.id', 'metadata_fields.key')
            ->groupBy('metadata_fields.id', 'metadata_fields.key')
            ->orderBy('metadata_fields.key')
            ->get();
    }

    /**
     * @param  list<int>  $fieldIds
     * @return array<int, list<string>> Lowercased option values per field id
     */
    protected function loadOptionValueSets(array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }

        $rows = DB::table('metadata_options')
            ->whereIn('metadata_field_id', $fieldIds)
            ->select('metadata_field_id', 'value')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $fid = (int) $row->metadata_field_id;
            if (! isset($map[$fid])) {
                $map[$fid] = [];
            }
            $map[$fid][] = strtolower(trim((string) $row->value));
        }

        return $map;
    }

    /**
     * @param  list<string>  $optionSetLower
     */
    protected function valueInOptionSet(string $rawOrNormalized, array $optionSetLower): bool
    {
        $v = strtolower(trim($rawOrNormalized));

        return in_array($v, $optionSetLower, true);
    }

    protected function insertIgnoreRow(
        int $tenantId,
        string $fieldKey,
        string $suggestedValue,
        int $supportingAssetCount,
        float $confidence,
        float $priorityScore,
        float $consistencyScore,
        string $source
    ): int {
        $now = now();

        return (int) DB::table('ai_metadata_value_suggestions')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'field_key' => $fieldKey,
            'suggested_value' => $suggestedValue,
            'supporting_asset_count' => $supportingAssetCount,
            'confidence' => round($confidence, 4),
            'priority_score' => round($priorityScore, 4),
            'consistency_score' => round($consistencyScore, 4),
            'source' => $source,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function normalizeSuggestedValue(string $value): string
    {
        return strtolower(trim($value));
    }
}
