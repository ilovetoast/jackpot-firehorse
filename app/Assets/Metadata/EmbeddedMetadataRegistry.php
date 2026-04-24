<?php

namespace App\Assets\Metadata;

/**
 * Single governance entry point for embedded metadata allowlists. 
 * Unknown file keys are never indexed; only config('asset_embedded_metadata.keys') drives Layer C.
 */
class EmbeddedMetadataRegistry
{
    public function schemaVersion(): string
    {
        return (string) config('asset_embedded_metadata.schema_version', '1');
    }

    /**
     * @return array<string, array<string, mixed>> 
     */
    public function allowlistedKeys(): array
    {
        return config('asset_embedded_metadata.keys', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entry(string $fullyQualifiedKey): ?array
    {
        $keys = $this->allowlistedKeys();
        if (! isset($keys[$fullyQualifiedKey])) {
            return null;
        }

        return $this->applySensitivityGuards($fullyQualifiedKey, $keys[$fullyQualifiedKey]);
    }

    /**
     * Whether this FQ key is listed in the registry at all (before sensitivity overrides).
     */
    public function isKnownKey(string $fullyQualifiedKey): bool
    {
        return isset($this->allowlistedKeys()[$fullyQualifiedKey]);
    }

    /**
     * @return list<string>
     */
    public function sensitiveFqKeys(): array
    {
        $fromEntries = [];
        foreach ($this->allowlistedKeys() as $fq => $entry) {
            if (($entry['sensitivity'] ?? 'none') === 'stored_only') {
                $fromEntries[] = $fq;
            }
        }

        return array_values(array_unique(array_merge(
            $fromEntries,
            config('asset_embedded_metadata.sensitive_fq_keys', [])
        )));
    }

    public function isSensitiveFqKey(string $fullyQualifiedKey): bool
    {
        return in_array($fullyQualifiedKey, $this->sensitiveFqKeys(), true);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null null if this key must not appear in the index at all
     */
    public function indexEntryFor(string $fullyQualifiedKey): ?array
    {
        $entry = $this->entry($fullyQualifiedKey);
        if ($entry === null) {
            return null;
        }
        if (! ($entry['index'] ?? false)) {
            return null;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    protected function applySensitivityGuards(string $fullyQualifiedKey, array $entry): array
    {
        $sensitivity = $entry['sensitivity'] ?? 'none';
        $forceSensitive = in_array($fullyQualifiedKey, config('asset_embedded_metadata.sensitive_fq_keys', []), true);

        if ($forceSensitive || $sensitivity === 'stored_only') {
            return array_merge($entry, [
                'index' => false,
                'filterable' => false,
                'visible' => false,
                'map_to_system' => null,
            ]);
        }

        if ($sensitivity === 'hidden') {
            return array_merge($entry, [
                'index' => false,
                'filterable' => false,
                'visible' => false,
            ]);
        }

        return $entry;
    }
}
