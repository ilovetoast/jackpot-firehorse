<?php

namespace App\Support;

/**
 * Locate the primary product image layer in a Studio document_json blob.
 */
final class StudioEditorDocumentProductLayerFinder
{
    /**
     * @param  array<string, mixed>  $document
     * @return array{layer_id: string, asset_id: string}|null
     */
    public static function find(array $document): ?array
    {
        $layers = $document['layers'] ?? null;
        if (! is_array($layers)) {
            return null;
        }

        $candidates = [];
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (($layer['type'] ?? '') !== 'image') {
                continue;
            }
            $assetId = $layer['assetId'] ?? $layer['asset_id'] ?? null;
            if (! is_string($assetId) || $assetId === '') {
                continue;
            }
            $z = isset($layer['z']) && is_numeric($layer['z']) ? (int) $layer['z'] : 0;
            $name = isset($layer['name']) ? strtolower((string) $layer['name']) : '';
            $candidates[] = [
                'id' => (string) ($layer['id'] ?? ''),
                'asset_id' => $assetId,
                'z' => $z,
                'productish' => str_contains($name, 'product') || str_contains($name, 'hero') || str_contains($name, 'subject'),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['z'] <=> $a['z']);

        foreach ($candidates as $c) {
            if ($c['productish']) {
                return ['layer_id' => $c['id'], 'asset_id' => $c['asset_id']];
            }
        }

        $first = $candidates[0];

        return ['layer_id' => $first['id'], 'asset_id' => $first['asset_id']];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function applyImageAsset(array $document, string $layerId, string $newAssetId, string $srcUrl): array
    {
        $layers = $document['layers'] ?? [];
        if (! is_array($layers)) {
            return $document;
        }

        foreach ($layers as $i => $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if ((string) ($layer['id'] ?? '') !== $layerId) {
                continue;
            }
            if (($layer['type'] ?? '') !== 'image') {
                continue;
            }
            $layer['assetId'] = $newAssetId;
            $layer['src'] = $srcUrl;
            $layers[$i] = $layer;
            break;
        }

        $document['layers'] = $layers;

        return $document;
    }
}
