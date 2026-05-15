<?php

namespace App\Services\Hygiene;

use App\Models\MetadataField;
use App\Models\MetadataOption;
use App\Models\MetadataValueMerge;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Phase 5.3 — non-destructive metadata-value merge.
 *
 * Rewrites every `asset_metadata.value_json` row that holds the merge's
 * `from` value to hold `to` instead, scoped to a single tenant + field.
 * Multiselect arrays are deduped after substitution. The original asset
 * payloads are preserved (no row deletes); only `value_json` changes.
 *
 * Side effects in order:
 *   1. (optional) Record an alias `from → to` so future writes / read paths
 *      know the historical mapping. Uses MetadataCanonicalizationService so
 *      its loop guards still apply. Failures here do NOT abort the merge —
 *      the alias is metadata, the data rewrite is the contract.
 *   2. Rewrite asset_metadata rows. Capped per call so a giant tenant
 *      can't lock the table.
 *   3. (optional) Remove the now-unused MetadataOption row for `from` so
 *      the value picker stops offering it. Off by default; admins opt-in.
 *   4. Append a row to `metadata_value_merges` with row count + actor.
 *
 * Strict guarantees:
 *   - Tenant isolation: every UPDATE joins through `assets.tenant_id`.
 *   - Idempotent on a clean re-run: if no rows match `from`, returns 0
 *     without throwing. Alias creation is idempotent (same target → no-op).
 *   - Bounded: caps `MAX_ROWS_PER_MERGE` rows per call. The caller can
 *     loop until the cap stops being hit if they need to merge millions
 *     of rows; Phase 5.3 doesn't ship a queued worker for that.
 */
class MetadataValueMergeService
{
    /** Hard ceiling per merge call. Protects MySQL from a long lock. */
    public const MAX_ROWS_PER_MERGE = 5000;

    public function __construct(
        protected MetadataValueNormalizer $normalizer,
        protected MetadataCanonicalizationService $canonical,
    ) {}

    /**
     * Merge `from` → `to` for the given (tenant, field).
     *
     * @return array{
     *     rows_updated: int,
     *     alias_recorded: bool,
     *     option_removed: bool,
     *     bounded_by_cap: bool,
     *     audit_id: int,
     * }
     *
     * @throws InvalidArgumentException When inputs are invalid.
     */
    public function merge(
        MetadataField $field,
        Tenant $tenant,
        string $from,
        string $to,
        ?User $performedBy = null,
        bool $removeFromOption = false,
        ?string $notes = null,
        string $source = 'manual',
    ): array {
        $fromNorm = $this->normalizer->normalize($from);
        $toNorm = $this->normalizer->normalize($to);
        if ($fromNorm === '' || $toNorm === '') {
            throw new InvalidArgumentException('Both from and to values are required.');
        }
        if ($fromNorm === $toNorm) {
            throw new InvalidArgumentException('from and to normalize to the same value.');
        }

        $type = $this->canonicalType($field);
        if (! in_array($type, ['select', 'multiselect'], true)) {
            throw new InvalidArgumentException(
                "Merge is only supported for select / multiselect fields (got '{$type}')."
            );
        }

        $aliasRecorded = $this->safeRecordAlias($field, $tenant, $fromNorm, $toNorm, $performedBy);

        // Preserve the admin-supplied casing for the canonical value going
        // forward; the normalized form is for matching only. We also strip
        // surrounding whitespace so the rewrite is consistent with how
        // values are typed elsewhere in the system.
        $toDisplay = trim($to);

        $rowsUpdated = match ($type) {
            'select' => $this->mergeSelect($field, $tenant, $fromNorm, $toDisplay),
            'multiselect' => $this->mergeMultiselect($field, $tenant, $fromNorm, $toDisplay),
        };
        $boundedByCap = $rowsUpdated >= self::MAX_ROWS_PER_MERGE;

        $optionRemoved = false;
        if ($removeFromOption) {
            $optionRemoved = $this->safeRemoveOption($field, $fromNorm);
        }

        $audit = MetadataValueMerge::query()->create([
            'tenant_id' => $tenant->id,
            'metadata_field_id' => $field->id,
            'from_value' => $fromNorm,
            'to_value' => $toNorm,
            'rows_updated' => $rowsUpdated,
            'options_removed' => $optionRemoved ? 1 : 0,
            'alias_recorded' => $aliasRecorded,
            'source' => $source,
            'performed_by_user_id' => $performedBy?->id,
            'notes' => $notes,
            'performed_at' => now(),
        ]);

        return [
            'rows_updated' => $rowsUpdated,
            'alias_recorded' => $aliasRecorded,
            'option_removed' => $optionRemoved,
            'bounded_by_cap' => $boundedByCap,
            'audit_id' => (int) $audit->id,
        ];
    }

    /**
     * Recent merge history for an admin "what was changed?" surface. Cheap
     * indexed read; does not paginate (caller-supplied limit only).
     *
     * @return list<array<string, mixed>>
     */
    public function recentMerges(MetadataField $field, Tenant $tenant, int $limit = 25): array
    {
        return MetadataValueMerge::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->orderByDesc('performed_at')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (MetadataValueMerge $m) => [
                'id' => (int) $m->id,
                'from_value' => $m->from_value,
                'to_value' => $m->to_value,
                'rows_updated' => (int) $m->rows_updated,
                'alias_recorded' => (bool) $m->alias_recorded,
                'options_removed' => (int) $m->options_removed,
                'source' => (string) $m->source,
                'performed_by_user_id' => $m->performed_by_user_id,
                'performed_at' => $m->performed_at?->toIso8601String(),
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // select rewrite
    // -----------------------------------------------------------------

    /**
     * Select fields store `value_json` as a JSON-encoded string. We match
     * case-insensitively against the normalized `$fromNorm`. The `$toDisplay`
     * is written verbatim so admin-supplied casing survives.
     */
    private function mergeSelect(MetadataField $field, Tenant $tenant, string $fromNorm, string $toDisplay): int
    {
        $ids = DB::table('asset_metadata as am')
            ->join('assets as a', 'a.id', '=', 'am.asset_id')
            ->where('a.tenant_id', $tenant->id)
            ->where('am.metadata_field_id', $field->id)
            ->whereRaw('LOWER(JSON_UNQUOTE(am.value_json)) = LOWER(?)', [$fromNorm])
            ->limit(self::MAX_ROWS_PER_MERGE)
            ->pluck('am.id');
        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table('asset_metadata')
            ->whereIn('id', $ids->all())
            ->update([
                'value_json' => json_encode($toDisplay),
                'updated_at' => now(),
            ]);
    }

    // -----------------------------------------------------------------
    // multiselect rewrite
    // -----------------------------------------------------------------

    /**
     * Multiselect fields store `value_json` as a JSON array. We can't
     * SQL-UPDATE a JSON array element generically across MySQL 5.7+ in a
     * portable way, so we read affected rows, mutate in PHP, and write
     * back. Mutation is:
     *   - replace any element that NORMALIZES to `$fromNorm` with `$toDisplay`
     *   - dedupe (by normalized form)
     *   - sort? NO — preserve admin-curated ordering.
     *
     * Why we don't pre-filter with `JSON_CONTAINS`: that operator is
     * strictly case-sensitive in MySQL and would miss cross-case aliases
     * (the test stores "Outdoors" but the normalized form is "outdoors").
     * Instead we fetch every multiselect row for this (tenant, field),
     * cap by MAX_ROWS_PER_MERGE, and match in PHP via the normalizer.
     * Bounded by the same cap, so behaviour is predictable.
     */
    private function mergeMultiselect(MetadataField $field, Tenant $tenant, string $fromNorm, string $toDisplay): int
    {
        $rows = DB::table('asset_metadata as am')
            ->join('assets as a', 'a.id', '=', 'am.asset_id')
            ->where('a.tenant_id', $tenant->id)
            ->where('am.metadata_field_id', $field->id)
            ->limit(self::MAX_ROWS_PER_MERGE)
            ->select(['am.id', 'am.value_json'])
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $updated = 0;
        $now = now();
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row->value_json, true);
            if (! is_array($decoded)) {
                continue;
            }
            $changed = false;
            $next = [];
            $seenNorm = [];
            foreach ($decoded as $element) {
                if (! is_string($element)) {
                    $next[] = $element;
                    continue;
                }
                $normElement = $this->normalizer->normalize($element);
                if ($normElement === $fromNorm) {
                    $element = $toDisplay;
                    $changed = true;
                }
                $key = $this->normalizer->normalize($element);
                if ($key !== '' && isset($seenNorm[$key])) {
                    // Dedupe: the to-value already appeared earlier (or the
                    // alias already coexisted with the canonical).
                    $changed = true;
                    continue;
                }
                if ($key !== '') {
                    $seenNorm[$key] = true;
                }
                $next[] = $element;
            }
            if (! $changed) {
                continue;
            }
            DB::table('asset_metadata')
                ->where('id', $row->id)
                ->update([
                    'value_json' => json_encode(array_values($next)),
                    'updated_at' => $now,
                ]);
            $updated++;
        }

        return $updated;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Try to record the merge as an alias. Failures here are operational —
     * the alias table just won't reflect the merge. The data rewrite
     * already happened by then so we always return cleanly.
     */
    private function safeRecordAlias(
        MetadataField $field,
        Tenant $tenant,
        string $from,
        string $to,
        ?User $user,
    ): bool {
        try {
            $this->canonical->addAlias(
                $field,
                $tenant,
                $from,
                $to,
                $user,
                MetadataCanonicalizationService::SOURCE_MERGED,
                'Recorded automatically during merge.'
            );

            return true;
        } catch (\Throwable $e) {
            Log::debug('MetadataValueMergeService: alias-record failed', [
                'field_id' => $field->id,
                'tenant_id' => $tenant->id,
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function safeRemoveOption(MetadataField $field, string $from): bool
    {
        try {
            $deleted = MetadataOption::query()
                ->where('metadata_field_id', $field->id)
                ->whereRaw('LOWER(value) = LOWER(?)', [$from])
                ->delete();

            return $deleted > 0;
        } catch (\Throwable $e) {
            Log::debug('MetadataValueMergeService: option-removal failed', [
                'field_id' => $field->id,
                'from' => $from,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Same canonical-type map the eligibility / facet services use. Kept
     * private so this file remains readable; if a third hygiene class needs
     * it we can promote it to a shared helper.
     */
    private function canonicalType(MetadataField $field): string
    {
        $type = (string) ($field->type ?? '');
        return match ($type) {
            'select', 'single_select' => 'select',
            'multiselect', 'multi_select' => 'multiselect',
            'boolean', 'bool' => 'boolean',
            default => $type,
        };
    }
}
