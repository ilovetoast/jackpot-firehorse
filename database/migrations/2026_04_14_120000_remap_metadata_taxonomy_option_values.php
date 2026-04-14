<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remap stored asset_metadata select values after taxonomy normalization (JSON string per row).
 * Safe to run once; no-op when tables or fields are missing.
 */
return new class extends Migration
{
    /**
     * @var array<string, list<array{0: string, 1: string}>>
     */
    protected array $fieldValueRemaps = [
        'video_type' => [
            ['interviews', 'interview'],
        ],
        'font_role' => [
            ['body_copy', 'body'],
        ],
        'execution_video_type' => [
            ['explainer_video', 'explainer'],
            ['product_demos', 'product_demo'],
        ],
        'digital_type' => [
            ['display_ads', 'display_ad'],
        ],
        'print_type' => [
            ['ads', 'ad'],
            ['brochures', 'brochure'],
            ['posters', 'poster'],
            ['inserts', 'insert'],
        ],
        'ooh_type' => [
            ['billboards', 'billboard'],
        ],
        'event_type' => [
            ['booths', 'booth'],
            ['experiential', 'experiential_installation'],
        ],
        'sales_collateral_type' => [
            ['catalogs', 'catalog'],
            ['sales_sheets', 'sales_sheet'],
            ['trade_show_materials', 'trade_show_material'],
        ],
        'pr_type' => [
            ['press_releases', 'press_release'],
            ['media_kits', 'media_kit'],
            ['backgrounders', 'backgrounder'],
        ],
        'packaging_type' => [
            ['renders_3d', 'render'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('metadata_fields') || ! Schema::hasTable('asset_metadata')) {
            return;
        }

        foreach ($this->fieldValueRemaps as $fieldKey => $pairs) {
            $fieldId = DB::table('metadata_fields')->where('key', $fieldKey)->value('id');
            if (! $fieldId) {
                continue;
            }
            foreach ($pairs as [$from, $to]) {
                DB::table('asset_metadata')
                    ->where('metadata_field_id', $fieldId)
                    ->where('value_json', json_encode($from))
                    ->update([
                        'value_json' => json_encode($to),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally empty: reversing would corrupt data merged into new canonical values.
    }
};
