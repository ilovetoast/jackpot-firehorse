<?php

namespace App\Services\Hygiene;

use App\Models\MetadataField;
use App\Models\MetadataValueAlias;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Phase 5.3 — canonical value resolver and alias CRUD.
 *
 * Architecture:
 *   - One alias row per (tenant, field, alias_value) — DB enforces this.
 *   - Aliases are NOT chained. If `B → C` exists, you cannot then add
 *     `A → B`; you must add `A → C`. Loop guards in this service refuse
 *     chains so resolution stays O(1) and admins never see "alias of an
 *     alias of an alias" surprises.
 *   - Canonical values can themselves be aliased? NO. If you try to add
 *     `B → C` while `B` is already a canonical for some other alias `A`,
 *     the service refuses unless you explicitly remove `A` first.
 *   - Resolution is cheap: one indexed lookup per value via
 *     `MetadataValueAlias::firstWhere(['tenant_id', 'metadata_field_id',
 *     'alias_value'])`.
 *
 * Phase 5.3 ships passive resolution only — `getCanonical()` is exposed for
 * read paths but the global filter engine is NOT wired through it yet. That
 * keeps existing query semantics intact while admins curate the alias table
 * with confidence.
 */
class MetadataCanonicalizationService
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SEEDED = 'seeded';
    public const SOURCE_MERGED = 'merged';
    public const SOURCE_AI_SUGGESTED = 'ai_suggested';
    public const SOURCE_IMPORTED = 'imported';

    private const ALLOWED_SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_SEEDED,
        self::SOURCE_MERGED,
        self::SOURCE_AI_SUGGESTED,
        self::SOURCE_IMPORTED,
    ];

    public function __construct(
        protected MetadataValueNormalizer $normalizer,
    ) {}

    // -----------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------

    /**
     * Resolve a raw value to its canonical form within (tenant, field).
     *
     * Returns the original value (cast to string) when no alias is on file,
     * so callers can pipe every read through this method without nullcheck.
     */
    public function getCanonical(MetadataField $field, Tenant $tenant, mixed $value): string
    {
        $alias = $this->normalizeForLookup($value);
        if ($alias === '') {
            return '';
        }
        $row = MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('alias_value', $alias)
            ->first();

        return $row?->canonical_value ?? $alias;
    }

    /**
     * Batch resolution. Useful when a value endpoint wants to map dozens of
     * candidate values in one trip.
     *
     * @param  iterable<mixed>  $values
     * @return array<string, string> alias_value → canonical_value
     */
    public function batchResolveCanonical(MetadataField $field, Tenant $tenant, iterable $values): array
    {
        $normalized = [];
        foreach ($values as $v) {
            $key = $this->normalizeForLookup($v);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = $key; // default (no alias): canonical = self
        }
        if ($normalized === []) {
            return [];
        }
        $rows = MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->whereIn('alias_value', array_keys($normalized))
            ->get(['alias_value', 'canonical_value']);
        foreach ($rows as $row) {
            $normalized[$row->alias_value] = (string) $row->canonical_value;
        }

        return $normalized;
    }

    /**
     * @return Collection<int, MetadataValueAlias>
     */
    public function listForField(MetadataField $field, Tenant $tenant): Collection
    {
        return MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->orderBy('canonical_value')
            ->orderBy('alias_value')
            ->get();
    }

    /**
     * Aliases pointing at a specific canonical value.
     *
     * @return Collection<int, MetadataValueAlias>
     */
    public function getAliasesFor(MetadataField $field, Tenant $tenant, string $canonical): Collection
    {
        $canonicalNormalized = $this->normalizeForLookup($canonical);
        if ($canonicalNormalized === '') {
            return collect();
        }

        return MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('canonical_value', $canonicalNormalized)
            ->orderBy('alias_value')
            ->get();
    }

    public function isAlias(MetadataField $field, Tenant $tenant, string $value): bool
    {
        $key = $this->normalizeForLookup($value);
        if ($key === '') {
            return false;
        }

        return MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('alias_value', $key)
            ->exists();
    }

    public function isCanonical(MetadataField $field, Tenant $tenant, string $value): bool
    {
        $key = $this->normalizeForLookup($value);
        if ($key === '') {
            return false;
        }

        return MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('canonical_value', $key)
            ->exists();
    }

    // -----------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------

    /**
     * Idempotent alias-creation. Refuses to create:
     *   - alias === canonical (same normalized form) — pointless.
     *   - alias is already on file pointing at a different canonical.
     *     Callers must `removeAlias()` first.
     *   - alias is itself a canonical for someone else (would create a
     *     two-step chain). Same fix: collapse the chain manually first.
     *   - canonical is itself an alias. Callers must repoint the original
     *     alias before reusing the value as a canonical.
     *
     * @throws InvalidArgumentException for any of the above cases.
     */
    public function addAlias(
        MetadataField $field,
        Tenant $tenant,
        string $alias,
        string $canonical,
        ?User $user = null,
        string $source = self::SOURCE_MANUAL,
        ?string $notes = null,
    ): MetadataValueAlias {
        $this->assertSource($source);
        $aliasNorm = $this->normalizeForLookup($alias);
        $canonicalNorm = $this->normalizeForLookup($canonical);
        if ($aliasNorm === '' || $canonicalNorm === '') {
            throw new InvalidArgumentException('Alias and canonical must be non-empty after normalization.');
        }
        if ($aliasNorm === $canonicalNorm) {
            throw new InvalidArgumentException('Alias and canonical normalize to the same value; nothing to record.');
        }

        // Chain guard 1: the proposed alias is currently a canonical for
        // someone else. Adding the row would create A → B → C; refuse.
        $aliasIsCanonical = MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('canonical_value', $aliasNorm)
            ->exists();
        if ($aliasIsCanonical) {
            throw new InvalidArgumentException(
                "Cannot alias '{$aliasNorm}' — it is already the canonical target of other aliases. "
                .'Repoint or remove those first.'
            );
        }

        // Chain guard 2: the proposed canonical is itself an alias. Adding
        // would create A → B where B → C; collapse manually first.
        $canonicalIsAlias = MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('alias_value', $canonicalNorm)
            ->exists();
        if ($canonicalIsAlias) {
            throw new InvalidArgumentException(
                "Cannot use '{$canonicalNorm}' as a canonical — it is itself an alias of another value."
            );
        }

        // Idempotent same-target. If the row already exists pointing at the
        // same canonical, return it. If it points elsewhere, refuse.
        $existing = MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('alias_value', $aliasNorm)
            ->first();
        if ($existing !== null) {
            if ($existing->canonical_value === $canonicalNorm) {
                return $existing;
            }
            throw new InvalidArgumentException(
                "Alias '{$aliasNorm}' already points at '{$existing->canonical_value}'. "
                .'Remove the existing alias first.'
            );
        }

        $alias = new MetadataValueAlias();
        $alias->fill([
            'tenant_id' => $tenant->id,
            'metadata_field_id' => $field->id,
            'alias_value' => $aliasNorm,
            'canonical_value' => $canonicalNorm,
            'normalization_hash' => $this->normalizer->hash($aliasNorm),
            'source' => $source,
            'created_by_user_id' => $user?->id,
            'notes' => $notes,
        ]);
        $alias->save();

        return $alias;
    }

    public function removeAlias(MetadataField $field, Tenant $tenant, string $alias): bool
    {
        $key = $this->normalizeForLookup($alias);
        if ($key === '') {
            return false;
        }
        $deleted = MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('alias_value', $key)
            ->delete();

        return $deleted > 0;
    }

    public function removeAllAliasesForCanonical(MetadataField $field, Tenant $tenant, string $canonical): int
    {
        $key = $this->normalizeForLookup($canonical);
        if ($key === '') {
            return 0;
        }

        return MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->where('canonical_value', $key)
            ->delete();
    }

    public function aliasCountForField(MetadataField $field, Tenant $tenant): int
    {
        return MetadataValueAlias::query()
            ->where('tenant_id', $tenant->id)
            ->where('metadata_field_id', $field->id)
            ->count();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function assertSource(string $source): void
    {
        if (! in_array($source, self::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException(
                "Unknown alias source '{$source}'. Allowed: ".implode(', ', self::ALLOWED_SOURCES)
            );
        }
    }

    /**
     * Lookup keys live in the normalized space. We do NOT store the original
     * casing in `alias_value`; the normalizer is the canonical key. This
     * keeps the unique index meaningful and matches how the duplicate
     * detector clusters.
     */
    private function normalizeForLookup(mixed $value): string
    {
        return $this->normalizer->normalize($value);
    }
}
