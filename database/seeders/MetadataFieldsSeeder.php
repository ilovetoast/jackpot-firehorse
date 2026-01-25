<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive Metadata Fields Seeder
 *
 * Consolidates all metadata field creation and configuration:
 * - Creates all system metadata fields
 * - Sets field-level properties (AI eligible, population mode, etc.)
 * - Configures category-specific settings (enabled/disabled, primary placement, AI eligible)
 *
 * Category Configuration Format:
 * [
 *     'category_slug' => [
 *         'enabled' => true/false,      // Field enabled for this category
 *         'is_primary' => true/false,   // Primary filter placement for this category
 *         'ai_eligible' => true/false,   // AI suggestions enabled for this category (overrides field default)
 *     ]
 * ]
 */
class MetadataFieldsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Handle field renames (campaign -> collection)
        $this->handleFieldRenames();
        
        // Seed all metadata fields
        $this->seedBasicFields();
        $this->seedFilterOnlyFields();
        
        // Configure field-level properties
        $this->configureFieldProperties();
        
        // Configure category-specific settings
        $this->configureCategorySettings();
    }
    
    /**
     * Handle field renames (e.g., campaign -> collection).
     * Preserves existing data by updating the field in place.
     */
    protected function handleFieldRenames(): void
    {
        // Rename campaign to collection
        $campaignField = DB::table('metadata_fields')
            ->where('key', 'campaign')
            ->first();
            
        if ($campaignField) {
            // Check if collection already exists
            $collectionField = DB::table('metadata_fields')
                ->where('key', 'collection')
                ->first();
                
            if (!$collectionField) {
                // Rename campaign to collection (preserves all existing data)
                DB::table('metadata_fields')
                    ->where('id', $campaignField->id)
                    ->update([
                        'key' => 'collection',
                        'system_label' => 'Collection',
                        'updated_at' => now(),
                    ]);
            }
            // If collection already exists, leave campaign as-is (user can manually merge if needed)
        }
        
        // Remove AI Color Palette and AI Detected Objects from database if they exist
        DB::table('metadata_fields')
            ->whereIn('key', ['ai_color_palette', 'ai_detected_objects'])
            ->delete();
            
        // Convert Scene Classification from automatic to regular metadata field
        $sceneField = DB::table('metadata_fields')
            ->where('key', 'scene_classification')
            ->first();
            
        if ($sceneField) {
            DB::table('metadata_fields')
                ->where('id', $sceneField->id)
                ->update([
                    'population_mode' => 'manual', // Change from automatic to manual
                    'show_on_upload' => true, // Enable on upload
                    'show_on_edit' => true, // Enable on edit
                    'show_in_filters' => true, // Keep filterable
                    'readonly' => false, // Make it editable
                    'is_user_editable' => true, // User can edit
                    'ai_eligible' => true, // Enable AI suggestions
                    'group_key' => 'creative', // Move to creative group
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Seed basic metadata fields (from PhotoTypeSeeder).
     */
    protected function seedBasicFields(): void
    {
        // Photo Type
        $photoTypeId = $this->getOrCreateField([
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
            'ai_eligible' => true, // Enable AI by default
        ]);

        $this->createOptions($photoTypeId, [
            ['value' => 'studio', 'system_label' => 'Studio'],
            ['value' => 'lifestyle', 'system_label' => 'Lifestyle'],
            ['value' => 'product', 'system_label' => 'Product'],
            ['value' => 'action', 'system_label' => 'Action'],            
            ['value' => 'plate', 'system_label' => 'Plate'],            
            ['value' => 'event', 'system_label' => 'Event'],
        ]);

        // Logo Type
        $logoTypeId = $this->getOrCreateField([
            'key' => 'logo_type',
            'system_label' => 'Logo Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => true,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true, // Enable AI suggestions
        ]);

        $this->createOptions($logoTypeId, [
            ['value' => 'primary', 'system_label' => 'Primary'],
            ['value' => 'secondary', 'system_label' => 'Secondary'],
            ['value' => 'submark', 'system_label' => 'Submark'],
            ['value' => 'icon_mark', 'system_label' => 'Icon / Mark'],
            ['value' => 'wordmark', 'system_label' => 'Wordmark'],
            ['value' => 'monogram', 'system_label' => 'Monogram'],
            ['value' => 'lockup', 'system_label' => 'Lockup'],
        ]);

        // Orientation
        $orientationId = $this->getOrCreateField([
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

        $this->createOptions($orientationId, [
            ['value' => 'landscape', 'system_label' => 'Landscape'],
            ['value' => 'portrait', 'system_label' => 'Portrait'],
            ['value' => 'square', 'system_label' => 'Square'],
        ]);

        // Color Space
        $colorSpaceId = $this->getOrCreateField([
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

        $this->createOptions($colorSpaceId, [
            ['value' => 'srgb', 'system_label' => 'sRGB'],
            ['value' => 'adobe_rgb', 'system_label' => 'Adobe RGB'],
            ['value' => 'display_p3', 'system_label' => 'Display P3'],
        ]);

        // Resolution Class
        $resolutionClassId = $this->getOrCreateField([
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

        $this->createOptions($resolutionClassId, [
            ['value' => 'low', 'system_label' => 'Low'],
            ['value' => 'medium', 'system_label' => 'Medium'],
            ['value' => 'high', 'system_label' => 'High'],
            ['value' => 'ultra', 'system_label' => 'Ultra'],
        ]);

        // Usage Rights
        $usageRightsId = $this->getOrCreateField([
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

        $this->createOptions($usageRightsId, [
            ['value' => 'unrestricted', 'system_label' => 'Unrestricted'],
            ['value' => 'editorial_only', 'system_label' => 'Editorial Only'],
            ['value' => 'internal_use', 'system_label' => 'Internal Use'],
            ['value' => 'licensed', 'system_label' => 'Licensed'],
            ['value' => 'restricted', 'system_label' => 'Restricted'],
        ]);

        // Expiration Date
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

        // Tags (multiselect, no predefined options, AI enabled by default)
        $tagsId = $this->getOrCreateField([
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
            ->where('id', $tagsId)
            ->update(['ai_eligible' => true]);

        // Collection (formerly Campaign)
        $this->getOrCreateField([
            'key' => 'collection',
            'system_label' => 'Collection',
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

        // Quality Rating (user-editable rating field)
        $qualityRatingId = $this->getOrCreateField([
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
            'is_internal_only' => false, // Changed to false - users can edit ratings
        ]);
        
        // Update existing quality_rating field to ensure is_internal_only is false
        DB::table('metadata_fields')
            ->where('key', 'quality_rating')
            ->update([
                'is_internal_only' => false,
                'is_user_editable' => true,
                'type' => 'rating',
                'updated_at' => now(),
            ]);

        // Starred Asset (boolean, user-editable, applied to all categories)
        $this->getOrCreateField([
            'key' => 'starred',
            'system_label' => 'Starred',
            'type' => 'boolean',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'internal',
            'is_filterable' => false,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => false, // Starred is set post-upload
            'is_internal_only' => false,
        ]);
        
        // Update existing starred field to ensure correct configuration
        DB::table('metadata_fields')
            ->where('key', 'starred')
            ->update([
                'type' => 'boolean',
                'is_user_editable' => true,
                'is_internal_only' => false,
                'updated_at' => now(),
            ]);

        // Scene Classification (regular metadata field with AI suggestions)
        $sceneClassificationId = $this->getOrCreateField([
            'key' => 'scene_classification',
            'system_label' => 'Scene Classification',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => true,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true, // Enable AI suggestions
        ]);

        $this->createOptions($sceneClassificationId, [
            ['value' => 'indoor', 'system_label' => 'Indoor'],
            ['value' => 'outdoor', 'system_label' => 'Outdoor'],
            ['value' => 'portrait', 'system_label' => 'Portrait'],
            ['value' => 'landscape', 'system_label' => 'Landscape'],
            ['value' => 'product', 'system_label' => 'Product'],
            ['value' => 'food', 'system_label' => 'Food'],
            ['value' => 'architecture', 'system_label' => 'Architecture'],
            ['value' => 'nature', 'system_label' => 'Nature'],
            ['value' => 'urban', 'system_label' => 'Urban'],
            ['value' => 'abstract', 'system_label' => 'Abstract'],
        ]);

        // Dominant Colors (system automated field - multiselect with JSON values)
        // Stores rich color data: [{hex, rgb, coverage}, ...]
        // No predefined options - colors are dynamically extracted
        $this->getOrCreateField([
            'key' => 'dominant_colors',
            'system_label' => 'Dominant Colors',
            'type' => 'multiselect', // Multi-value field for color array
            'applies_to' => 'image', // Only applies to image assets
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => true, // Filterable via color tiles
            'is_user_editable' => false, // Read-only (system populated)
            'is_ai_trainable' => false,
            'is_upload_visible' => false, // Hidden from upload form
            'is_internal_only' => false, // Visible in UI
            'ai_eligible' => false, // Not AI eligible (system calculated)
        ]);
    }

    /**
     * Seed filter-only metadata fields (from FilterOnlyMetadataFieldsSeeder).
     */
    protected function seedFilterOnlyFields(): void
    {
        // No filter-only fields currently (AI Color Palette and AI Detected Objects removed)
    }

    /**
     * Configure field-level properties (population mode, AI eligibility, etc.).
     */
    protected function configureFieldProperties(): void
    {
        // Set automatic population fields (from MetadataFieldPopulationSeeder)
        $automaticFields = [
            'orientation',
            'color_space',
            'resolution_class',
            'dominant_colors', // System automated - extracted from color analysis
        ];

        foreach ($automaticFields as $fieldKey) {
            // Only dominant_colors should have show_on_edit enabled by default
            $showOnEdit = $fieldKey === 'dominant_colors';
            
            DB::table('metadata_fields')
                ->where('key', $fieldKey)
                ->update([
                    'population_mode' => 'automatic',
                    'show_on_upload' => false, // Hidden from upload form
                    'show_on_edit' => $showOnEdit, // Only dominant_colors enabled by default
                    'show_in_filters' => true, // Available in grid filters
                    'readonly' => true, // Read-only for users (system can populate)
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Configure category-specific settings for metadata fields.
     * 
     * Format: 'field_key' => [
     *     'category_slug' => [
     *         'enabled' => true/false,
     *         'is_primary' => true/false,
     *         'ai_eligible' => true/false, // Optional: overrides field default
     *     ]
     * ]
     */
    protected function configureCategorySettings(): void
    {
        $categoryConfig = [
            'collection' => [
                'photography' => [
                    'enabled' => true,
                    'is_primary' => true, // Primary field for Photography category
                ],
            ],
            'photo_type' => [
                'photography' => [
                    'enabled' => true,
                    'is_primary' => true, // Primary field for Photography category
                    'ai_eligible' => true, // AI suggestions enabled for Photography category
                ],
                'logos' => [
                    'enabled' => false, // Disabled for Logos category
                ],
            ],
            'expiration_date' => [
                'photography' => [
                    'enabled' => true,
                    'is_primary' => true, // Primary field for Photography category
                ],
                // Disabled for all other categories (handled below)
            ],
            'scene_classification' => [
                'photography' => [
                    'enabled' => true,
                    'is_primary' => true, // Primary field for Photography category
                    'ai_eligible' => true, // AI suggestions enabled for Photography category
                ],
            ],
            'usage_rights' => [
                'photography' => [
                    'enabled' => true,
                    'is_primary' => false,
                ],
                // Disabled for all other categories (handled below)
            ],
            'logo_type' => [
                'logos' => [
                    'enabled' => true,
                    'is_primary' => true, // Primary field for Logos category
                    'ai_eligible' => true, // AI suggestions enabled for Logos category
                ],
            ],
            // Tags: Not in config = enabled for all categories by default
            // Quality Rating: Not in config = enabled for all categories by default
            // Add more field configurations here as needed
        ];

        // Get all tenants and their brands
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            $brands = DB::table('brands')->where('tenant_id', $tenant->id)->get();
            
            foreach ($brands as $brand) {
                foreach ($categoryConfig as $fieldKey => $categorySettings) {
                    $field = DB::table('metadata_fields')
                        ->where('key', $fieldKey)
                        ->first();
                    
                    if (!$field) {
                        continue; // Field doesn't exist, skip
                    }

                    foreach ($categorySettings as $categorySlug => $settings) {
                        // Find category by slug for this brand
                        $category = DB::table('categories')
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->where('slug', $categorySlug)
                            ->where('is_system', true) // Only system categories
                            ->first();

                        if (!$category) {
                            continue; // Category doesn't exist for this brand, skip
                        }

                        // Get or create visibility record
                        $visibility = DB::table('metadata_field_visibility')
                            ->where('metadata_field_id', $field->id)
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->where('category_id', $category->id)
                            ->first();

                        $visibilityData = [
                            'metadata_field_id' => $field->id,
                            'tenant_id' => $tenant->id,
                            'brand_id' => $brand->id,
                            'category_id' => $category->id,
                            'is_hidden' => !($settings['enabled'] ?? true), // Inverted: enabled = not hidden
                            'is_upload_hidden' => false, // Can be customized per category if needed
                            'is_filter_hidden' => false, // Can be customized per category if needed
                            'is_primary' => $settings['is_primary'] ?? null,
                            'updated_at' => now(),
                        ];

                        if ($visibility) {
                            // Update existing visibility record
                            DB::table('metadata_field_visibility')
                                ->where('id', $visibility->id)
                                ->update($visibilityData);
                        } else {
                            // Create new visibility record
                            $visibilityData['created_at'] = now();
                            DB::table('metadata_field_visibility')->insert($visibilityData);
                        }

                        // If ai_eligible is specified for this category, update field-level setting
                        // Note: This is a field-level setting, not category-specific
                        // Category-specific AI eligibility would require additional table structure
                        if (isset($settings['ai_eligible'])) {
                            DB::table('metadata_fields')
                                ->where('id', $field->id)
                                ->update([
                                    'ai_eligible' => $settings['ai_eligible'],
                                    'updated_at' => now(),
                                ]);
                        }
                    }
                }
                
                // Handle fields that should be disabled for categories NOT in config
                // These fields are only enabled for specific categories:
                // - expiration_date: only photography
                // - usage_rights: only photography
                // - logo_type: only logos
                // - scene_classification: only photography
                // - photo_type: only photography (disabled for logos)
                $fieldsToRestrict = ['expiration_date', 'usage_rights', 'logo_type', 'scene_classification', 'photo_type'];
                
                foreach ($fieldsToRestrict as $fieldKey) {
                    $field = DB::table('metadata_fields')
                        ->where('key', $fieldKey)
                        ->first();
                    
                    if (!$field) {
                        continue;
                    }
                    
                    // Get enabled categories for this field from config
                    $enabledCategories = $categoryConfig[$fieldKey] ?? [];
                    $enabledCategorySlugs = array_keys($enabledCategories);
                    
                    // Get all system categories for this brand
                    $allCategories = DB::table('categories')
                        ->where('tenant_id', $tenant->id)
                        ->where('brand_id', $brand->id)
                        ->where('is_system', true)
                        ->get();
                    
                    foreach ($allCategories as $category) {
                        // If this category is not in the enabled list, disable the field
                        if (!in_array($category->slug, $enabledCategorySlugs)) {
                            $visibility = DB::table('metadata_field_visibility')
                                ->where('metadata_field_id', $field->id)
                                ->where('tenant_id', $tenant->id)
                                ->where('brand_id', $brand->id)
                                ->where('category_id', $category->id)
                                ->first();
                            
                            $visibilityData = [
                                'metadata_field_id' => $field->id,
                                'tenant_id' => $tenant->id,
                                'brand_id' => $brand->id,
                                'category_id' => $category->id,
                                'is_hidden' => true, // Disable for this category
                                'is_upload_hidden' => false,
                                'is_filter_hidden' => false,
                                'is_primary' => null,
                                'updated_at' => now(),
                            ];
                            
                            if ($visibility) {
                                DB::table('metadata_field_visibility')
                                    ->where('id', $visibility->id)
                                    ->update($visibilityData);
                            } else {
                                $visibilityData['created_at'] = now();
                                DB::table('metadata_field_visibility')->insert($visibilityData);
                            }
                        }
                    }
                }
            }
        }
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
            // Update existing field with any new attributes
            $updateData = array_merge($fieldData, ['updated_at' => now()]);
            unset($updateData['key']); // Don't update the key
            DB::table('metadata_fields')
                ->where('id', $existingField->id)
                ->update($updateData);
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
