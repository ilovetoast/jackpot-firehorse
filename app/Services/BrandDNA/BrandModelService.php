<?php

namespace App\Services\BrandDNA;

use App\Models\Brand;
use App\Models\BrandModel;
use App\Models\BrandModelVersion;
use Illuminate\Support\Facades\DB;

/**
 * Brand DNA / Brand Guidelines — service layer.
 * Manages versioned JSON models for brands.
 */
class BrandModelService
{
    /**
     * Create the initial version for a brand's model.
     */
    public function createInitialVersion(Brand $brand, array $payload, string $source = 'manual'): BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (!$brandModel) {
            $brandModel = $brand->brandModel()->create(['is_enabled' => false]);
        }

        return DB::transaction(function () use ($brandModel, $payload, $source) {
            $versionNumber = $brandModel->versions()->max('version_number') + 1;

            return $brandModel->versions()->create([
                'version_number' => $versionNumber,
                'source_type' => $source,
                'model_payload' => $payload,
                'metrics_payload' => null,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Activate a version. Sets previous active → archived, updates brand_models.active_version_id.
     * Syncs core brand colors (primary, secondary, accent) into allowed_color_palette if not already present.
     */
    public function activateVersion(BrandModelVersion $version): void
    {
        DB::transaction(function () use ($version) {
            $brandModel = $version->brandModel;
            $brand = $brandModel->brand;

            // Archive previous active version
            $brandModel->versions()
                ->where('status', 'active')
                ->update(['status' => 'archived']);

            // Activate this version
            $version->update(['status' => 'active']);

            // Update brand model pointer
            $brandModel->update(['active_version_id' => $version->id]);

            // Sync core brand colors into allowed_color_palette
            $this->syncCoreColorsIntoPalette($version, $brand);
        });
    }

    /**
     * Ensure primary, secondary, accent from brand/visual are in allowed_color_palette.
     */
    protected function syncCoreColorsIntoPalette(BrandModelVersion $version, Brand $brand): void
    {
        $payload = $version->model_payload ?? [];
        $rules = $payload['scoring_rules'] ?? [];
        $palette = $rules['allowed_color_palette'] ?? [];

        $coreColors = [];
        $visual = $payload['visual'] ?? [];
        $colorSystem = $visual['color_system'] ?? [];
        if (! empty($colorSystem['primary'])) {
            $coreColors['primary'] = $this->normalizeHex($colorSystem['primary']);
        }
        if (! empty($colorSystem['secondary'])) {
            $coreColors['secondary'] = $this->normalizeHex($colorSystem['secondary']);
        }
        if (! empty($colorSystem['accent'])) {
            $coreColors['accent'] = $this->normalizeHex($colorSystem['accent']);
        }
        if (empty($coreColors)) {
            if ($brand->primary_color) {
                $coreColors['primary'] = $this->normalizeHex($brand->primary_color);
            }
            if ($brand->secondary_color) {
                $coreColors['secondary'] = $this->normalizeHex($brand->secondary_color);
            }
            if ($brand->accent_color) {
                $coreColors['accent'] = $this->normalizeHex($brand->accent_color);
            }
        }

        $existingHexes = [];
        foreach ($palette as $c) {
            $h = is_array($c) ? ($c['hex'] ?? null) : $c;
            if (is_string($h)) {
                $existingHexes[] = $this->normalizeHex($h);
            }
        }

        $added = [];
        foreach ($coreColors as $role => $hex) {
            if ($hex && ! in_array($hex, $existingHexes, true)) {
                $palette[] = ['hex' => $hex, 'role' => $role];
                $existingHexes[] = $hex;
                $added[] = $hex;
            }
        }

        if (! empty($added)) {
            $payload['scoring_rules'] = array_merge($rules, ['allowed_color_palette' => $palette]);
            $version->update(['model_payload' => $payload]);
        }
    }

    protected function normalizeHex(string $value): string
    {
        $v = strtolower(trim(str_replace(' ', '', $value)));
        if ($v !== '' && $v[0] !== '#') {
            $v = '#' . $v;
        }

        return $v;
    }

    /**
     * Create a new version from the existing active version (or empty if none).
     */
    public function createNewVersionFromExisting(Brand $brand): BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (!$brandModel) {
            $brandModel = $brand->brandModel()->create(['is_enabled' => false]);
        }

        $activeVersion = $brandModel->activeVersion;
        $payload = $activeVersion
            ? $activeVersion->model_payload
            : [];

        return $this->createInitialVersion($brand, $payload, 'manual');
    }

    /**
     * Update the active version's model_payload.
     */
    public function updateActiveVersionPayload(Brand $brand, array $payload): ?BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (!$brandModel) {
            return null;
        }

        $activeVersion = $brandModel->activeVersion;
        if (!$activeVersion) {
            return null;
        }

        $activeVersion->update(['model_payload' => $payload]);
        return $activeVersion->fresh();
    }

    /**
     * Update a specific version's model_payload (draft or active).
     * Ensures version belongs to brand's model.
     */
    public function updateVersionPayload(Brand $brand, BrandModelVersion $version, array $payload): ?BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (!$brandModel || $version->brand_model_id !== $brandModel->id) {
            return null;
        }

        $version->update(['model_payload' => $payload]);
        return $version->fresh();
    }

    /**
     * Get the active model payload for a brand, or null if none.
     */
    public function getActiveModel(Brand $brand): ?array
    {
        $brandModel = $brand->brandModel;
        if (!$brandModel) {
            return null;
        }

        $activeVersion = $brandModel->activeVersion;
        if (!$activeVersion) {
            return null;
        }

        return $activeVersion->model_payload;
    }
}
