<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Filter-Only Metadata Fields Seeder
 *
 * Phase B3: Creates metadata fields that are filter-only:
 * - Do NOT appear in upload UI (show_on_upload = false)
 * - Do NOT appear in edit UI (show_on_edit = false)
 * - DO appear in grid filters (show_in_filters = true)
 *
 * These fields are typically AI-generated or automatically populated.
 */
class FilterOnlyMetadataFieldsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // AI Detected Objects (multiselect)
        $aiDetectedObjectsId = $this->createField([
            'key' => 'ai_detected_objects',
            'system_label' => 'AI Detected Objects',
            'type' => 'multiselect',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => false, // Not user-editable (automatic)
            'is_ai_trainable' => true,
            'is_upload_visible' => false,
            'is_internal_only' => false,
            'group_key' => 'ai_analysis',
            'population_mode' => 'automatic',
            'show_on_upload' => false,
            'show_on_edit' => false,
            'show_in_filters' => true,
            'readonly' => true,
        ]);

        // Add options for AI Detected Objects
        $this->createOptions($aiDetectedObjectsId, [
            ['value' => 'person', 'label' => 'Person'],
            ['value' => 'vehicle', 'label' => 'Vehicle'],
            ['value' => 'animal', 'label' => 'Animal'],
            ['value' => 'building', 'label' => 'Building'],
            ['value' => 'food', 'label' => 'Food'],
            ['value' => 'nature', 'label' => 'Nature'],
            ['value' => 'text', 'label' => 'Text'],
            ['value' => 'logo', 'label' => 'Logo'],
            ['value' => 'product', 'label' => 'Product'],
        ]);

        // AI Color Palette (multiselect)
        $aiColorPaletteId = $this->createField([
            'key' => 'ai_color_palette',
            'system_label' => 'AI Color Palette',
            'type' => 'multiselect',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => false,
            'is_ai_trainable' => true,
            'is_upload_visible' => false,
            'is_internal_only' => false,
            'group_key' => 'ai_analysis',
            'population_mode' => 'automatic',
            'show_on_upload' => false,
            'show_on_edit' => false,
            'show_in_filters' => true,
            'readonly' => true,
        ]);

        // Add options for AI Color Palette
        $this->createOptions($aiColorPaletteId, [
            ['value' => 'red', 'label' => 'Red'],
            ['value' => 'orange', 'label' => 'Orange'],
            ['value' => 'yellow', 'label' => 'Yellow'],
            ['value' => 'green', 'label' => 'Green'],
            ['value' => 'blue', 'label' => 'Blue'],
            ['value' => 'purple', 'label' => 'Purple'],
            ['value' => 'pink', 'label' => 'Pink'],
            ['value' => 'brown', 'label' => 'Brown'],
            ['value' => 'black', 'label' => 'Black'],
            ['value' => 'white', 'label' => 'White'],
            ['value' => 'gray', 'label' => 'Gray'],
        ]);

        // Scene Classification (select)
        $sceneClassificationId = $this->createField([
            'key' => 'scene_classification',
            'system_label' => 'Scene Classification',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'is_filterable' => true,
            'is_user_editable' => false,
            'is_ai_trainable' => true,
            'is_upload_visible' => false,
            'is_internal_only' => false,
            'group_key' => 'ai_analysis',
            'population_mode' => 'automatic',
            'show_on_upload' => false,
            'show_on_edit' => false,
            'show_in_filters' => true,
            'readonly' => true,
        ]);

        // Add options for Scene Classification
        $this->createOptions($sceneClassificationId, [
            ['value' => 'indoor', 'label' => 'Indoor'],
            ['value' => 'outdoor', 'label' => 'Outdoor'],
            ['value' => 'portrait', 'label' => 'Portrait'],
            ['value' => 'landscape', 'label' => 'Landscape'],
            ['value' => 'product', 'label' => 'Product'],
            ['value' => 'food', 'label' => 'Food'],
            ['value' => 'architecture', 'label' => 'Architecture'],
            ['value' => 'nature', 'label' => 'Nature'],
            ['value' => 'urban', 'label' => 'Urban'],
            ['value' => 'abstract', 'label' => 'Abstract'],
        ]);
    }

    /**
     * Create a metadata field (idempotent - checks if exists first).
     *
     * @param array $fieldData
     * @return int Field ID
     */
    protected function createField(array $fieldData): int
    {
        $existing = DB::table('metadata_fields')
            ->where('key', $fieldData['key'])
            ->first();

        if ($existing) {
            // Update existing field with new attributes
            DB::table('metadata_fields')
                ->where('id', $existing->id)
                ->update(array_merge($fieldData, [
                    'updated_at' => now(),
                ]));
            return $existing->id;
        }

        // Insert new field
        $id = DB::table('metadata_fields')->insertGetId(
            array_merge($fieldData, [
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        return $id;
    }

    /**
     * Create options for a metadata field (idempotent).
     *
     * @param int $fieldId
     * @param array $options Array of ['value' => string, 'label' => string]
     * @return void
     */
    protected function createOptions(int $fieldId, array $options): void
    {
        foreach ($options as $option) {
            $existing = DB::table('metadata_options')
                ->where('metadata_field_id', $fieldId)
                ->where('value', $option['value'])
                ->first();

            if (!$existing) {
                DB::table('metadata_options')->insert([
                    'metadata_field_id' => $fieldId,
                    'value' => $option['value'],
                    'system_label' => $option['label'],
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Update label if it changed
                DB::table('metadata_options')
                    ->where('id', $existing->id)
                    ->update([
                        'system_label' => $option['label'],
                        'updated_at' => now(),
                    ]);
            }
        }
    }
}
