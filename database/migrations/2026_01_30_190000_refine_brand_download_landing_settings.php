<?php

/**
 * D10 — Refine brand download_landing_settings JSON structure.
 *
 * New shape: enabled, logo_asset_id (uuid|null), color_role (primary|secondary|accent),
 * background_asset_ids (max 5), default_headline, default_subtext.
 * No logo_url or accent_color (visuals from brand palette / logo assets).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $brands = DB::table('brands')->whereNotNull('download_landing_settings')->get();

        foreach ($brands as $brand) {
            $raw = json_decode($brand->download_landing_settings, true);
            if (! is_array($raw)) {
                continue;
            }

            $out = [
                'enabled' => $raw['enabled'] ?? false,
                'logo_asset_id' => null,
                'color_role' => 'primary',
                'background_asset_ids' => [],
                'default_headline' => $raw['default_headline'] ?? null,
                'default_subtext' => $raw['default_subtext'] ?? null,
            ];

            if (isset($raw['background_asset_ids']) && is_array($raw['background_asset_ids'])) {
                $ids = array_values(array_filter(array_slice($raw['background_asset_ids'], 0, 5), function ($id) {
                    return is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id);
                }));
                $out['background_asset_ids'] = $ids;
            }

            DB::table('brands')
                ->where('id', $brand->id)
                ->update(['download_landing_settings' => json_encode($out)]);
        }
    }

    public function down(): void
    {
        // Reverting would require restoring legacy logo_url/accent_color from unknown source; leave as-is.
    }
};
