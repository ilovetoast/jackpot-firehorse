<?php

namespace App\Support;

/**
 * Maps a layer id from a "source" composition document onto the corresponding layer in a sibling
 * composition (same Creative Set). Duplicated compositions keep z-order; we first try the same
 * index in z-sorted stacks, then same (type + name) when names are present.
 */
final class StudioCrossCompositionLayerResolver
{
    /**
     * @param  array<string, mixed>  $sourceDocument
     * @param  array<string, mixed>  $targetDocument
     */
    public function resolveTargetLayerId(
        array $sourceDocument,
        array $targetDocument,
        string $sourceLayerId,
    ): ?string {
        $sourceLayers = $sourceDocument['layers'] ?? [];
        $targetLayers = $targetDocument['layers'] ?? [];
        if (! is_array($sourceLayers) || ! is_array($targetLayers)) {
            return null;
        }

        $source = $this->findLayerById($sourceLayers, $sourceLayerId);
        if ($source === null) {
            return null;
        }

        $sourceType = (string) ($source['type'] ?? '');
        $srcSorted = $this->sortedByZ($sourceLayers);
        $idx = null;
        foreach ($srcSorted as $i => $layer) {
            if (($layer['id'] ?? '') === $sourceLayerId) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            return null;
        }

        $tgtSorted = $this->sortedByZ($targetLayers);
        $candidate = $tgtSorted[$idx] ?? null;
        if (is_array($candidate) && (string) ($candidate['type'] ?? '') === $sourceType) {
            return (string) ($candidate['id'] ?? '') ?: null;
        }

        $name = strtolower(trim((string) ($source['name'] ?? '')));
        if ($name !== '') {
            foreach ($targetLayers as $layer) {
                if (! is_array($layer)) {
                    continue;
                }
                if ((string) ($layer['type'] ?? '') !== $sourceType) {
                    continue;
                }
                if (strtolower(trim((string) ($layer['name'] ?? ''))) === $name) {
                    return (string) ($layer['id'] ?? '') ?: null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @return array<int, array<string, mixed>>
     */
    private function sortedByZ(array $layers): array
    {
        $list = array_values(array_filter($layers, static fn ($l) => is_array($l)));
        usort($list, static function (array $a, array $b): int {
            $za = isset($a['z']) && is_numeric($a['z']) ? (int) $a['z'] : 0;
            $zb = isset($b['z']) && is_numeric($b['z']) ? (int) $b['z'] : 0;

            return $za <=> $zb;
        });

        return $list;
    }

    /**
     * @param  array<int, mixed>  $layers
     * @return array<string, mixed>|null
     */
    private function findLayerById(array $layers, string $id): ?array
    {
        foreach ($layers as $layer) {
            if (is_array($layer) && (string) ($layer['id'] ?? '') === $id) {
                return $layer;
            }
        }

        return null;
    }
}
