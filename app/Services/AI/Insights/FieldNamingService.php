<?php

namespace App\Services\AI\Insights;

use App\Models\Category;
use Illuminate\Support\Str;

/**
 * Maps anchor tags to proposed metadata field keys/labels — avoids naive regex (e.g. hiking → hik_species).
 */
class FieldNamingService
{
    /**
     * @return array{field_key: string, field_name: string}|null null = skip anchor (too broad / do not suggest)
     */
    public function inferFieldName(string $anchor, ?Category $category = null): ?array
    {
        $a = strtolower(trim($anchor));
        if ($a === '') {
            return null;
        }

        $skip = config('ai_metadata_field_suggestions.naming_skip_anchors', []);
        $skip = array_map('strtolower', is_array($skip) ? $skip : []);
        if (in_array($a, $skip, true)) {
            return null;
        }

        $species = config('ai_metadata_field_suggestions.naming_species_anchors', ['fishing', 'hunting']);
        $species = array_map('strtolower', is_array($species) ? $species : []);
        if (in_array($a, $species, true)) {
            return $this->speciesFieldForAnchor($a);
        }

        $brand = config('ai_metadata_field_suggestions.naming_brand_anchors', ['brand', 'branding']);
        $brand = array_map('strtolower', is_array($brand) ? $brand : []);
        if (in_array($a, $brand, true)) {
            return [
                'field_key' => 'brand_type',
                'field_name' => 'Brand Type',
            ];
        }

        return $this->fallbackRelatedTags($anchor);
    }

    /**
     * Distinct keys per common anchor so unique constraints do not collide awkwardly in one sync.
     *
     * @return array{field_key: string, field_name: string}
     */
    protected function speciesFieldForAnchor(string $anchorLower): array
    {
        return match ($anchorLower) {
            'fishing' => ['field_key' => 'fish_species', 'field_name' => 'Fish species'],
            'hunting' => ['field_key' => 'game_species', 'field_name' => 'Game species'],
            default => [
                'field_key' => 'species',
                'field_name' => 'Species',
            ],
        };
    }

    /**
     * @return array{field_key: string, field_name: string}
     */
    protected function fallbackRelatedTags(string $anchorTag): array
    {
        $t = trim($anchorTag);
        $slug = Str::slug($t, '_');
        if ($slug === '') {
            $slug = 'tag_cluster';
        }

        return [
            'field_key' => $slug.'_related_tags',
            'field_name' => Str::title($t).' related tags',
        ];
    }
}
