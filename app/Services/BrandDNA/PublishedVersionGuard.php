<?php

namespace App\Services\BrandDNA;

use App\Models\BrandModelVersion;

/**
 * Publish safeguard: prevents editing structural fields on published (active) versions.
 * User must create a new version (version bump) to edit.
 */
class PublishedVersionGuard
{
    protected const STRUCTURAL_PATHS = [
        'personality.primary_archetype',
        'identity.mission',
        'identity.positioning',
        'scoring_rules.tone_keywords',
        'scoring_rules.allowed_color_palette',
        'typography.primary_font',
    ];

    /**
     * Check if patch touches any structural field.
     */
    public function patchTouchesStructuralField(array $patch): bool
    {
        foreach (self::STRUCTURAL_PATHS as $path) {
            if ($this->patchContainsPath($patch, $path)) {
                return true;
            }
        }

        return false;
    }

    protected function patchContainsPath(array $patch, string $dotPath): bool
    {
        $segments = explode('.', $dotPath);
        $current = $patch;
        foreach ($segments as $seg) {
            if (! is_array($current) || ! array_key_exists($seg, $current)) {
                return false;
            }
            $current = $current[$seg];
        }

        return true;
    }

    /**
     * Check if suggestion path is a structural field.
     */
    public function suggestionTouchesStructuralField(array $suggestion): bool
    {
        $path = $suggestion['path'] ?? '';
        if ($path === '') {
            return false;
        }

        return in_array($path, self::STRUCTURAL_PATHS, true);
    }

    /**
     * Returns true if the version is published (active) and should block structural edits.
     */
    public function isPublished(BrandModelVersion $version): bool
    {
        if ($version->status === 'active') {
            return true;
        }
        $brandModel = $version->brandModel;
        if ($brandModel && $brandModel->active_version_id === $version->id) {
            return true;
        }

        return false;
    }
}
