<?php

namespace App\Services\BrandDNA\Extraction;

class WebsiteExtractionProcessor
{
    public function process(array $crawlResult): array
    {
        $schema = BrandExtractionSchema::empty();
        $primaryColors = $crawlResult['primary_colors'] ?? [];
        $schema['visual'] = [
            'primary_colors' => array_map(fn ($c) => is_string($c) ? $c : ($c['hex'] ?? (string)$c), $primaryColors),
            'secondary_colors' => [],
            'fonts' => $crawlResult['detected_fonts'] ?? [],
            'logo_detected' => $crawlResult['logo_url'] ?? null,
        ];
        $schema['identity']['positioning'] = $crawlResult['brand_bio'] ?? null;
        $schema['sources']['website'] = [
            'hero_headlines' => $crawlResult['hero_headlines'] ?? [],
            'brand_bio' => $crawlResult['brand_bio'] ?? null,
        ];
        $schema['confidence'] = $this->computeConfidence($schema);
        return $schema;
    }

    protected function computeConfidence(array $schema): float
    {
        $signals = 0;
        if (!empty($schema['visual']['primary_colors'])) $signals++;
        if (!empty($schema['visual']['fonts'])) $signals++;
        if ($schema['visual']['logo_detected']) $signals++;
        if ($schema['identity']['positioning']) $signals++;
        return min(1.0, $signals * 0.25);
    }
}
