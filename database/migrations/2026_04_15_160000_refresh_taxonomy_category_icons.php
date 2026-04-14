<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Align system category and linked brand category icons with catalog templates
     * (same mapping as SystemCategoryTemplateSeeder / CategoryIcons::getDefaultIcon by slug).
     */
    public function up(): void
    {
        $map = [
            ['photography', 'asset', 'photo'],
            ['graphics', 'asset', 'paint-brush'],
            ['logos', 'asset', 'star'],
            ['video', 'asset', 'video'],
            ['audio', 'asset', 'music'],
            ['documents', 'asset', 'document'],
            ['templates', 'asset', 'cube'],
            ['fonts', 'asset', 'bookmark'],
            ['model-3d', 'asset', 'cube'],
            ['illustrations', 'asset', 'sparkles'],
            ['brand-elements', 'asset', 'puzzle'],
            ['social', 'deliverable', 'user-group'],
            ['digital-ads', 'deliverable', 'chart-bar'],
            ['print', 'deliverable', 'document'],
            ['videos', 'deliverable', 'film'],
            ['packaging', 'deliverable', 'gift'],
            ['ooh', 'deliverable', 'map-pin'],
            ['sales-collateral', 'deliverable', 'clipboard'],
            ['pr', 'deliverable', 'megaphone'],
            ['events', 'deliverable', 'calendar'],
            ['web', 'deliverable', 'cloud'],
            ['email', 'deliverable', 'envelope'],
            ['product-renders', 'deliverable', 'camera'],
            ['radio', 'deliverable', 'microphone'],
            ['reference_material', 'reference', 'folder'],
        ];

        foreach ($map as [$slug, $assetType, $icon]) {
            DB::table('system_categories')
                ->where('slug', $slug)
                ->where('asset_type', $assetType)
                ->where('version', 1)
                ->update(['icon' => $icon]);
        }

        DB::update(
            'UPDATE categories SET icon = (
                SELECT sc.icon FROM system_categories sc WHERE sc.id = categories.system_category_id
            ) WHERE is_system = 1 AND system_category_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // Non-reversible: prior icon values are not stored.
    }
};
