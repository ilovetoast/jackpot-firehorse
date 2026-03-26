<?php

namespace App\Assets\Metadata;

use App\Models\Asset;
use Illuminate\Support\Facades\Log;

/**
 * Maps allowlisted embedded values into canonical asset columns / attributes only when registry permits.
 *
 * Provenance for mapped fields is stored under metadata.embedded_system_map (does not replace category_id).
 */
class EmbeddedMetadataSystemMapper
{
    public function __construct(
        protected EmbeddedMetadataRegistry $registry,
        protected EmbeddedMetadataIndexBuilder $indexBuilder
    ) {}

    /**
     * @param  array<string, mixed>  $normalizedPayload
     */
    public function apply(Asset $asset, array $normalizedPayload): void
    {
        $updates = [];

        foreach ($this->registry->allowlistedKeys() as $fqKey => $def) {
            $mapTo = $def['map_to_system'] ?? null;
            if (! is_string($mapTo) || $mapTo === '') {
                continue;
            }

            $mode = $def['system_map_mode'] ?? 'fill_if_empty';
            $value = $this->indexBuilder->valueFromPayload($normalizedPayload, $fqKey);
            if ($value === null || $value === '') {
                continue;
            }

            match ($mapTo) {
                'captured_at' => $this->mapCapturedAt($asset, $fqKey, $value, $mode, $updates),
                default => Log::debug('[EmbeddedMetadataSystemMapper] Unhandled map_to_system', [
                    'asset_id' => $asset->id,
                    'map_to' => $mapTo,
                    'fq_key' => $fqKey,
                ]),
            };
        }

        if ($updates !== []) {
            $asset->update($updates);
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    protected function mapCapturedAt(Asset $asset, string $fqKey, mixed $value, string $mode, array &$updates): void
    {
        $pdfHint = false;
        $dt = EmbeddedMetadataValueParser::parseDatetime($value, $pdfHint);
        if (! $dt) {
            return;
        }

        $current = $asset->captured_at;
        if ($mode === 'never') {
            return;
        }

        $shouldSet = false;
        if ($mode === 'fill_if_empty' || $mode === 'overwrite_if_nullish') {
            if ($current !== null) {
                return;
            }
            $shouldSet = true;
        } elseif ($mode === 'trusted_overwrite') {
            $shouldSet = true;
        }

        if (! $shouldSet) {
            return;
        }

        $updates['captured_at'] = $dt;

        $meta = $asset->metadata ?? [];
        $map = $meta['embedded_system_map'] ?? [];
        $map['captured_at'] = [
            'source_fq' => $fqKey,
            'system_map_mode' => $mode,
            'mapped_at' => now()->toIso8601String(),
        ];
        $meta['embedded_system_map'] = $map;
        $updates['metadata'] = $meta;
    }
}
