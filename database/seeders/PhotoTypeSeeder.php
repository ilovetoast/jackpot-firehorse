<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Basic Metadata Fields Seeder
 *
 * Creates essential metadata fields including:
 * - photo_type: Classifies images by type (studio, lifestyle, product, etc.)
 * - orientation: Image orientation (landscape, portrait, square)
 * - color_space: Color space (sRGB, Adobe RGB, Display P3)
 * - resolution_class: Resolution classification (low, medium, high, ultra)
 * - usage_rights: Defines usage rights/license restrictions
 * - expiration_date: Tracks when assets expire
 * - tags: Multiselect tags for flexible asset categorization
 * - campaign: Campaign name (text field)
 * - quality_rating: Quality rating (rating field, internal only)
 */
class PhotoTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedPhotoType();
        $this->seedOrientation();
        $this->seedColorSpace();
        $this->seedResolutionClass();
        $this->seedUsageRights();
        $this->seedExpirationDate();
        $this->seedTags();
        $this->seedCampaign();
        $this->seedQualityRating();
    }

    /**
     * Seed photo_type field with options.
     */
    protected function seedPhotoType(): void
    {
        $fieldId = $this->getOrCreateField([
            'key' => 'photo_type',
            'system_label' => 'Photo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => true,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);

        $options = [
            ['value' => 'studio', 'system_label' => 'Studio'],
            ['value' => 'lifestyle', 'system_label' => 'Lifestyle'],
            ['value' => 'product', 'system_label' => 'Product'],
            ['value' => 'action', 'system_label' => 'Action'],
            ['value' => 'flat_lay', 'system_label' => 'Flat Lay'],
            ['value' => 'macro', 'system_label' => 'Macro'],
            ['value' => 'portrait', 'system_label' => 'Portrait'],
            ['value' => 'landscape', 'system_label' => 'Landscape'],
            ['value' => 'event', 'system_label' => 'Event'],
        ];

        $this->createOptions($fieldId, $options);
    }

    /**
     * Seed usage_rights field with options.
     */
    protected function seedUsageRights(): void
    {
        $fieldId = $this->getOrCreateField([
            'key' => 'usage_rights',
            'system_label' => 'Usage Rights',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'legal',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);

        $options = [
            ['value' => 'unrestricted', 'system_label' => 'Unrestricted'],
            ['value' => 'editorial_only', 'system_label' => 'Editorial Only'],
            ['value' => 'internal_use', 'system_label' => 'Internal Use'],
            ['value' => 'licensed', 'system_label' => 'Licensed'],
            ['value' => 'restricted', 'system_label' => 'Restricted'],
        ];

        $this->createOptions($fieldId, $options);
    }

    /**
     * Seed expiration_date field.
     */
    protected function seedExpirationDate(): void
    {
        $this->getOrCreateField([
            'key' => 'expiration_date',
            'system_label' => 'Expiration Date',
            'type' => 'date',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'legal',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);
    }

    /**
     * Seed orientation field with options.
     */
    protected function seedOrientation(): void
    {
        $fieldId = $this->getOrCreateField([
            'key' => 'orientation',
            'system_label' => 'Orientation',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => false,
            'is_ai_trainable' => true,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);

        $options = [
            ['value' => 'landscape', 'system_label' => 'Landscape'],
            ['value' => 'portrait', 'system_label' => 'Portrait'],
            ['value' => 'square', 'system_label' => 'Square'],
        ];

        $this->createOptions($fieldId, $options);
    }

    /**
     * Seed color_space field with options.
     */
    protected function seedColorSpace(): void
    {
        $fieldId = $this->getOrCreateField([
            'key' => 'color_space',
            'system_label' => 'Color Space',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => true,
            'is_user_editable' => false,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);

        $options = [
            ['value' => 'srgb', 'system_label' => 'sRGB'],
            ['value' => 'adobe_rgb', 'system_label' => 'Adobe RGB'],
            ['value' => 'display_p3', 'system_label' => 'Display P3'],
        ];

        $this->createOptions($fieldId, $options);
    }

    /**
     * Seed resolution_class field with options.
     */
    protected function seedResolutionClass(): void
    {
        $fieldId = $this->getOrCreateField([
            'key' => 'resolution_class',
            'system_label' => 'Resolution Class',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => true,
            'is_user_editable' => false,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);

        $options = [
            ['value' => 'low', 'system_label' => 'Low'],
            ['value' => 'medium', 'system_label' => 'Medium'],
            ['value' => 'high', 'system_label' => 'High'],
            ['value' => 'ultra', 'system_label' => 'Ultra'],
        ];

        $this->createOptions($fieldId, $options);
    }

    /**
     * Seed tags field (multiselect, no predefined options).
     * Tags field has AI suggestions enabled by default.
     */
    protected function seedTags(): void
    {
        $fieldId = $this->getOrCreateField([
            'key' => 'tags',
            'system_label' => 'Tags',
            'type' => 'multiselect',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'general',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => true,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true, // Enable AI suggestions by default for tags
        ]);

        // Ensure ai_eligible is set to true (in case field already existed)
        DB::table('metadata_fields')
            ->where('id', $fieldId)
            ->update(['ai_eligible' => true]);
    }

    /**
     * Seed campaign field (text field).
     */
    protected function seedCampaign(): void
    {
        $this->getOrCreateField([
            'key' => 'campaign',
            'system_label' => 'Campaign',
            'type' => 'text',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'general',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
        ]);
    }

    /**
     * Seed quality_rating field (rating field, internal only).
     */
    protected function seedQualityRating(): void
    {
        $this->getOrCreateField([
            'key' => 'quality_rating',
            'system_label' => 'Quality Rating',
            'type' => 'rating',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'internal',
            'is_filterable' => false,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => false, // Rating fields excluded from upload
            'is_internal_only' => true,
        ]);
    }

    /**
     * Get or create a metadata field.
     *
     * @param array $fieldData
     * @return int Field ID
     */
    protected function getOrCreateField(array $fieldData): int
    {
        $existingField = DB::table('metadata_fields')
            ->where('key', $fieldData['key'])
            ->first();

        if ($existingField) {
            return $existingField->id;
        }

        return DB::table('metadata_fields')->insertGetId(
            array_merge($fieldData, [
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    /**
     * Create options for a metadata field (idempotent).
     *
     * @param int $fieldId
     * @param array $options Array of ['value' => string, 'system_label' => string]
     * @return void
     */
    protected function createOptions(int $fieldId, array $options): void
    {
        foreach ($options as $option) {
            $existingOption = DB::table('metadata_options')
                ->where('metadata_field_id', $fieldId)
                ->where('value', $option['value'])
                ->first();

            if (!$existingOption) {
                DB::table('metadata_options')->insert([
                    'metadata_field_id' => $fieldId,
                    'value' => $option['value'],
                    'system_label' => $option['system_label'],
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // Update label if it changed
                DB::table('metadata_options')
                    ->where('id', $existingOption->id)
                    ->update([
                        'system_label' => $option['system_label'],
                        'updated_at' => now(),
                    ]);
            }
        }
    }
}
