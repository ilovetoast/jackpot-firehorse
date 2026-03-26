<?php

namespace App\Assets\Metadata;

use App\Models\Asset;

/**
 * Read-only shapes for API/Inertia (summary + optional raw).
 */
class EmbeddedMetadataPresentation
{
    public function __construct(
        protected EmbeddedMetadataRegistry $registry
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summaryForAsset(Asset $asset): array
    {
        $payload = $asset->embeddedMetadataPayload;
        $namespaces = $payload ? array_keys($payload->payload_json ?? []) : [];

        $visibleRows = $asset->metadataIndexEntries()
            ->where('is_visible', true)
            ->orderBy('namespace')
            ->orderBy('normalized_key')
            ->get()
            ->map(fn ($row) => [
                'namespace' => $row->namespace,
                'key' => $row->key,
                'normalized_key' => $row->normalized_key,
                'value_type' => $row->value_type,
                'display' => $this->displayValue($row),
            ])
            ->values()
            ->all();

        return [
            'has_embedded_metadata' => $payload !== null && $namespaces !== [],
            'namespaces_present' => $namespaces,
            'visible_indexed_metadata' => $visibleRows,
            'extracted_at' => $payload?->extracted_at?->toIso8601String(),
            'schema_version' => $payload?->schema_version,
        ];
    }

    /**
     * Raw Layer B payload grouped by namespace (for privileged/detail views).
     *
     * @return array<string, mixed>|null
     */
    public function rawPayloadForAsset(Asset $asset, bool $includeSensitiveNamespaces = true): ?array
    {
        $payload = $asset->embeddedMetadataPayload;
        if (! $payload) {
            return null;
        }

        $data = $payload->payload_json ?? [];
        if ($includeSensitiveNamespaces) {
            return $data;
        }

        $exif = $data['exif'] ?? [];
        if (is_array($exif)) {
            foreach ($this->registry->sensitiveFqKeys() as $fq) {
                if (! preg_match('/^exif\.(.+)$/', $fq, $m)) {
                    continue;
                }
                $k = $m[1];
                unset($exif[$k]);
            }
            $data['exif'] = $exif;
        }

        return $data;
    }

    /**
     * @param  \App\Models\AssetMetadataIndexEntry  $row
     */
    protected function displayValue($row): string
    {
        if ($row->value_string !== null && $row->value_string !== '') {
            return $row->value_string;
        }
        if ($row->value_number !== null) {
            return (string) $row->value_number;
        }
        if ($row->value_boolean !== null) {
            return $row->value_boolean ? 'true' : 'false';
        }
        if ($row->value_date !== null) {
            return (string) $row->value_date;
        }
        if ($row->value_datetime !== null) {
            return $row->value_datetime->toIso8601String();
        }
        if ($row->value_json !== null) {
            return json_encode($row->value_json);
        }

        return '';
    }
}
