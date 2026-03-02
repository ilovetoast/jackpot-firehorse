<?php

namespace App\Services\BrandDNA\Extraction;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Extracts structured brand signals from uploaded brand materials (assets).
 * Aggregates dominant colors, OCR text, typography from assets.
 */
class BrandMaterialProcessor
{
    public function process(Collection $assets): array
    {
        $schema = BrandExtractionSchema::empty();
        $allColors = [];
        $allFonts = [];
        $extractedText = [];

        foreach ($assets as $asset) {
            if (! $asset instanceof Asset) {
                continue;
            }
            $colors = $this->getDominantColors($asset);
            foreach ($colors as $c) {
                $hex = is_array($c) ? ($c['hex'] ?? null) : $c;
                if ($hex && is_string($hex)) {
                    $allColors[] = str_starts_with($hex, '#') ? $hex : '#' . $hex;
                }
            }
            $text = $this->getExtractedText($asset);
            if ($text) {
                $extractedText[] = $text;
            }
        }

        $schema['visual']['primary_colors'] = array_values(array_unique($allColors));
        $schema['sources']['materials'] = [
            'asset_count' => $assets->count(),
            'colors_extracted' => count($schema['visual']['primary_colors']),
        ];

        if (! empty($extractedText)) {
            $combined = implode("\n\n", $extractedText);
            $schema = BrandExtractionSchema::merge(
                $schema,
                (new BrandGuidelinesProcessor)->process($combined)
            );
        }

        $schema['confidence'] = $this->computeConfidence($schema);

        return $schema;
    }

    protected function getDominantColors(Asset $asset): array
    {
        $metadata = $asset->metadata ?? [];
        $colors = $metadata['dominant_colors'] ?? null;
        if (! is_array($colors)) {
            $fieldId = DB::table('metadata_fields')->where('key', 'dominant_colors')->value('id');
            if ($fieldId) {
                $row = DB::table('asset_metadata')
                    ->where('asset_id', $asset->id)
                    ->where('metadata_field_id', $fieldId)
                    ->whereNotNull('approved_at')
                    ->value('value_json');
                if ($row) {
                    $colors = json_decode($row, true);
                }
            }
        }

        return is_array($colors) ? $colors : [];
    }

    protected function getExtractedText(Asset $asset): ?string
    {
        $extraction = $asset->getLatestPdfTextExtractionForVersion($asset->currentVersion?->id);
        if (! $extraction || ! $extraction->isComplete()) {
            return null;
        }

        return trim($extraction->extracted_text ?? '');
    }

    protected function computeConfidence(array $schema): float
    {
        $signals = 0;
        if (! empty($schema['visual']['primary_colors'])) {
            $signals++;
        }
        if (! empty($schema['identity']['mission']) || ! empty($schema['identity']['positioning'])) {
            $signals++;
        }

        return min(1.0, $signals * 0.3);
    }
}
