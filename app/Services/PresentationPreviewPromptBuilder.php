<?php

namespace App\Services;

use App\Models\Asset;

/**
 * Builds an image-edit prompt for {@see GeneratePresentationPreviewJob} from asset context.
 */
final class PresentationPreviewPromptBuilder
{
    /**
     * @param  string|null  $userSceneDescription  Optional environment from the user (e.g. "Architect's desk").
     */
    public function build(Asset $asset, ?string $userSceneDescription = null): string
    {
        $asset->loadMissing(['category']);

        $prefix = (string) config(
            'presentation_preview.ai_instruction_prefix',
            'Take this piece of creative and preserve it exactly: do not modify, redraw, replace, or crop away the artwork itself—only integrate it believably into a scene. The output will be used for marketing presentations and asset reviews. '
        );

        $maxLen = max(32, (int) config('presentation_preview.max_scene_description_length', 500));
        $user = is_string($userSceneDescription) ? trim($userSceneDescription) : '';
        if ($user !== '' && strlen($user) > $maxLen) {
            $user = substr($user, 0, $maxLen);
        }

        $context = $this->assetContextSummary($asset);

        if ($user !== '') {
            $mid = 'Environment / placement for the scene (from user): '.$user.'. '
                .'You may add subtle props or surroundings that match this setting; the supplied creative must remain visually dominant and unchanged in content. '
                ."Additional asset context (hints only): {$context}. ";
        } else {
            $mid = 'Create a polished marketing presentation frame around this image. Preserve text legibility and brand colors where visible. '
                ."Use professional lighting, subtle depth, and a clean neutral treatment suitable for: {$context}. "
                .'For print-ready or document-style artwork, present it as if photographed on a real surface '
                .'(for example a light wood table or desk) with soft natural ambient light, slide-deck cover style. ';
        }

        $tail = 'Output a single high-quality still; do not add watermarks or UI chrome.';

        return $prefix.$mid.$tail;
    }

    /**
     * Short human-readable hints from category + metadata (not instructions on their own).
     */
    private function assetContextSummary(Asset $asset): string
    {
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

        return $lines === [] ? 'general brand asset' : implode('; ', $lines);
    }
}
