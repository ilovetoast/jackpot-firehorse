<?php

namespace App\Assets\Metadata;

use App\Models\Asset;
use App\Models\AssetMetadataIndexEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class EmbeddedMetadataIndexBuilder
{
    public function __construct(
        protected EmbeddedMetadataRegistry $registry,
        protected EmbeddedMetadataSearchTextNormalizer $searchTextNormalizer,
        protected EmbeddedMetadataTechnicalNormalizer $technicalNormalizer
    ) {}

    /**
     * Replace all derived index rows for this asset (idempotent rebuild).
     *
     * @param  array<string, mixed>  $normalizedPayload  Layer B shape (namespaced buckets)
     */
    public function rebuild(Asset $asset, array $normalizedPayload): void
    {
        AssetMetadataIndexEntry::query()->where('asset_id', $asset->id)->delete();

        foreach ($this->registry->allowlistedKeys() as $fqKey => $def) {
            $entry = $this->registry->indexEntryFor($fqKey);
            if ($entry === null) {
                continue;
            }

            $value = $this->valueFromPayload($normalizedPayload, $fqKey);
            if ($value === null || $value === '') {
                continue;
            }

            $type = $entry['type'] ?? 'string';
            if ($type === 'keyword') {
                $items = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($items as $item) {
                    if (! is_string($item) && ! is_numeric($item)) {
                        continue;
                    }
                    $s = Str::limit(trim((string) $item), 4090, '');
                    if ($s === '') {
                        continue;
                    }
                    $this->createRow(
                        $asset,
                        $entry,
                        $fqKey,
                        'keyword',
                        $s,
                        valueString: $s
                    );
                }

                continue;
            }

            $this->insertTypedRow($asset, $entry, $fqKey, $type, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function valueFromPayload(array $payload, string $fullyQualifiedKey): mixed
    {
        if (! preg_match('/^([^.]+)\.(.+)$/', $fullyQualifiedKey, $m)) {
            return null;
        }
        $namespace = $m[1];
        $key = $m[2];

        return $payload[$namespace][$key] ?? null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function insertTypedRow(Asset $asset, array $entry, string $fqKey, string $type, mixed $value): void
    {
        $pdfHint = str_contains($fqKey, 'pdf.');

        match ($type) {
            'string' => $this->insertStringRow($asset, $entry, $fqKey, $value),
            'number' => $this->insertNumberRow($asset, $entry, $fqKey, $value),
            'boolean' => $this->insertBooleanRow($asset, $entry, $fqKey, $value),
            'date' => $this->insertDateRow($asset, $entry, $fqKey, $value, $pdfHint),
            'datetime' => $this->insertDatetimeRow($asset, $entry, $fqKey, $value, $pdfHint),
            'json' => $this->createRow(
                $asset,
                $entry,
                $fqKey,
                'json',
                is_string($value) ? $value : json_encode($value),
                valueJson: is_array($value) ? $value : ['value' => $value]
            ),
            default => $this->insertStringRow($asset, $entry, $fqKey, $value),
        };
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function insertStringRow(Asset $asset, array $entry, string $fqKey, mixed $value): void
    {
        $display = $this->stringDisplayValue($entry, $value);
        $this->createRow(
            $asset,
            $entry,
            $fqKey,
            'string',
            $display,
            valueString: $display
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function stringDisplayValue(array $entry, mixed $value): string
    {
        $nk = $entry['normalized_key'] ?? '';
        if ($this->technicalNormalizer->shouldNormalize($nk)) {
            return Str::limit($this->technicalNormalizer->formatDisplay($nk, 'string', $value), 4090, '');
        }

        return Str::limit($this->scalarString($value), 4090, '');
    }

    protected function insertBooleanRow(Asset $asset, array $entry, string $fqKey, mixed $value): void
    {
        $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            return;
        }
        $label = $b ? 'true' : 'false';
        $this->createRow(
            $asset,
            $entry,
            $fqKey,
            'boolean',
            $label,
            valueBoolean: $b
        );
    }

    protected function insertNumberRow(Asset $asset, array $entry, string $fqKey, mixed $value): void
    {
        $num = EmbeddedMetadataValueParser::toNumber($value);
        if ($num === null) {
            return;
        }

        if (($entry['normalized_key'] ?? '') === 'iso') {
            $display = $this->technicalNormalizer->formatDisplay('iso', 'number', $value);
            $this->createRow(
                $asset,
                $entry,
                $fqKey,
                'number',
                $display,
                valueNumber: (float) (int) round($num)
            );

            return;
        }

        $this->createRow(
            $asset,
            $entry,
            $fqKey,
            'number',
            (string) $num,
            valueNumber: $num
        );
    }

    protected function insertDateRow(Asset $asset, array $entry, string $fqKey, mixed $value, bool $pdfHint): void
    {
        $dt = EmbeddedMetadataValueParser::parseDatetime($value, $pdfHint ? 'pdf' : null);
        if (! $dt) {
            return;
        }
        $this->createRow(
            $asset,
            $entry,
            $fqKey,
            'date',
            $dt->toDateString(),
            valueDate: $dt->format('Y-m-d')
        );
    }

    protected function insertDatetimeRow(Asset $asset, array $entry, string $fqKey, mixed $value, bool $pdfHint): void
    {
        $dt = EmbeddedMetadataValueParser::parseDatetime($value, $pdfHint ? 'pdf' : null);
        if (! $dt) {
            return;
        }
        $this->createRow(
            $asset,
            $entry,
            $fqKey,
            'datetime',
            $dt->toIso8601String(),
            valueDatetime: $dt
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function createRow(
        Asset $asset,
        array $entry,
        string $fqKey,
        string $valueType,
        string $searchTextSource,
        ?string $valueString = null,
        ?float $valueNumber = null,
        ?bool $valueBoolean = null,
        ?string $valueDate = null,
        ?CarbonInterface $valueDatetime = null,
        ?array $valueJson = null,
    ): void {
        $logicalKey = preg_match('/^([^.]+)\.(.+)$/', $fqKey, $m) ? $m[2] : $fqKey;

        $normalizedSearch = $this->searchTextNormalizer->normalize($searchTextSource);

        AssetMetadataIndexEntry::create([
            'asset_id' => $asset->id,
            'namespace' => $entry['namespace'],
            'key' => $logicalKey,
            'normalized_key' => $entry['normalized_key'],
            'value_type' => $valueType,
            'value_string' => $valueString,
            'value_number' => $valueNumber,
            'value_boolean' => $valueBoolean,
            'value_date' => $valueDate,
            'value_datetime' => $valueDatetime,
            'value_json' => $valueJson,
            'search_text' => Str::limit($normalizedSearch, 65000, ''),
            'is_filterable' => (bool) ($entry['filterable'] ?? false),
            'is_visible' => (bool) ($entry['visible'] ?? false),
            'source_priority' => (int) ($entry['source_priority'] ?? 100),
        ]);
    }

    protected function scalarString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        return (string) $value;
    }
}
