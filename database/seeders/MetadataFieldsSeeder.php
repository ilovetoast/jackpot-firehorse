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
        // C9.2: Collection field is needed for Metadata Management governance (same as Tags)
        // Even though Collections use Collection models for data storage, we need the metadata field
        // to exist so it can be governed by category visibility rules in Metadata Management UI
        // This is a governance-only field - actual collection data is in Collection models, not asset_metadata
        // Do NOT deprecate - restore if previously deprecated
        $collectionField = DB::table('metadata_fields')
            ->where('key', 'collection')
            ->first();
            
        if ($collectionField && $collectionField->deprecated_at) {
            // C9.2: Restore collection field for Metadata Management governance
            DB::table('metadata_fields')
                ->where('key', 'collection')
                ->update([
                    'deprecated_at' => null,
                    'updated_at' => now(),
                ]);
        }
        
        // Rename campaign to collection (if collection doesn't exist)
        $campaignField = DB::table('metadata_fields')
            ->where('key', 'campaign')
            ->first();
            
        if ($campaignField) {
            // Check if collection already exists (including deprecated)
            $collectionField = DB::table('metadata_fields')
                ->where('key', 'collection')
                ->first();
                
            if (!$collectionField) {
                // Rename campaign to collection (preserves all existing data)
                // C9.2: Do NOT deprecate - needed for Metadata Management governance
                DB::table('metadata_fields')
                    ->where('id', $campaignField->id)
                    ->update([
                        'key' => 'collection',
                        'system_label' => 'Collection',
                        'deprecated_at' => null, // C9.2: Keep active for Metadata Management governance
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

        // Collection - C9.2: Governance-only metadata field for Metadata Management
        // Actual collection data is stored in Collection models (not asset_metadata table)
        // This field exists solely to allow category-driven visibility control (same as Tags)
        $collectionId = $this->getOrCreateField([
            'key' => 'collection',
            'system_label' => 'Collection',
            'type' => 'multiselect', // Similar to Tags
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'general',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => false, // Collections are not AI-generated
        ]);
        
        // C9.2: Ensure collection field is not deprecated (needed for Metadata Management governance)
        DB::table('metadata_fields')
            ->where('id', $collectionId)
            ->update([
                'deprecated_at' => null,
                'updated_at' => now(),
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

        // Dominant Color Bucket (system automated field - for filtering only)
        // Stores quantized LAB bucket: "L{L}_A{A}_B{B}" format
        // Never shown in asset views, only used for filtering
        $this->getOrCreateField([
            'key' => 'dominant_color_bucket',
            'system_label' => 'Dominant Color Bucket',
            'type' => 'text', // String field for bucket value
            'applies_to' => 'image', // Only applies to image assets
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => true, // Used for filtering
            'is_user_editable' => false, // Read-only (system populated)
            'is_ai_trainable' => false,
            'is_upload_visible' => false, // Hidden from upload form
            'is_internal_only' => true, // Hidden from asset views (filtering only)
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
            'dominant_color_bucket', // System automated - quantized LAB bucket for filtering
        ];

        foreach ($automaticFields as $fieldKey) {
            // Most automatic fields should be visible in edit/drawer view (read-only display)
            // Exception: dominant_color_bucket is hidden from asset views (filtering only)
            $showOnEdit = ($fieldKey !== 'dominant_color_bucket');
            
            DB::table('metadata_fields')
                ->where('key', $fieldKey)
                ->update([
                    'population_mode' => 'automatic',
                    'show_on_upload' => false, // Hidden from upload form
                    'show_on_edit' => $showOnEdit, // Visible in drawer with "Auto" label (read-only), except bucket
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
            // Dominant color fields configuration
            // Note: dominant_colors is the existing field (array of colors)
            // dominant_color_hex would be extracted from first color if needed
            // For now, we configure dominant_colors and dominant_color_bucket
            'dominant_colors' => [
                // Enabled for all image categories by default (handled below)
            ],
            'dominant_color_bucket' => [
                // Enabled for all image categories by default (handled below)
                // Always hidden from asset views, only used for filtering
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
                // - Video category: Only tags and collection enabled (all other fields disabled)
                $fieldsToRestrict = ['expiration_date', 'usage_rights', 'logo_type', 'scene_classification', 'photo_type'];
                
                // Configure Video category: Only tags and collection enabled, all other fields disabled
                $videoCategory = DB::table('categories')
                    ->where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->where('slug', 'video')
                    ->where('is_system', true)
                    ->first();
                
                if ($videoCategory) {
                    // Get all metadata fields except tags and collection
                    $allFields = DB::table('metadata_fields')->get();
                    $tagsField = DB::table('metadata_fields')->where('key', 'tags')->first();
                    $collectionField = DB::table('metadata_fields')->where('key', 'collection')->first();
                    
                    foreach ($allFields as $field) {
                        // Skip tags and collection (enabled by default)
                        if ($field->key === 'tags' || $field->key === 'collection') {
                            continue;
                        }
                        
                        // Disable all other fields for video category
                        $visibility = DB::table('metadata_field_visibility')
                            ->where('metadata_field_id', $field->id)
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->where('category_id', $videoCategory->id)
                            ->first();
                        
                        $visibilityData = [
                            'metadata_field_id' => $field->id,
                            'tenant_id' => $tenant->id,
                            'brand_id' => $brand->id,
                            'category_id' => $videoCategory->id,
                            'is_hidden' => true, // Disable for video category
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
                
                // Configure dominant color fields for all image categories
                // Defaults per requirements:
                // - dominant_colors: enabled=true, upload=false, quick_view=true, filter=false
                // - dominant_color_bucket: enabled=true, upload=false, quick_view=false, filter=true
                $colorFields = ['dominant_colors', 'dominant_color_bucket'];
                
                foreach ($colorFields as $fieldKey) {
                    $field = DB::table('metadata_fields')
                        ->where('key', $fieldKey)
                        ->first();
                    
                    if (!$field) {
                        continue;
                    }
                    
                    // Get all image categories for this brand
                    $imageCategories = DB::table('categories')
                        ->where('tenant_id', $tenant->id)
                        ->where('brand_id', $brand->id)
                        ->where('is_system', true)
                        ->where('asset_type', 'asset') // Only asset categories (images)
                        ->get();
                    
                    foreach ($imageCategories as $category) {
                        // Check if visibility record exists
                        $visibility = DB::table('metadata_field_visibility')
                            ->where('metadata_field_id', $field->id)
                            ->where('tenant_id', $tenant->id)
                            ->where('brand_id', $brand->id)
                            ->where('category_id', $category->id)
                            ->first();
                        
                        // Configure defaults based on field type
                        if ($fieldKey === 'dominant_colors') {
                            // dominant_colors: enabled, not in upload, visible in edit, NOT filterable by default
                            $visibilityData = [
                                'metadata_field_id' => $field->id,
                                'tenant_id' => $tenant->id,
                                'brand_id' => $brand->id,
                                'category_id' => $category->id,
                                'is_hidden' => false, // Enabled
                                'is_upload_hidden' => true, // Hidden from upload
                                'is_filter_hidden' => true, // NOT filterable by default (tenant can enable)
                                'is_primary' => null,
                                'updated_at' => now(),
                            ];
                        } else { // dominant_color_bucket
                            // dominant_color_bucket: enabled, not in upload, hidden from edit, filterable
                            $visibilityData = [
                                'metadata_field_id' => $field->id,
                                'tenant_id' => $tenant->id,
                                'brand_id' => $brand->id,
                                'category_id' => $category->id,
                                'is_hidden' => false, // Enabled
                                'is_upload_hidden' => true, // Hidden from upload
                                'is_filter_hidden' => false, // Filterable
                                'is_primary' => null,
                                'updated_at' => now(),
                            ];
                        }
                        
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
