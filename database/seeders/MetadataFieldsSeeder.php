<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\MetadataCache;
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
 * Deploy: option lists for system fields (Subject, Environment, Shot Type / photo_type) do NOT auto-update
 * on git push. After deploying, run e.g. `php artisan db:seed --class=MetadataFieldsSeeder` on staging/production
 * (or your full database seeder) so `metadata_options` and field labels sync. Existing asset metadata values
 * that used removed option slugs may need a one-time data migration.
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

        // Guarantee default system fields exist and are enabled (staging consistency)
        $this->ensureDefaultSystemFields();

        $this->deprecateLegacySceneClassification();
        $this->deprecateRedundantMetadataStatusField();

        // Raw DB writes do not fire MetadataFieldObserver; bump schema version so upload/drawer
        // refetch (MetadataSchemaResolver uses versioned cache keys).
        $this->bumpMetadataSchemaCacheForAllTenants();
    }

    protected function bumpMetadataSchemaCacheForAllTenants(): void
    {
        foreach (Tenant::query()->cursor() as $tenant) {
            MetadataCache::flushTenant((int) $tenant->id);
        }
    }

    /**
     * Lifecycle status is tracked on assets/workflows elsewhere; remove duplicate metadata field from catalog.
     */
    protected function deprecateRedundantMetadataStatusField(): void
    {
        DB::table('metadata_fields')
            ->where('key', 'status')
            ->where('scope', 'system')
            ->update([
                'deprecated_at' => now(),
                'replacement_field_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * scene_classification mixed environment, subject, and composition; replaced by environment_type + subject_type.
     * Existing rows remain in DB for audit; field is hidden from schema via deprecated_at.
     */
    protected function deprecateLegacySceneClassification(): void
    {
        $replacementId = DB::table('metadata_fields')->where('key', 'environment_type')->value('id');

        DB::table('metadata_fields')
            ->where('key', 'scene_classification')
            ->update([
                'deprecated_at' => now(),
                'replacement_field_id' => $replacementId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Ensure default system fields always exist and are enabled.
     * Guarantees staging consistency: fields created by seedBasicFields/seedFilterOnlyFields
     * are explicitly set to population_mode=automatic and deprecated_at=null.
     */
    protected function ensureDefaultSystemFields(): void
    {
        $systemFieldKeys = ['dominant_colors', 'dominant_hue_group', 'color_space', 'orientation', 'resolution_class'];

        DB::table('metadata_fields')
            ->whereIn('key', $systemFieldKeys)
            ->update([
                'population_mode' => 'automatic',
                'deprecated_at' => null,
                'updated_at' => now(),
            ]);
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

            if (! $collectionField) {
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

        // scene_classification: deprecated in favor of environment_type + subject_type (see deprecateLegacySceneClassification).
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
        // Shot Type (DB key: photo_type) — “What kind of visual is it?” Sync replaces option rows not in this list.
        $photoTypeId = $this->getOrCreateField([
            'key' => 'photo_type',
            'system_label' => 'Shot Type',
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
            ['value' => 'product_only', 'system_label' => 'Product-only'],
            ['value' => 'product_in_use', 'system_label' => 'Product in use'],
            ['value' => 'lifestyle', 'system_label' => 'Lifestyle'],
            ['value' => 'portrait', 'system_label' => 'Portrait'],
            ['value' => 'detail_closeup', 'system_label' => 'Detail/Close-up'],
            ['value' => 'flat_lay', 'system_label' => 'Flat lay'],
            ['value' => 'packaging_label', 'system_label' => 'Packaging/Label'],
            ['value' => 'interior', 'system_label' => 'Interior'],
            ['value' => 'exterior', 'system_label' => 'Exterior'],
            ['value' => 'event', 'system_label' => 'Event'],
            ['value' => 'action', 'system_label' => 'Action'],
            ['value' => 'scenic', 'system_label' => 'Scenic'],
            ['value' => 'mockup_render', 'system_label' => 'Mockup/Render'],
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
            ['value' => 'icon', 'system_label' => 'Icon'],
            ['value' => 'wordmark', 'system_label' => 'Wordmark'],
            ['value' => 'lockup', 'system_label' => 'Lockup'],
            ['value' => 'promotional', 'system_label' => 'Promotional'],
            ['value' => 'alternate', 'system_label' => 'Alternate'],
        ]);

        // Font role — headline vs body copy (Fonts library / Brand Guidelines typography only).
        // Visibility is enforced per category slug via config/metadata_category_defaults.php (restrict_fields +
        // category_config) and TenantMetadataVisibilityService::isRestrictFieldEnabledForCategorySlug — not by applies_to alone.
        $fontRoleId = $this->getOrCreateField([
            'key' => 'font_role',
            'system_label' => 'Font role',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => false,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($fontRoleId, [
            ['value' => 'headline', 'system_label' => 'Headline'],
            ['value' => 'subhead', 'system_label' => 'Subhead'],
            ['value' => 'body', 'system_label' => 'Body'],
            ['value' => 'accent', 'system_label' => 'Accent'],
            ['value' => 'ui', 'system_label' => 'UI'],
            ['value' => 'campaign', 'system_label' => 'Campaign'],
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
        $this->syncOptions($graphicTypeId, [
            ['value' => 'icon', 'system_label' => 'Icon'],
            ['value' => 'illustration', 'system_label' => 'Illustration'],
            ['value' => 'texture', 'system_label' => 'Texture'],
            ['value' => 'pattern', 'system_label' => 'Pattern'],
            ['value' => 'overlay', 'system_label' => 'Overlay'],
            ['value' => 'badge', 'system_label' => 'Badge'],
            ['value' => 'effect', 'system_label' => 'Effect'],
            ['value' => 'background', 'system_label' => 'Background'],
        ]);

        // Video Assets — video_type (primary filter on Video category via config/metadata_category_defaults.php;
        // asset grid resolves metadata schema with file kind `video` for category slug `video`).
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
        $this->syncOptions($videoTypeId, [
            ['value' => 'b_roll', 'system_label' => 'B-Roll'],
            ['value' => 'interview', 'system_label' => 'Interview'],
            ['value' => 'testimonial', 'system_label' => 'Testimonial'],
            ['value' => 'product_demo', 'system_label' => 'Product Demo'],
            ['value' => 'event_footage', 'system_label' => 'Event Footage'],
            ['value' => 'social_clip', 'system_label' => 'Social Clip'],
            ['value' => 'motion_graphic', 'system_label' => 'Motion Graphic'],
            ['value' => 'behind_the_scenes', 'system_label' => 'Behind the Scenes'],
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
        $this->syncOptions($templateTypeId, [
            ['value' => 'social', 'system_label' => 'Social'],
            ['value' => 'email', 'system_label' => 'Email'],
            ['value' => 'presentation', 'system_label' => 'Presentation'],
            ['value' => 'print', 'system_label' => 'Print'],
            ['value' => 'web', 'system_label' => 'Web'],
            ['value' => 'ad', 'system_label' => 'Ad'],
        ]);

        $audioTypeId = $this->getOrCreateField([
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
        $this->syncOptions($audioTypeId, [
            ['value' => 'voiceover', 'system_label' => 'Voiceover'],
            ['value' => 'music', 'system_label' => 'Music'],
            ['value' => 'sfx', 'system_label' => 'SFX'],
            ['value' => 'podcast', 'system_label' => 'Podcast'],
            ['value' => 'interview', 'system_label' => 'Interview'],
            ['value' => 'ambient', 'system_label' => 'Ambient'],
        ]);

        $model3dTypeId = $this->getOrCreateField([
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
        $this->syncOptions($model3dTypeId, [
            ['value' => 'product_model', 'system_label' => 'Product Model'],
            ['value' => 'environment', 'system_label' => 'Environment'],
            ['value' => 'packaging', 'system_label' => 'Packaging'],
            ['value' => 'fixture', 'system_label' => 'Fixture'],
            ['value' => 'concept', 'system_label' => 'Concept'],
            ['value' => 'render_source', 'system_label' => 'Render Source'],
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

        $environmentTypeId = $this->getOrCreateField([
            'key' => 'environment_type',
            'system_label' => 'Environment',
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
        // “Where is it?” — sync replaces options not listed (staging: run MetadataFieldsSeeder after deploy).
        $this->syncOptions($environmentTypeId, [
            ['value' => 'indoor', 'system_label' => 'Indoor'],
            ['value' => 'outdoor', 'system_label' => 'Outdoor'],
            ['value' => 'studio', 'system_label' => 'Studio'],
            ['value' => 'urban', 'system_label' => 'Urban'],
            ['value' => 'nature', 'system_label' => 'Nature'],
            ['value' => 'mixed_composite', 'system_label' => 'Mixed/Composite'],
            ['value' => 'unknown', 'system_label' => 'Unknown'],
        ]);

        $subjectTypeId = $this->getOrCreateField([
            'key' => 'subject_type',
            'system_label' => 'Subject',
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
        // “What is the main thing?” — sync replaces options not listed.
        $this->syncOptions($subjectTypeId, [
            ['value' => 'product', 'system_label' => 'Product'],
            ['value' => 'person', 'system_label' => 'Person'],
            ['value' => 'food_beverage', 'system_label' => 'Food/Beverage'],
            ['value' => 'architecture', 'system_label' => 'Architecture'],
            ['value' => 'landscape_place', 'system_label' => 'Landscape/Place'],
            ['value' => 'object', 'system_label' => 'Object'],
            ['value' => 'texture_pattern', 'system_label' => 'Texture/Pattern'],
            ['value' => 'text_graphic', 'system_label' => 'Text/Graphic'],
            ['value' => 'abstract', 'system_label' => 'Abstract'],
        ]);

        // Season (system, applies_to=all). Fonts and logos categories hide this via
        // config/metadata_category_defaults.php → system_fields_hidden_for_category_slugs.
        $seasonId = $this->getOrCreateField([
            'key' => 'season',
            'system_label' => 'Season',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'general',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => false,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($seasonId, [
            ['value' => 'spring', 'system_label' => 'Spring'],
            ['value' => 'summer', 'system_label' => 'Summer'],
            ['value' => 'fall', 'system_label' => 'Fall'],
            ['value' => 'winter', 'system_label' => 'Winter'],
            ['value' => 'evergreen', 'system_label' => 'Evergreen'],
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

        // Dominant Hue (system automated) — filter-only field.
        // Perceptual hue clusters from HueClusterService. Options from getClusters().
        // type: select, system_automated: true, filterable: true, visible: true, not user-editable.
        $this->getOrCreateField([
            'key' => 'dominant_hue_group',
            'system_label' => 'Dominant Hue',
            'type' => 'select',
            'applies_to' => 'image',
            'scope' => 'system',
            'group_key' => 'technical',
            'is_filterable' => true,
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($printTypeId, [
            ['value' => 'ad', 'system_label' => 'Ad'],
            ['value' => 'brochure', 'system_label' => 'Brochure'],
            ['value' => 'flyer', 'system_label' => 'Flyer'],
            ['value' => 'poster', 'system_label' => 'Poster'],
            ['value' => 'insert', 'system_label' => 'Insert'],
            ['value' => 'catalog', 'system_label' => 'Catalog'],
            ['value' => 'sales_sheet', 'system_label' => 'Sales Sheet'],
            ['value' => 'direct_mail', 'system_label' => 'Direct Mail'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($digitalTypeId, [
            ['value' => 'display_ad', 'system_label' => 'Display Ad'],
            ['value' => 'web_banner', 'system_label' => 'Web Banner'],
            ['value' => 'landing_page', 'system_label' => 'Landing Page'],
            ['value' => 'email_graphic', 'system_label' => 'Email Graphic'],
            ['value' => 'hero', 'system_label' => 'Hero'],
            ['value' => 'ecommerce_module', 'system_label' => 'E-commerce Module'],
            ['value' => 'app_creative', 'system_label' => 'App Creative'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($oohTypeId, [
            ['value' => 'billboard', 'system_label' => 'Billboard'],
            ['value' => 'signage', 'system_label' => 'Signage'],
            ['value' => 'transit', 'system_label' => 'Transit'],
            ['value' => 'retail_display', 'system_label' => 'Retail Display'],
            ['value' => 'wall_graphic', 'system_label' => 'Wall Graphic'],
            ['value' => 'window_graphic', 'system_label' => 'Window Graphic'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($eventTypeId, [
            ['value' => 'booth', 'system_label' => 'Booth'],
            ['value' => 'backdrop', 'system_label' => 'Backdrop'],
            ['value' => 'banner', 'system_label' => 'Banner'],
            ['value' => 'credential', 'system_label' => 'Credential'],
            ['value' => 'handout', 'system_label' => 'Handout'],
            ['value' => 'experiential_installation', 'system_label' => 'Experiential Installation'],
            ['value' => 'wayfinding', 'system_label' => 'Wayfinding'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($executionVideoTypeId, [
            ['value' => 'broadcast', 'system_label' => 'Broadcast'],
            ['value' => 'pre_roll', 'system_label' => 'Pre-Roll'],
            ['value' => 'brand_video', 'system_label' => 'Brand Video'],
            ['value' => 'explainer', 'system_label' => 'Explainer'],
            ['value' => 'product_demo', 'system_label' => 'Product Demo'],
            ['value' => 'social_video', 'system_label' => 'Social Video'],
            ['value' => 'cutdown', 'system_label' => 'Cutdown'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($salesCollateralTypeId, [
            ['value' => 'sales_sheet', 'system_label' => 'Sales Sheet'],
            ['value' => 'catalog', 'system_label' => 'Catalog'],
            ['value' => 'pitch_deck', 'system_label' => 'Pitch Deck'],
            ['value' => 'trade_show_material', 'system_label' => 'Trade Show Material'],
            ['value' => 'case_study', 'system_label' => 'Case Study'],
            ['value' => 'one_pager', 'system_label' => 'One Pager'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($prTypeId, [
            ['value' => 'press_release', 'system_label' => 'Press Release'],
            ['value' => 'media_kit', 'system_label' => 'Media Kit'],
            ['value' => 'backgrounder', 'system_label' => 'Backgrounder'],
            ['value' => 'fact_sheet', 'system_label' => 'Fact Sheet'],
            ['value' => 'announcement', 'system_label' => 'Announcement'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($packagingTypeId, [
            ['value' => 'label', 'system_label' => 'Label'],
            ['value' => 'box', 'system_label' => 'Box'],
            ['value' => 'pouch', 'system_label' => 'Pouch'],
            ['value' => 'carton', 'system_label' => 'Carton'],
            ['value' => 'shipper', 'system_label' => 'Shipper'],
            ['value' => 'flat_art', 'system_label' => 'Flat Art'],
            ['value' => 'render', 'system_label' => 'Render'],
        ]);

        $productRenderTypeId = $this->getOrCreateField([
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($productRenderTypeId, [
            ['value' => 'product_hero', 'system_label' => 'Product Hero'],
            ['value' => 'lifestyle_render', 'system_label' => 'Lifestyle Render'],
            ['value' => 'technical_flat', 'system_label' => 'Technical Flat'],
            ['value' => 'cad_export', 'system_label' => 'CAD Export'],
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
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($radioTypeId, [
            ['value' => 'broadcast_spots', 'system_label' => 'Broadcast Spots'],
            ['value' => 'live_reads', 'system_label' => 'Live Reads'],
        ]);

        $socialFormatId = $this->getOrCreateField([
            'key' => 'social_format',
            'system_label' => 'Social Format',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($socialFormatId, [
            ['value' => 'feed_post', 'system_label' => 'Feed Post'],
            ['value' => 'story', 'system_label' => 'Story'],
            ['value' => 'reel', 'system_label' => 'Reel'],
            ['value' => 'carousel', 'system_label' => 'Carousel'],
            ['value' => 'cover', 'system_label' => 'Cover'],
            ['value' => 'ad', 'system_label' => 'Ad'],
            ['value' => 'thumbnail', 'system_label' => 'Thumbnail'],
        ]);

        $socialPlatformId = $this->getOrCreateField([
            'key' => 'social_platform',
            'system_label' => 'Social Platform',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($socialPlatformId, [
            ['value' => 'instagram', 'system_label' => 'Instagram'],
            ['value' => 'facebook', 'system_label' => 'Facebook'],
            ['value' => 'linkedin', 'system_label' => 'LinkedIn'],
            ['value' => 'tiktok', 'system_label' => 'TikTok'],
            ['value' => 'pinterest', 'system_label' => 'Pinterest'],
            ['value' => 'x', 'system_label' => 'X'],
            ['value' => 'youtube', 'system_label' => 'YouTube'],
            ['value' => 'multiple', 'system_label' => 'Multiple'],
        ]);

        $emailTypeId = $this->getOrCreateField([
            'key' => 'email_type',
            'system_label' => 'Email Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($emailTypeId, [
            ['value' => 'newsletter', 'system_label' => 'Newsletter'],
            ['value' => 'campaign', 'system_label' => 'Campaign'],
            ['value' => 'transactional', 'system_label' => 'Transactional'],
            ['value' => 'launch', 'system_label' => 'Launch'],
            ['value' => 'promotional', 'system_label' => 'Promotional'],
        ]);

        $webTypeId = $this->getOrCreateField([
            'key' => 'web_type',
            'system_label' => 'Web Type',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => true,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($webTypeId, [
            ['value' => 'landing_page', 'system_label' => 'Landing Page'],
            ['value' => 'homepage', 'system_label' => 'Homepage'],
            ['value' => 'product_page', 'system_label' => 'Product Page'],
            ['value' => 'blog_feature', 'system_label' => 'Blog Feature'],
            ['value' => 'microsite', 'system_label' => 'Microsite'],
            ['value' => 'banner_module', 'system_label' => 'Banner Module'],
        ]);

        $channelPlatformId = $this->getOrCreateField([
            'key' => 'channel_platform',
            'system_label' => 'Channel / Platform',
            'type' => 'select',
            'applies_to' => 'all',
            'scope' => 'system',
            'group_key' => 'creative',
            'is_filterable' => true,
            'is_user_editable' => true,
            'is_ai_trainable' => false,
            'is_upload_visible' => true,
            'is_internal_only' => false,
            'ai_eligible' => false,
            'display_widget' => 'select',
        ]);
        $this->syncOptions($channelPlatformId, [
            ['value' => 'google', 'system_label' => 'Google'],
            ['value' => 'meta', 'system_label' => 'Meta'],
            ['value' => 'amazon', 'system_label' => 'Amazon'],
            ['value' => 'programmatic', 'system_label' => 'Programmatic'],
            ['value' => 'internal', 'system_label' => 'Internal'],
            ['value' => 'website', 'system_label' => 'Website'],
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
            'dominant_hue_group', // System automated - perceptual hue cluster for filtering
        ];

        foreach ($automaticFields as $fieldKey) {
            // dominant_colors: display-only, never in filters
            // dominant_hue_group: filter-only, secondary filters when enabled
            $isDominantColors = ($fieldKey === 'dominant_colors');
            $isDominantHueGroup = ($fieldKey === 'dominant_hue_group');
            $showOnEdit = $isDominantHueGroup ? false : true; // dominant_hue_group never in Quick View
            $showInFilters = $isDominantColors ? false : true;  // dominant_colors never in filters; hue group filter-only

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
        $tenants = \App\Models\Tenant::with('brands')->get();

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
     * @param  array  $options  Array of ['value' => string, 'system_label' => string]
     */
    /**
     * Sync options for a select field: keep only the given options (remove others), then create/update as needed.
     *
     * @param  array  $options  Array of ['value' => string, 'system_label' => string]
     */
    protected function syncOptions(int $fieldId, array $options): void
    {
        $allowedValues = array_column($options, 'value');
        if (! empty($allowedValues)) {
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
     * @param  array  $options  Array of ['value' => string, 'system_label' => string]
     */
    protected function createOptions(int $fieldId, array $options): void
    {
        foreach ($options as $option) {
            $existingOption = DB::table('metadata_options')
                ->where('metadata_field_id', $fieldId)
                ->where('value', $option['value'])
                ->first();

            if (! $existingOption) {
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
