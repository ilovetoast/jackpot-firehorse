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
     * Activate a version (publish flow).
     *
     * When the user finishes the wizard:
     * - Sets brand_models.active_version_id = version.id
     * - Marks the draft version as active (status=active)
     * - Archives the previous active version (status=archived)
     * - Syncs core brand colors into allowed_color_palette if not already present
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

            // Sync visual references to brand_visual_references for embeddings
            app(BrandVisualReferenceSyncService::class)->syncFromVersion($version);
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
     * After brand identity colors change: fill Standards on the **published** (active) DNA
     * version only — never a draft — when scoring has no allowed palette yet and/or no
     * allowed fonts yet (fonts are copied from typography on that same version).
     */
    public function syncPublishedStandardsFromBrandWhenUnset(Brand $brand): ?BrandModelVersion
    {
        $brandModel = $brand->brandModel;
        if (! $brandModel || ! $brandModel->active_version_id) {
            return null;
        }

        $version = BrandModelVersion::query()
            ->where('id', $brandModel->active_version_id)
            ->where('brand_model_id', $brandModel->id)
            ->where('status', 'active')
            ->first();

        if (! $version) {
            return null;
        }

        $payload = is_array($version->model_payload) ? $version->model_payload : [];
        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];
        $palette = is_array($rules['allowed_color_palette'] ?? null) ? $rules['allowed_color_palette'] : [];

        $changed = false;

        if (! $this->scoringColorPaletteHasEntries($palette)) {
            $newPalette = $palette;
            $existingHexes = $this->collectPaletteHexesNormalized($newPalette);
            $coreColors = [];
            if ($brand->primary_color) {
                $coreColors['primary'] = $this->normalizeHex((string) $brand->primary_color);
            }
            if ($brand->secondary_color) {
                $coreColors['secondary'] = $this->normalizeHex((string) $brand->secondary_color);
            }
            if ($brand->accent_color) {
                $coreColors['accent'] = $this->normalizeHex((string) $brand->accent_color);
            }
            $paletteUpdated = false;
            foreach ($coreColors as $role => $hex) {
                if ($hex !== '' && preg_match('/^#[0-9a-f]{6}$/', $hex) === 1 && ! in_array($hex, $existingHexes, true)) {
                    $newPalette[] = ['hex' => $hex, 'role' => $role];
                    $existingHexes[] = $hex;
                    $paletteUpdated = true;
                }
            }
            if ($paletteUpdated) {
                $rules['allowed_color_palette'] = $newPalette;
                $payload['scoring_rules'] = $rules;
                $changed = true;
            }
        }

        $rules = is_array($payload['scoring_rules'] ?? null) ? $payload['scoring_rules'] : [];
        $allowedFonts = $rules['allowed_fonts'] ?? [];
        if (! is_array($allowedFonts)) {
            $allowedFonts = [];
        }
        if (! $this->allowedFontsListHasEntries($allowedFonts)) {
            $typography = is_array($payload['typography'] ?? null) ? $payload['typography'] : [];
            $fontNames = $this->collectTypographyFontNames($typography);
            if ($fontNames !== []) {
                $rules['allowed_fonts'] = array_values(array_unique($fontNames));
                $payload['scoring_rules'] = $rules;
                $changed = true;
            }
        }

        if (! $changed) {
            return null;
        }

        $version->update(['model_payload' => $payload]);

        return $version->fresh();
    }

    /**
     * @param  list<mixed>  $palette
     */
    private function scoringColorPaletteHasEntries(array $palette): bool
    {
        foreach ($palette as $c) {
            if ($this->extractPaletteEntryHex($c) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $palette
     * @return list<string>
     */
    private function collectPaletteHexesNormalized(array $palette): array
    {
        $out = [];
        foreach ($palette as $c) {
            $h = $this->extractPaletteEntryHex($c);
            if ($h !== null) {
                $out[] = $h;
            }
        }

        return array_values(array_unique($out));
    }

    private function extractPaletteEntryHex(mixed $entry): ?string
    {
        $raw = null;
        if (is_string($entry) && trim($entry) !== '') {
            $raw = $entry;
        } elseif (is_array($entry)) {
            $candidate = $entry['hex'] ?? $entry['value'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $raw = $candidate;
            } elseif (is_array($candidate)) {
                $raw = $this->unwrapStringScalar($candidate);
                if ($raw === '') {
                    return null;
                }
            }
        }
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $norm = $this->normalizeHex($raw);

        return preg_match('/^#[0-9a-f]{6}$/', $norm) === 1 ? $norm : null;
    }

    /**
     * @param  list<mixed>  $fonts
     */
    private function allowedFontsListHasEntries(array $fonts): bool
    {
        foreach ($fonts as $f) {
            if (is_string($f) && trim($f) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $typography
     * @return list<string>
     */
    private function collectTypographyFontNames(array $typography): array
    {
        $names = [];
        foreach (['primary_font', 'secondary_font'] as $key) {
            $s = $this->unwrapStringScalar($typography[$key] ?? null);
            if ($s !== '') {
                $names[] = $s;
            }
        }
        $fonts = $typography['fonts'] ?? null;
        if (is_array($fonts)) {
            foreach ($fonts as $f) {
                if (is_string($f) && trim($f) !== '') {
                    $names[] = trim($f);
                } elseif (is_array($f)) {
                    $n = $this->unwrapStringScalar($f['name'] ?? null);
                    if ($n !== '') {
                        $names[] = $n;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $names))));
    }

    private function unwrapStringScalar(mixed $val): string
    {
        if ($val === null) {
            return '';
        }
        if (is_string($val)) {
            return trim($val);
        }
        if (is_array($val) && array_key_exists('value', $val)) {
            $inner = $val['value'] ?? null;

            return is_string($inner) ? trim($inner) : '';
        }

        return '';
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
