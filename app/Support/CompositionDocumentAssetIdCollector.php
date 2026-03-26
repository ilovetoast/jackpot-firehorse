<?php

namespace App\Support;

/**
 * Collects DAM asset ids referenced from a generative editor document (layers JSON).
 */
final class CompositionDocumentAssetIdCollector
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<int, string>
     */
    public static function collectReferencedAssetIds(array $document): array
    {
        $ids = [];
        foreach ($document['layers'] ?? [] as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            if (! empty($layer['assetId']) && is_string($layer['assetId'])) {
                $ids[] = $layer['assetId'];
            }
            if (! empty($layer['resultAssetId']) && is_string($layer['resultAssetId'])) {
                $ids[] = $layer['resultAssetId'];
            }
            if (! empty($layer['referenceAssetIds']) && is_array($layer['referenceAssetIds'])) {
                foreach ($layer['referenceAssetIds'] as $rid) {
                    if (is_string($rid) && $rid !== '') {
                        $ids[] = $rid;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
