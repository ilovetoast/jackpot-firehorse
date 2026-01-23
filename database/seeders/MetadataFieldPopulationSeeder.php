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
     * 
     * Configures system fields that are automatically populated:
     * - Hidden from upload form (show_on_upload = false)
     * - Visible in edit but readonly (show_on_edit = true, readonly = true)
     * - Available in grid filters (show_in_filters = true)
     * - Automatically populated by system (population_mode = 'automatic')
     */
    public function run(): void
    {
        // Fields that should be auto-populated, hidden from upload, readonly, but filterable
        $automaticFields = [
            'orientation',
            'dimensions',
            'color_mode',
            'color_space',
            'resolution_class',
        ];

        foreach ($automaticFields as $fieldKey) {
            DB::table('metadata_fields')
                ->where('key', $fieldKey)
                ->update([
                    'population_mode' => 'automatic',
                    'show_on_upload' => false, // Hidden from upload form
                    'show_on_edit' => true, // Visible in edit (readonly)
                    'show_in_filters' => true, // Available in grid filters
                    'readonly' => true, // Read-only for users (system can populate)
                    'updated_at' => now(),
                ]);
        }
    }
}
