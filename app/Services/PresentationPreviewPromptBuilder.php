<?php

namespace App\Services;

use App\Models\Asset;

/**
 * Builds an image-edit prompt for {@see GeneratePresentationPreviewJob} from asset context.
 */
final class PresentationPreviewPromptBuilder
{
    /**
     * Uses category plus metadata hints: usage, type, print_type, asset_type.
     */
    public function build(Asset $asset): string
    {
        $asset->loadMissing(['category']);

        $lines = [];
        $categoryName = $asset->category?->name;
        if (is_string($categoryName) && trim($categoryName) !== '') {
            $lines[] = 'Category: '.trim($categoryName);
        }

        $meta = is_array($asset->metadata) ? $asset->metadata : [];
        foreach (['usage', 'type', 'print_type', 'asset_type'] as $key) {
            $v = $meta[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $lines[] = ucfirst(str_replace('_', ' ', $key)).': '.trim($v);
            }
        }

        $context = $lines === [] ? 'general brand asset' : implode('; ', $lines);

        return 'Create a polished, context-aware marketing presentation version of this image. '
            .'Preserve the main subject, text legibility, and brand colors where visible. '
            ."Use professional lighting, subtle depth, and a clean neutral treatment suitable for: {$context}. "
            .'For print-ready or document-style artwork, present it as if photographed on a real surface '
            .'(for example a light wood table or desk) with soft natural ambient light, slide-deck cover style. '
            .'Output a single high-quality still; do not add watermarks or UI chrome.';
    }
}
