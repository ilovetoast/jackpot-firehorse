<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Metadata Field Population Seeder
 *
 * Phase B2: Updates existing metadata fields to mark automatic fields.
 * Sets at least one field (e.g., orientation) as automatically populated.
 */
class MetadataFieldPopulationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Update orientation field to be automatic (if it exists)
        DB::table('metadata_fields')
            ->where('key', 'orientation')
            ->update([
                'population_mode' => 'automatic',
                'show_on_upload' => false,
                'show_on_edit' => true, // Still visible in edit view
                'show_in_filters' => true, // Still filterable
                'readonly' => true, // Read-only since it's automatic
                'updated_at' => now(),
            ]);

        // Update dimensions field to be automatic (if it exists)
        DB::table('metadata_fields')
            ->where('key', 'dimensions')
            ->update([
                'population_mode' => 'automatic',
                'show_on_upload' => false,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'readonly' => true,
                'updated_at' => now(),
            ]);

        // Update color_mode/color_space field to be automatic (if it exists)
        DB::table('metadata_fields')
            ->whereIn('key', ['color_mode', 'color_space'])
            ->update([
                'population_mode' => 'automatic',
                'show_on_upload' => false,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'readonly' => true,
                'updated_at' => now(),
            ]);

        // Update resolution_class field to be automatic (if it exists)
        DB::table('metadata_fields')
            ->where('key', 'resolution_class')
            ->update([
                'population_mode' => 'automatic',
                'show_on_upload' => false,
                'show_on_edit' => true,
                'show_in_filters' => true,
                'readonly' => true,
                'updated_at' => now(),
            ]);
    }
}
