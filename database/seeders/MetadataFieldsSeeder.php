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

        // Dimensions: system, auto-populated; never on upload, quick view, or filters (Part 1)
        $this->seedDimensionsField();

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
     * Seed dimensions field: behind-the-scenes only (auto-populated from file).
     * Never visible on upload, quick view, or More filters. Config always_hidden_fields enforces
     * is_filter_hidden=true (and is_hidden, is_upload_hidden) for every category.
     */
    protected function seedDimensionsField(): void
    {
        $this->getOrCreateField([
            'key' => 'dimensions',
            'system_label' => 'Dimensions',
            'type' => 'text',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => false,
            'is_user_editable' => false,
            'is_ai_trainable' => false,
            'is_upload_visible' => false,
            'is_internal_only' => true,
        ]);
    }

    /**
     * Seed basic metadata fields (from PhotoTypeSeeder).
     */
    protected function seedBasicFields(): void
    {
        // Photo Type (Photography) — options: studio, lifestyle only
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($photoTypeId, [
            ['value' => 'studio', 'system_label' => 'Studio'],
            ['value' => 'lifestyle', 'system_label' => 'Lifestyle'],
        ]);

        // Logo Type (Logos) — options: primary, secondary, promotional only; no AI by default
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
            'ai_eligible' => false,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($logoTypeId, [
            ['value' => 'primary', 'system_label' => 'Primary'],
            ['value' => 'secondary', 'system_label' => 'Secondary'],
            ['value' => 'promotional', 'system_label' => 'Promotional'],
        ]);

        // Graphics — graphic_type
        $graphicTypeId = $this->getOrCreateField([
            'key' => 'graphic_type',
            'system_label' => 'Graphic Type',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($graphicTypeId, [
            ['value' => 'icon', 'system_label' => 'Icon'],
            ['value' => 'effect', 'system_label' => 'Effect'],
            ['value' => 'texture', 'system_label' => 'Texture'],
        ]);

        // Video Assets — video_type
        $videoTypeId = $this->getOrCreateField([
            'key' => 'video_type',
            'system_label' => 'Video Type',
            'type' => 'select',
            'applies_to' => 'video',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($videoTypeId, [
            ['value' => 'b_roll', 'system_label' => 'B-Roll'],
            ['value' => 'interviews', 'system_label' => 'Interviews'],
        ]);

        // Templates — template_type
        $templateTypeId = $this->getOrCreateField([
            'key' => 'template_type',
            'system_label' => 'Template Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($templateTypeId, [
            ['value' => 'email', 'system_label' => 'Email'],
            ['value' => 'social', 'system_label' => 'Social'],
        ]);

        // Audio — audio_type (field only, no options)
        $this->getOrCreateField([
            'key' => 'audio_type',
            'system_label' => 'Audio Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);

        // 3D Models — model_3d_type (field only, no options)
        $this->getOrCreateField([
            'key' => 'model_3d_type',
            'system_label' => '3D Model Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
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
        
        // C9.2: Ensure collection field is not deprecated and is grouped under General in uploader
        DB::table('metadata_fields')
            ->where('id', $collectionId)
            ->update([
                'deprecated_at' => null,
                'group_key' => 'general',
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
            'population_mode' => 'manual', // Must be manual for stars to be clickable
            'readonly' => false,
        ]);
        
        // Update existing quality_rating field to ensure is_internal_only is false and always user-editable
        // population_mode=manual and readonly=false are required for stars to be clickable in the quick panel
        DB::table('metadata_fields')
            ->where('key', 'quality_rating')
            ->update([
                'is_internal_only' => false,
                'is_user_editable' => true,
                'type' => 'rating',
                'population_mode' => 'manual',
                'readonly' => false,
                'updated_at' => now(),
            ]);

        // Starred Asset (boolean, user-editable, applied to all categories)
        // is_filterable => true so the field appears in grid filters when "Filter" is enabled per category
        $this->getOrCreateField([
            'key' => 'starred',
            'system_label' => 'Starred',
            'type' => 'boolean',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'internal',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true, // Shown as toggle on uploader (UploadAssetDialog renders it above UploadTray)
            'is_internal_only' => false,
        ]);
        
        // Update existing starred field to ensure correct configuration (display_widget = toggle everywhere)
        DB::table('metadata_fields')
            ->where('key', 'starred')
            ->update([
                'type' => 'boolean',
                'is_filterable' => true,
                'is_user_editable' => true,
                'is_internal_only' => false,
                'is_upload_visible' => true,
                'display_widget' => 'toggle',
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

        // Dominant Colors (system automated) — drawer only, never in More filters
        // Stores rich color data: [{hex, rgb, coverage}, ...]. Visible in asset drawer; not filterable.
        $this->getOrCreateField([
            'key' => 'dominant_colors',
            'system_label' => 'Dominant Colors',
            'type' => 'multiselect',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => false, // Never in More filters (drawer only)
            'is_user_editable' => false,
            'is_ai_trainable' => false,
            'is_upload_visible' => false,
            'is_internal_only' => false, // Visible in drawer
            'ai_eligible' => false,
        ]);

        // Dominant Color Bucket (system automated) — filter-only field.
        // Stores quantized LAB bucket: "L{L}_A{A}_B{B}" format.
        // Config filter_only_enforced_fields + dominant_colors_visibility enforce:
        // - Never in Quick View, Upload, Primary filters
        // - Secondary filters only when is_filter_hidden=false (user enables)
        // - Uses ColorSwatchFilter (filter_type=color)
        $this->getOrCreateField([
            'key' => 'dominant_color_bucket',
            'system_label' => 'Dominant Color Bucket',
            'type' => 'text', // String field for bucket value
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => true, // Filter-only; visibility via metadata_field_visibility
            'is_user_editable' => false,
            'is_ai_trainable' => false,
            'is_upload_visible' => false,
            'is_internal_only' => false,
            'ai_eligible' => false,
        ]);

        // --- Execution / Deliverables type fields (exactly these 10; no others) ---
        // 1. Print  2. Digital  3. OOH  4. Events  5. Video (Executions)  6. Sales Collateral
        // 7. PR  8. Packaging  9. Product Renders  10. Radio

        // 1. Print
        $printTypeId = $this->getOrCreateField([
            'key' => 'print_type',
            'system_label' => 'Print Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($printTypeId, [
            ['value' => 'ads', 'system_label' => 'Ads'],
            ['value' => 'brochures', 'system_label' => 'Brochures'],
            ['value' => 'posters', 'system_label' => 'Posters'],
            ['value' => 'inserts', 'system_label' => 'Inserts'],
        ]);

        // 2. Digital (rename from Digital Ads)
        $digitalTypeId = $this->getOrCreateField([
            'key' => 'digital_type',
            'system_label' => 'Digital Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($digitalTypeId, [
            ['value' => 'display_ads', 'system_label' => 'Display Ads'],
        ]);

        // 3. OOH
        $oohTypeId = $this->getOrCreateField([
            'key' => 'ooh_type',
            'system_label' => 'OOH Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($oohTypeId, [
            ['value' => 'billboards', 'system_label' => 'Billboards'],
            ['value' => 'signage', 'system_label' => 'Signage'],
        ]);

        // 4. Events
        $eventTypeId = $this->getOrCreateField([
            'key' => 'event_type',
            'system_label' => 'Event Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($eventTypeId, [
            ['value' => 'booths', 'system_label' => 'Booths'],
            ['value' => 'transit', 'system_label' => 'Transit'],
            ['value' => 'experiential', 'system_label' => 'Experiential'],
        ]);

        // 5. Video (Executions)
        $executionVideoTypeId = $this->getOrCreateField([
            'key' => 'execution_video_type',
            'system_label' => 'Execution Video Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($executionVideoTypeId, [
            ['value' => 'broadcast', 'system_label' => 'Broadcast'],
            ['value' => 'pre_roll', 'system_label' => 'Pre-Roll'],
            ['value' => 'brand_video', 'system_label' => 'Brand Video'],
            ['value' => 'explainer_video', 'system_label' => 'Explainer Video'],
            ['value' => 'product_demos', 'system_label' => 'Product Demos'],
        ]);

        // 6. Sales Collateral
        $salesCollateralTypeId = $this->getOrCreateField([
            'key' => 'sales_collateral_type',
            'system_label' => 'Sales Collateral Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($salesCollateralTypeId, [
            ['value' => 'catalogs', 'system_label' => 'Catalogs'],
            ['value' => 'sales_sheets', 'system_label' => 'Sales Sheets'],
            ['value' => 'trade_show_materials', 'system_label' => 'Trade Show Materials'],
        ]);

        // 7. PR
        $prTypeId = $this->getOrCreateField([
            'key' => 'pr_type',
            'system_label' => 'PR Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($prTypeId, [
            ['value' => 'press_releases', 'system_label' => 'Press Releases'],
            ['value' => 'media_kits', 'system_label' => 'Media Kits'],
            ['value' => 'backgrounders', 'system_label' => 'Backgrounders'],
        ]);

        // 8. Packaging
        $packagingTypeId = $this->getOrCreateField([
            'key' => 'packaging_type',
            'system_label' => 'Packaging Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($packagingTypeId, [
            ['value' => 'flat_art', 'system_label' => 'Flat Art'],
            ['value' => 'renders_3d', 'system_label' => '3D Renders'],
        ]);

        // 9. Product Renders (field only; options left empty)
        $this->getOrCreateField([
            'key' => 'product_render_type',
            'system_label' => 'Product Render Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);

        // 10. Radio
        $radioTypeId = $this->getOrCreateField([
            'key' => 'radio_type',
            'system_label' => 'Radio Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'display_widget' => 'select',
        ]);
        $this->createOptions($radioTypeId, [
            ['value' => 'broadcast_spots', 'system_label' => 'Broadcast Spots'],
            ['value' => 'live_reads', 'system_label' => 'Live Reads'],
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
            // dominant_colors: display-only, never in filters (always_hidden_fields)
            // dominant_color_bucket: filter-only, secondary filters when enabled (filter_only_enforced_fields)
            $isDominantColors = ($fieldKey === 'dominant_colors');
            $isDominantColorBucket = ($fieldKey === 'dominant_color_bucket');
            $showOnEdit = $isDominantColorBucket ? false : true; // dominant_color_bucket never in Quick View
            $showInFilters = $isDominantColors ? false : true;  // dominant_colors never in filters; bucket filter-only

            DB::table('metadata_fields')
                ->where('key', $fieldKey)
                ->update([
                    'population_mode' => 'automatic',
                    'show_on_upload' => false,
                    'show_on_edit' => $showOnEdit,
                    'show_in_filters' => $showInFilters,
                    'readonly' => true,
                    'is_filterable' => $showInFilters,
                    'updated_at' => now(),
                ]);
        }

        // Dimensions: behind-the-scenes only; never in upload, quick view, or More filters.
        // always_hidden_fields in config ensures category visibility has is_filter_hidden=true.
        $dimensionsField = DB::table('metadata_fields')->where('key', 'dimensions')->first();
        if ($dimensionsField) {
            DB::table('metadata_fields')
                ->where('key', 'dimensions')
                ->update([
                    'scope' => 'system',
                    'population_mode' => 'automatic',
                    'show_on_upload' => false,
                    'show_on_edit' => false,
                    'show_in_filters' => false,
                    'readonly' => true,
                    'is_filterable' => false,
                    'is_user_editable' => false,
                    'is_upload_visible' => false,
                    'is_internal_only' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Configure category-specific settings for metadata fields.
     * Uses TenantMetadataVisibilityService::applySeededDefaultsForCategory (single source of truth).
     * Default: only collection + tags enabled per category.
     */
    protected function configureCategorySettings(): void
    {
        $visibilityService = app(\App\Services\TenantMetadataVisibilityService::class);
        $tenants = \App\Models\Tenant::all();

        foreach ($tenants as $tenant) {
            $brands = $tenant->brands;
            foreach ($brands as $brand) {
                $categories = \App\Models\Category::where('tenant_id', $tenant->id)
                    ->where('brand_id', $brand->id)
                    ->get();
                foreach ($categories as $category) {
                    $visibilityService->applySeededDefaultsForCategory($tenant, $category);
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
    /**
     * Sync options for a select field: keep only the given options (remove others), then create/update as needed.
     *
     * @param int $fieldId
     * @param array $options Array of ['value' => string, 'system_label' => string]
     */
    protected function syncOptions(int $fieldId, array $options): void
    {
        $allowedValues = array_column($options, 'value');
        if (!empty($allowedValues)) {
            DB::table('metadata_options')
                ->where('metadata_field_id', $fieldId)
                ->whereNotIn('value', $allowedValues)
                ->delete();
        }
        $this->createOptions($fieldId, $options);
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
