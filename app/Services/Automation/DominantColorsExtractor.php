<?php

namespace App\Services\Automation;

use App\Models\Asset;
use App\Services\Color\HueClusterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dominant Colors Extractor Service
 *
 * Extracts the top 3 dominant colors from color analysis cluster data
 * and persists them as hex + RGB values in asset.metadata['dominant_colors'].
 *
 * Upstream color analysis (ColorAnalysisService) always runs against the asset's
 * generated thumbnail image (JPEG/PNG), never the original file; this extractor
 * consumes the resulting cluster data only.
 *
 * Rules:
 * - Only processes image assets
 * - Uses existing _color_analysis cluster data
 * - Filters clusters by coverage >= 10%
 * - Returns max 3 colors, sorted by coverage descending
 * - Read-only, not filterable, not shown in UI
 */
class DominantColorsExtractor
{
    /**
     * Minimum coverage threshold for a cluster to be considered dominant.
     */
    protected const COVERAGE_THRESHOLD = 0.10; // 10%

    /**
     * Maximum number of dominant colors to extract.
     */
    protected const MAX_COLORS = 3;

    /**
     * Extract and persist dominant colors for an asset.
     *
     * @param Asset $asset
     * @return void
     */
    public function extractAndPersist(Asset $asset): void
    {
        if (!$asset->visualMetadataReady()) {
            return;
        }

        // Extract dominant colors from cluster data
        $dominantColors = $this->extractDominantColors($asset);
        
        if ($dominantColors === null) {
            // No cluster data available or extraction failed
            return;
        }

        // Persist to asset.metadata
        $this->persistDominantColors($asset, $dominantColors);

        // Assign dominant_hue_group from highest-weight dominant color via HueClusterService
        $this->persistDominantHueGroup($asset, $dominantColors);
    }

    /**
     * Extract dominant colors from color analysis cluster data.
     *
     * @param Asset $asset
     * @return array|null Array of color objects or null if extraction fails
     */
    protected function extractDominantColors(Asset $asset): ?array
    {
        $metadata = $asset->metadata ?? [];
        
        // Check if color analysis data exists
        if (!isset($metadata['_color_analysis']['clusters']) || !is_array($metadata['_color_analysis']['clusters'])) {
            Log::debug('[DominantColorsExtractor] No color analysis cluster data found', [
                'asset_id' => $asset->id,
            ]);
            return null;
        }

        $clusters = $metadata['_color_analysis']['clusters'];
        
        if (empty($clusters)) {
            Log::debug('[DominantColorsExtractor] Empty cluster data', [
                'asset_id' => $asset->id,
            ]);
            return null;
        }

        // Filter clusters by coverage threshold and take top 3
        $dominantClusters = $this->filterAndSelectTopClusters($clusters);
        
        if (empty($dominantClusters)) {
            Log::debug('[DominantColorsExtractor] No clusters meet coverage threshold', [
                'asset_id' => $asset->id,
                'threshold' => self::COVERAGE_THRESHOLD,
            ]);
            return null;
        }

        // Convert clusters to color objects
        $colors = [];
        foreach ($dominantClusters as $cluster) {
            $color = $this->clusterToColorObject($cluster);
            if ($color !== null) {
                $colors[] = $color;
            }
        }

        return empty($colors) ? null : $colors;
    }

    /**
     * Filter clusters by coverage threshold and select top N by coverage.
     *
     * @param array $clusters Array of cluster data
     * @return array Filtered and sorted clusters
     */
    protected function filterAndSelectTopClusters(array $clusters): array
    {
        // Filter by coverage threshold
        $filtered = array_filter($clusters, function ($cluster) {
            $coverage = $cluster['coverage'] ?? 0.0;
            return is_numeric($coverage) && $coverage >= self::COVERAGE_THRESHOLD;
        });

        // Sort by coverage descending (clusters should already be sorted, but ensure it)
        usort($filtered, function ($a, $b) {
            $coverageA = $a['coverage'] ?? 0.0;
            $coverageB = $b['coverage'] ?? 0.0;
            return $coverageB <=> $coverageA;
        });

        // Take top N
        return array_slice($filtered, 0, self::MAX_COLORS);
    }

    /**
     * Convert a cluster to a color object with hex and RGB.
     *
     * @param array $cluster Cluster data with 'rgb' and 'coverage' keys
     * @return array|null Color object or null if conversion fails
     */
    protected function clusterToColorObject(array $cluster): ?array
    {
        if (!isset($cluster['rgb']) || !is_array($cluster['rgb'])) {
            Log::warning('[DominantColorsExtractor] Cluster missing RGB data', [
                'cluster_keys' => array_keys($cluster),
            ]);
            return null;
        }

        $rgb = $cluster['rgb'];
        
        // Validate and normalize RGB values
        if (count($rgb) < 3) {
            Log::warning('[DominantColorsExtractor] Invalid RGB array length', [
                'rgb_count' => count($rgb),
            ]);
            return null;
        }

        // Extract and clamp RGB values to 0-255
        $r = $this->clampColorValue($rgb[0] ?? 0);
        $g = $this->clampColorValue($rgb[1] ?? 0);
        $b = $this->clampColorValue($rgb[2] ?? 0);

        // Convert to hex
        $hex = $this->rgbToHex($r, $g, $b);

        // Get coverage (preserve as float)
        $coverage = $cluster['coverage'] ?? 0.0;
        if (!is_numeric($coverage)) {
            $coverage = 0.0;
        }

        return [
            'hex' => $hex,
            'rgb' => [$r, $g, $b],
            'coverage' => (float) $coverage,
        ];
    }

    /**
     * Clamp color value to 0-255 range.
     *
     * @param mixed $value
     * @return int
     */
    protected function clampColorValue($value): int
    {
        $intValue = (int) round((float) $value);
        return max(0, min(255, $intValue));
    }

    /**
     * Convert RGB values to uppercase hex format.
     *
     * @param int $r Red (0-255)
     * @param int $g Green (0-255)
     * @param int $b Blue (0-255)
     * @return string Hex color in format #RRGGBB
     */
    protected function rgbToHex(int $r, int $g, int $b): string
    {
        // Ensure values are in valid range
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));

        // Convert to uppercase hex with leading zeros
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /**
     * Persist dominant colors to asset.metadata and to asset_metadata (canonical).
     *
     * Writes to both:
     * - assets.metadata['dominant_colors'] (legacy, preserved)
     * - asset_metadata table via metadata field key dominant_colors (canonical for filters/UI)
     *
     * @param Asset $asset
     * @param array $colors Array of color objects with hex, rgb, coverage
     * @return void
     */
    protected function persistDominantColors(Asset $asset, array $colors): void
    {
        // Preserve existing write to assets.metadata
        $metadata = $asset->metadata ?? [];
        $metadata['dominant_colors'] = $colors;
        $asset->update(['metadata' => $metadata]);

        // Canonical: persist to asset_metadata so filters, drawer, and schema see the same data
        $field = DB::table('metadata_fields')->where('key', 'dominant_colors')->first();
        if ($field) {
            $valueJson = json_encode($colors);
            $existing = DB::table('asset_metadata')
                ->where('asset_id', $asset->id)
                ->where('metadata_field_id', $field->id)
                ->first();

            if ($existing) {
                $oldValueJson = $existing->value_json;
                DB::table('asset_metadata')
                    ->where('id', $existing->id)
                    ->update([
                        'value_json' => $valueJson,
                        'source' => 'system',
                        'confidence' => 0.95,
                        'producer' => 'system',
                        'approved_at' => now(),
                        'approved_by' => null,
                        'updated_at' => now(),
                    ]);
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $existing->id,
                    'old_value_json' => $oldValueJson,
                    'new_value_json' => $valueJson,
                    'source' => 'system',
                    'changed_by' => null,
                    'created_at' => now(),
                ]);
            } else {
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $field->id,
                    'value_json' => $valueJson,
                    'source' => 'system',
                    'confidence' => 0.95,
                    'producer' => 'system',
                    'approved_at' => now(),
                    'approved_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => null,
                    'new_value_json' => $valueJson,
                    'source' => 'system',
                    'changed_by' => null,
                    'created_at' => now(),
                ]);
            }
        }

        Log::info('[DominantColorsExtractor] Dominant colors persisted', [
            'asset_id' => $asset->id,
            'colors_count' => count($colors),
        ]);
    }

    /**
     * Assign and persist dominant_hue_group from highest-weight dominant color.
     * Uses HueClusterService for perceptual cluster assignment.
     *
     * @param Asset $asset
     * @param array $dominantColors Array of color objects (from extractDominantColors), sorted by coverage desc
     * @return void
     */
    protected function persistDominantHueGroup(Asset $asset, array $dominantColors): void
    {
        if (empty($dominantColors)) {
            return;
        }

        $topColor = $dominantColors[0];
        $hex = $topColor['hex'] ?? null;
        if (!$hex || !is_string($hex)) {
            return;
        }

        $hueClusterService = app(HueClusterService::class);
        $clusterKey = $hueClusterService->assignClusterFromHex($hex);

        if ($clusterKey === null) {
            Log::debug('[DominantColorsExtractor] No hue cluster found for dominant color', [
                'asset_id' => $asset->id,
                'hex' => $hex,
            ]);
            return;
        }

        // Persist to assets.dominant_hue_group column
        $asset->update(['dominant_hue_group' => $clusterKey]);

        // Persist to asset_metadata (canonical for filters/schema)
        $field = DB::table('metadata_fields')->where('key', 'dominant_hue_group')->first();
        if ($field) {
            $valueJson = json_encode($clusterKey);
            $existing = DB::table('asset_metadata')
                ->where('asset_id', $asset->id)
                ->where('metadata_field_id', $field->id)
                ->first();

            if ($existing) {
                $oldValueJson = $existing->value_json;
                DB::table('asset_metadata')
                    ->where('id', $existing->id)
                    ->update([
                        'value_json' => $valueJson,
                        'source' => 'system',
                        'confidence' => 0.95,
                        'producer' => 'system',
                        'approved_at' => now(),
                        'approved_by' => null,
                        'updated_at' => now(),
                    ]);
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $existing->id,
                    'old_value_json' => $oldValueJson,
                    'new_value_json' => $valueJson,
                    'source' => 'system',
                    'changed_by' => null,
                    'created_at' => now(),
                ]);
            } else {
                $assetMetadataId = DB::table('asset_metadata')->insertGetId([
                    'asset_id' => $asset->id,
                    'metadata_field_id' => $field->id,
                    'value_json' => $valueJson,
                    'source' => 'system',
                    'confidence' => 0.95,
                    'producer' => 'system',
                    'approved_at' => now(),
                    'approved_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('asset_metadata_history')->insert([
                    'asset_metadata_id' => $assetMetadataId,
                    'old_value_json' => null,
                    'new_value_json' => $valueJson,
                    'source' => 'system',
                    'changed_by' => null,
                    'created_at' => now(),
                ]);
            }
        }

        Log::info('[DominantColorsExtractor] Dominant hue group persisted', [
            'asset_id' => $asset->id,
            'dominant_hue_group' => $clusterKey,
        ]);
    }

}
