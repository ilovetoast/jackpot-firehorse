<?php

namespace App\Assets\Metadata;

use App\Models\Asset;
use App\Models\AssetMetadataIndexEntry;

/**
 * Admin / engineering debug snapshot (no PII filtering — admin route only).
 */
class EmbeddedMetadataDebugPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function assemble(Asset $asset): array
    {
        $payload = $asset->embeddedMetadataPayload;
        $payloadData = $payload?->payload_json ?? [];

        $indexRows = AssetMetadataIndexEntry::query()
            ->where('asset_id', $asset->id)
            ->orderBy('namespace')
            ->orderBy('normalized_key')
            ->orderBy('key')
            ->limit(250)
            ->get();

        $meta = $asset->metadata ?? [];

        return [
            'namespaces_present' => array_keys(is_array($payloadData) ? $payloadData : []),
            'extractor_other' => is_array($payloadData['other'] ?? null) ? $payloadData['other'] : [],
            'index_row_count' => AssetMetadataIndexEntry::query()->where('asset_id', $asset->id)->count(),
            'index_rows' => $indexRows->map(fn ($r) => [
                'namespace' => $r->namespace,
                'key' => $r->key,
                'normalized_key' => $r->normalized_key,
                'value_type' => $r->value_type,
                'value_string' => $r->value_string,
                'value_number' => $r->value_number,
                'search_text' => $r->search_text,
            ])->values()->all(),
            'canonical_captured_at' => $asset->captured_at?->toIso8601String(),
            'embedded_system_map' => $meta['embedded_system_map'] ?? null,
            'payload_extracted_at' => $payload?->extracted_at?->toIso8601String(),
            'schema_version' => $payload?->schema_version,
        ];
    }
}
