<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type MergedFieldSignal array{
 *   field_key: string|null,
 *   value: string,
 *   source: list<string>,
 *   metadata_count: int,
 *   candidate_count: int,
 *   tag_count: int,
 *   count: int,
 *   distinct_asset_count: int,
 *   score: float,
 *   confidence: float,
 *   consistency_score: float,
 *   distinct_upload_batches: int,
 *   total_upload_batches: int
 * }
 */

/**
 * Read-only insights over existing metadata, tags, and candidate rows.
 * Does not call AI or generate new data.
 */
class MetadataInsightsAnalyzer
{
    /**
     * Value counts per field from approved asset_metadata and from pending AI/metadata candidates.
     *
     * @return array{
     *   approved_metadata: list<array{field_key: string, values: list<array{value: string, count: int}>}>,
     *   candidates: list<array{field_key: string, values: list<array{value: string, count: int}>}>
     * }
     */
    public function analyzeFieldValuePatterns(int $tenantId): array
    {
        return [
            'approved_metadata' => $this->aggregateFieldValuesFromApprovedMetadata($tenantId),
            'candidates' => $this->aggregateFieldValuesFromCandidates($tenantId),
        ];
    }

    /**
     * Tag frequency across assets with ratio vs total tag-assignment rows in the tenant.
     *
     * @return array{tags: list<array{tag: string, count: int, distinct_assets: int, ratio: float}>, total_tag_rows: int, total_assets: int}
     */
    public function analyzeTagClusters(int $tenantId): array
    {
        $base = DB::table('asset_tags')
            ->join('assets', 'asset_tags.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at');

        $totalTagRows = (clone $base)->count();
        $totalAssets = $this->countTenantAssets($tenantId);

        $rows = (clone $base)
            ->selectRaw('asset_tags.tag as tag, COUNT(*) as cnt, COUNT(DISTINCT asset_tags.asset_id) as asset_cnt')
            ->groupBy('asset_tags.tag')
            ->orderByDesc('cnt')
            ->get();

        $tags = [];
        $denominator = max($totalTagRows, 1);
        foreach ($rows as $row) {
            $tags[] = [
                'tag' => $row->tag,
                'count' => (int) $row->cnt,
                'distinct_assets' => (int) $row->asset_cnt,
                'ratio' => round(((int) $row->cnt) / $denominator, 4),
            ];
        }

        return [
            'tags' => $tags,
            'total_tag_rows' => $totalTagRows,
            'total_assets' => $totalAssets,
        ];
    }

    /**
     * Per-field coverage: distinct assets with an approved value vs total assets in tenant.
     *
     * @return array{total_assets: int, fields: list<array{field_key: string, assets_with_value: int, coverage_ratio: float}>}
     */
    public function analyzeFieldCoverage(int $tenantId): array
    {
        $totalAssets = $this->countTenantAssets($tenantId);
        $denominator = max($totalAssets, 1);

        $rows = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('asset_metadata.approved_at')
            ->selectRaw('metadata_fields.key as field_key, COUNT(DISTINCT assets.id) as asset_count')
            ->groupBy('metadata_fields.id', 'metadata_fields.key')
            ->orderBy('metadata_fields.key')
            ->get();

        $fields = [];
        foreach ($rows as $row) {
            $with = (int) $row->asset_count;
            $fields[] = [
                'field_key' => $row->field_key,
                'assets_with_value' => $with,
                'coverage_ratio' => round($with / $denominator, 4),
            ];
        }

        return [
            'total_assets' => $totalAssets,
            'fields' => $fields,
        ];
    }

    /**
     * @return list<array{field_key: string, values: list<array{value: string, count: int}>}>
     */
    protected function aggregateFieldValuesFromApprovedMetadata(int $tenantId): array
    {
        $rows = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('asset_metadata.approved_at')
            ->selectRaw('metadata_fields.key as field_key, asset_metadata.value_json as value_json, COUNT(*) as cnt')
            ->groupBy('metadata_fields.id', 'metadata_fields.key', 'asset_metadata.value_json')
            ->orderBy('metadata_fields.key')
            ->get();

        return $this->groupValuesByFieldKey($rows);
    }

    /**
     * Pending / unresolved candidates (not resolved; not dismissed when column exists).
     *
     * @return list<array{field_key: string, values: list<array{value: string, count: int}>}>
     */
    protected function aggregateFieldValuesFromCandidates(int $tenantId): array
    {
        $q = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata_candidates.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNull('asset_metadata_candidates.resolved_at');

        if ($this->hasCandidatesDismissedAtColumn()) {
            $q->whereNull('asset_metadata_candidates.dismissed_at');
        }

        $rows = $q
            ->selectRaw('metadata_fields.key as field_key, asset_metadata_candidates.value_json as value_json, COUNT(*) as cnt')
            ->groupBy('metadata_fields.id', 'metadata_fields.key', 'asset_metadata_candidates.value_json')
            ->orderBy('metadata_fields.key')
            ->get();

        return $this->groupValuesByFieldKey($rows);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows  rows with field_key, value_json, cnt
     * @return list<array{field_key: string, values: list<array{value: string, count: int}>}>
     */
    protected function groupValuesByFieldKey($rows): array
    {
        $byField = [];
        foreach ($rows as $row) {
            $key = $row->field_key;
            $normalized = $this->normalizeValueJson($row->value_json ?? null);
            if (! isset($byField[$key])) {
                $byField[$key] = [];
            }
            if (! isset($byField[$key][$normalized])) {
                $byField[$key][$normalized] = 0;
            }
            $byField[$key][$normalized] += (int) $row->cnt;
        }

        $out = [];
        foreach ($byField as $fieldKey => $valueMap) {
            $values = [];
            foreach ($valueMap as $value => $count) {
                $values[] = ['value' => $value, 'count' => $count];
            }
            usort($values, fn ($a, $b) => $b['count'] <=> $a['count']);
            $out[] = [
                'field_key' => $fieldKey,
                'values' => $values,
            ];
        }

        usort($out, fn ($a, $b) => $a['field_key'] <=> $b['field_key']);

        return $out;
    }

    protected function normalizeValueJson(mixed $valueJson): string
    {
        if ($valueJson === null) {
            return '';
        }
        if (is_array($valueJson)) {
            $decoded = $valueJson;
        } else {
            $decoded = json_decode((string) $valueJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return trim((string) $valueJson);
            }
        }

        if (! is_array($decoded)) {
            return is_scalar($decoded) ? (string) $decoded : '';
        }

        if (array_key_exists('value', $decoded)) {
            $v = $decoded['value'];

            return is_scalar($v) ? (string) $v : json_encode($v);
        }
        if (array_key_exists('id', $decoded)) {
            return (string) $decoded['id'];
        }

        return json_encode($decoded);
    }

    protected function countTenantAssets(int $tenantId): int
    {
        return (int) DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->count();
    }

    protected function hasCandidatesDismissedAtColumn(): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        return $cache = \Illuminate\Support\Facades\Schema::hasColumn('asset_metadata_candidates', 'dismissed_at');
    }

    /**
     * Merged signals across asset_tags, approved asset_metadata, and asset_metadata_candidates.
     * Grouped by category slug; each signal includes per-source distinct asset counts and weights.
     *
     * @return array<string, array{total_assets: int, signals: list<MergedFieldSignal>}>
     */
    public function mergeFieldSignals(int $tenantId): array
    {
        $tagInferredFieldKeys = array_map('strtolower', config('ai_metadata_value_suggestions.tag_inferred_field_keys', []));

        $slugByCategoryId = $this->loadCategorySlugMap($tenantId);
        $categoryIdByAssetId = $this->loadAssetCategoryIdMap($tenantId);
        $sessionByAssetId = $this->loadAssetUploadSessionMap($tenantId);
        $distinctSessionsPerSlug = $this->countDistinctUploadSessionsPerCategorySlug(
            $categoryIdByAssetId,
            $slugByCategoryId,
            $sessionByAssetId
        );
        $fieldKeyToOptionSetLower = $this->loadFieldKeyToOptionSetLower($tenantId);

        /** @var array<string, array{md: array<string, true>, cd: array<string, true>, td: array<string, true>}> $fieldBuckets key = slug|fieldKey|normValue */
        $fieldBuckets = [];
        /** @var array<string, array{md: array<string, true>, cd: array<string, true>, td: array<string, true>}> $anchorBuckets key = slug|normValue */
        $anchorBuckets = [];

        $addBucket = function (array &$bucket, string $assetId, string $kind): void {
            if (! isset($bucket[$kind])) {
                $bucket[$kind] = [];
            }
            $bucket[$kind][$assetId] = true;
        };

        $approved = DB::table('asset_metadata')
            ->join('assets', 'asset_metadata.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNotNull('asset_metadata.approved_at')
            ->selectRaw('assets.id as asset_id, metadata_fields.key as field_key, asset_metadata.value_json as value_json')
            ->get();

        foreach ($approved as $row) {
            $assetId = (string) $row->asset_id;
            $cid = $categoryIdByAssetId[$assetId] ?? null;
            if ($cid === null) {
                continue;
            }
            $slug = $slugByCategoryId[$cid] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $norm = $this->normalizeMergedValue($row->value_json ?? null);
            if ($norm === '') {
                continue;
            }
            $fieldKey = strtolower((string) $row->field_key);
            $fk = "{$slug}|{$fieldKey}|{$norm}";
            if (! isset($fieldBuckets[$fk])) {
                $fieldBuckets[$fk] = ['md' => [], 'cd' => [], 'td' => []];
            }
            $addBucket($fieldBuckets[$fk], $assetId, 'md');

            $ak = "{$slug}|{$norm}";
            if (! isset($anchorBuckets[$ak])) {
                $anchorBuckets[$ak] = ['md' => [], 'cd' => [], 'td' => []];
            }
            $addBucket($anchorBuckets[$ak], $assetId, 'md');
        }

        $q = DB::table('asset_metadata_candidates')
            ->join('assets', 'asset_metadata_candidates.asset_id', '=', 'assets.id')
            ->join('metadata_fields', 'asset_metadata_candidates.metadata_field_id', '=', 'metadata_fields.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->whereNull('asset_metadata_candidates.resolved_at');

        if ($this->hasCandidatesDismissedAtColumn()) {
            $q->whereNull('asset_metadata_candidates.dismissed_at');
        }

        $candidates = $q->selectRaw('assets.id as asset_id, metadata_fields.key as field_key, asset_metadata_candidates.value_json as value_json')->get();

        foreach ($candidates as $row) {
            $assetId = (string) $row->asset_id;
            $cid = $categoryIdByAssetId[$assetId] ?? null;
            if ($cid === null) {
                continue;
            }
            $slug = $slugByCategoryId[$cid] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $norm = $this->normalizeMergedValue($row->value_json ?? null);
            if ($norm === '') {
                continue;
            }
            $fieldKey = strtolower((string) $row->field_key);
            $fk = "{$slug}|{$fieldKey}|{$norm}";
            if (! isset($fieldBuckets[$fk])) {
                $fieldBuckets[$fk] = ['md' => [], 'cd' => [], 'td' => []];
            }
            $addBucket($fieldBuckets[$fk], $assetId, 'cd');

            $ak = "{$slug}|{$norm}";
            if (! isset($anchorBuckets[$ak])) {
                $anchorBuckets[$ak] = ['md' => [], 'cd' => [], 'td' => []];
            }
            $addBucket($anchorBuckets[$ak], $assetId, 'cd');
        }

        $tagRows = DB::table('asset_tags')
            ->join('assets', 'asset_tags.asset_id', '=', 'assets.id')
            ->where('assets.tenant_id', $tenantId)
            ->whereNull('assets.deleted_at')
            ->selectRaw('assets.id as asset_id, asset_tags.tag as tag')
            ->get();

        foreach ($tagRows as $row) {
            $assetId = (string) $row->asset_id;
            $cid = $categoryIdByAssetId[$assetId] ?? null;
            if ($cid === null) {
                continue;
            }
            $slug = $slugByCategoryId[$cid] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $norm = $this->normalizeMergedValue($row->tag ?? null);
            if ($norm === '') {
                continue;
            }

            $ak = "{$slug}|{$norm}";
            if (! isset($anchorBuckets[$ak])) {
                $anchorBuckets[$ak] = ['md' => [], 'cd' => [], 'td' => []];
            }
            $addBucket($anchorBuckets[$ak], $assetId, 'td');

            foreach ($tagInferredFieldKeys as $inferKey) {
                $fk = "{$slug}|{$inferKey}|{$norm}";
                if (! isset($fieldBuckets[$fk])) {
                    $fieldBuckets[$fk] = ['md' => [], 'cd' => [], 'td' => []];
                }
                $addBucket($fieldBuckets[$fk], $assetId, 'td');
            }

            foreach ($this->fieldKeysMatchingTagToOptions($norm, $fieldKeyToOptionSetLower) as $inferKey) {
                $fk = "{$slug}|{$inferKey}|{$norm}";
                if (! isset($fieldBuckets[$fk])) {
                    $fieldBuckets[$fk] = ['md' => [], 'cd' => [], 'td' => []];
                }
                $addBucket($fieldBuckets[$fk], $assetId, 'td');
            }
        }

        $totalsBySlug = [];
        foreach ($categoryIdByAssetId as $cid) {
            if ($cid === null) {
                continue;
            }
            $slug = $slugByCategoryId[$cid] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $totalsBySlug[$slug] = ($totalsBySlug[$slug] ?? 0) + 1;
        }

        $weightMd = 1.0;
        $weightCd = 0.7;
        $weightTd = 0.5;

        $out = [];

        foreach ($fieldBuckets as $compoundKey => $sets) {
            [$slug, $fieldKey, $normValue] = explode('|', $compoundKey, 3);
            $signal = $this->buildMergedSignal(
                $slug,
                $normValue,
                $fieldKey,
                $sets,
                $totalsBySlug[$slug] ?? 1,
                $weightMd,
                $weightCd,
                $weightTd,
                $sessionByAssetId,
                (int) ($distinctSessionsPerSlug[$slug] ?? 1)
            );
            if (! isset($out[$slug])) {
                $out[$slug] = ['total_assets' => 0, 'signals' => []];
            }
            $out[$slug]['signals'][] = $signal;
        }

        foreach ($anchorBuckets as $compoundKey => $sets) {
            [$slug, $normValue] = explode('|', $compoundKey, 2);
            $signal = $this->buildMergedSignal(
                $slug,
                $normValue,
                null,
                $sets,
                $totalsBySlug[$slug] ?? 1,
                $weightMd,
                $weightCd,
                $weightTd,
                $sessionByAssetId,
                (int) ($distinctSessionsPerSlug[$slug] ?? 1)
            );
            if (! isset($out[$slug])) {
                $out[$slug] = ['total_assets' => 0, 'signals' => []];
            }
            $out[$slug]['signals'][] = $signal;
        }

        foreach ($out as $slug => &$block) {
            $block['total_assets'] = $totalsBySlug[$slug] ?? 0;
        }
        unset($block);

        ksort($out);

        return $out;
    }

    /**
     * @param  array{md: array<string, true>, cd: array<string, true>, td: array<string, true>}  $sets
     * @return MergedFieldSignal
     */
    protected function buildMergedSignal(
        string $_categorySlug,
        string $normValue,
        ?string $fieldKey,
        array $sets,
        int $totalAssetsInCategory,
        float $weightMd,
        float $weightCd,
        float $weightTd,
        array $sessionByAssetId,
        int $totalDistinctSessionsInCategory
    ): array {
        $md = count($sets['md'] ?? []);
        $cd = count($sets['cd'] ?? []);
        $td = count($sets['td'] ?? []);
        $union = array_unique(array_merge(
            array_keys($sets['md'] ?? []),
            array_keys($sets['cd'] ?? []),
            array_keys($sets['td'] ?? [])
        ));
        $distinct = count($union);
        $score = $md * $weightMd + $cd * $weightCd + $td * $weightTd;
        $den = max($totalAssetsInCategory, 1);
        $confidence = ($md * $weightMd + $cd * $weightCd + $td * $weightTd) / $den;

        $batchIds = [];
        foreach ($union as $assetId) {
            $sid = $sessionByAssetId[$assetId] ?? null;
            if ($sid !== null && $sid !== '') {
                $batchIds[(string) $sid] = true;
            }
        }
        $distinctBatches = count($batchIds);
        $totalBatches = max(1, $totalDistinctSessionsInCategory);
        if ($distinctBatches === 0) {
            $consistency = 1.0;
        } else {
            $consistency = min(1.0, $distinctBatches / $totalBatches);
        }

        $sources = [];
        if ($td > 0) {
            $sources[] = 'tag';
        }
        if ($md > 0) {
            $sources[] = 'metadata';
        }
        if ($cd > 0) {
            $sources[] = 'candidate';
        }

        return [
            'field_key' => $fieldKey,
            'value' => $normValue,
            'source' => $sources,
            'metadata_count' => $md,
            'candidate_count' => $cd,
            'tag_count' => $td,
            'count' => $distinct,
            'distinct_asset_count' => $distinct,
            'score' => round($score, 4),
            'confidence' => round(min(1.0, $confidence), 4),
            'consistency_score' => round($consistency, 4),
            'distinct_upload_batches' => $distinctBatches,
            'total_upload_batches' => $totalBatches,
        ];
    }

    /**
     * @return array<string, string|null> asset_id string => upload_session_id or null
     */
    protected function loadAssetUploadSessionMap(int $tenantId): array
    {
        $rows = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select('id', 'upload_session_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $sid = $row->upload_session_id;
            $map[(string) $row->id] = $sid !== null && $sid !== '' ? (string) $sid : null;
        }

        return $map;
    }

    /**
     * Distinct upload sessions per category slug (denominator for consistency).
     *
     * @param  array<string, int|null>  $categoryIdByAssetId
     * @param  array<int, string>  $slugByCategoryId
     * @param  array<string, string|null>  $sessionByAssetId
     * @return array<string, int>
     */
    protected function countDistinctUploadSessionsPerCategorySlug(
        array $categoryIdByAssetId,
        array $slugByCategoryId,
        array $sessionByAssetId
    ): array {
        $bySlug = [];
        foreach ($categoryIdByAssetId as $assetId => $cid) {
            if ($cid === null) {
                continue;
            }
            $slug = $slugByCategoryId[$cid] ?? null;
            if ($slug === null || $slug === '') {
                continue;
            }
            $sid = $sessionByAssetId[(string) $assetId] ?? null;
            if ($sid === null || $sid === '') {
                continue;
            }
            if (! isset($bySlug[$slug])) {
                $bySlug[$slug] = [];
            }
            $bySlug[$slug][$sid] = true;
        }

        $out = [];
        foreach ($bySlug as $slug => $set) {
            $out[$slug] = count($set);
        }

        return $out;
    }

    /**
     * Select/multiselect option labels/values per field key (lowercase) for tag → field inference.
     *
     * @return array<string, array<string, true>>
     */
    protected function loadFieldKeyToOptionSetLower(int $tenantId): array
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
            ->whereRaw("COALESCE(metadata_fields.population_mode, 'manual') != ?", ['automatic'])
            ->select([
                'metadata_fields.key as field_key',
                'metadata_options.value',
                'metadata_options.system_label',
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $fk = strtolower((string) $row->field_key);
            if (! isset($map[$fk])) {
                $map[$fk] = [];
            }
            foreach (['value', 'system_label'] as $col) {
                $v = strtolower(trim((string) ($row->{$col} ?? '')));
                if ($v !== '') {
                    $map[$fk][$v] = true;
                }
            }
        }

        return $map;
    }

    /**
     * When config mapping is empty, still attach tags to fields whose option catalog matches the tag.
     *
     * @param  array<string, array<string, true>>  $fieldKeyToOptionSetLower
     * @return list<string> field keys (lowercase)
     */
    protected function fieldKeysMatchingTagToOptions(string $normTag, array $fieldKeyToOptionSetLower): array
    {
        if ($normTag === '' || strlen($normTag) < 2) {
            return [];
        }

        $minOptInTag = max(3, (int) config('ai_metadata_value_suggestions.min_option_length_for_tag_substring_match', 6));

        $matches = [];
        foreach ($fieldKeyToOptionSetLower as $fkLower => $optionSet) {
            if (isset($optionSet[$normTag])) {
                $matches[] = $fkLower;

                continue;
            }
            foreach (array_keys($optionSet) as $opt) {
                if (strlen($opt) < 3 || strlen($normTag) < 3) {
                    continue;
                }
                // Option text contains the full tag (unusual but valid).
                if (str_contains($opt, $normTag)) {
                    $matches[] = $fkLower;
                    break;
                }
                // Tag contains option: only for longer option tokens so short catalog values
                // (e.g. resolution_class "high", "low") do not match inside unrelated tag slugs.
                if (strlen($opt) >= $minOptInTag && str_contains($normTag, $opt)) {
                    $matches[] = $fkLower;
                    break;
                }
            }
        }

        return array_values(array_unique($matches));
    }

    /**
     * @return array<int, string>
     */
    protected function loadCategorySlugMap(int $tenantId): array
    {
        $rows = DB::table('categories')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select('id', 'slug')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = (string) $row->slug;
        }

        return $map;
    }

    /**
     * @return array<string, int|null>
     */
    protected function loadAssetCategoryIdMap(int $tenantId): array
    {
        $rows = DB::table('assets')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select('id', 'metadata')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
            $cid = is_array($meta) ? ($meta['category_id'] ?? null) : null;
            if ($cid === null || $cid === '') {
                $map[(string) $row->id] = null;
            } else {
                $map[(string) $row->id] = (int) $cid;
            }
        }

        return $map;
    }

    /**
     * Lowercase, trim, collapse JSON {value, id} to scalar string.
     */
    protected function normalizeMergedValue(mixed $valueJson): string
    {
        if ($valueJson === null) {
            return '';
        }
        if (is_array($valueJson)) {
            $decoded = $valueJson;
        } else {
            $decoded = json_decode((string) $valueJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return strtolower(trim((string) $valueJson));
            }
        }

        if (! is_array($decoded)) {
            return strtolower(trim((string) (is_scalar($decoded) ? $decoded : '')));
        }

        if (array_key_exists('value', $decoded)) {
            $v = $decoded['value'];

            return strtolower(trim(is_scalar($v) ? (string) $v : json_encode($v)));
        }
        if (array_key_exists('id', $decoded)) {
            return strtolower(trim((string) $decoded['id']));
        }

        return strtolower(trim(json_encode($decoded)));
    }
}
