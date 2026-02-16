<?php

/**
 * Metadata category default visibility configuration.
 *
 * Single source of truth for seeded defaults. Used by:
 * - TenantMetadataVisibilityService::applySeededDefaultsForCategory (new categories, Reset to default)
 *
 * Content Model: Default enabled fields limited to collection + tags.
 * No auto-enabling of type-based fields. No UI-only flags in seed data.
 */
return [
    /*
     * Category slugs that get only collection and tags enabled.
     * All system category templates use this minimal default.
     */
    'tags_and_collection_only_slugs' => [
        'logos',
        'photography',
        'graphics',
        'video',
        'print',
        'digital-ads',
        'ooh',
        'events',
        'videos',
        'sales-collateral',
        'pr',
        'packaging',
        'product-renders',
        'radio',
    ],

    /*
     * Per-field, per-category overrides (optional).
     * Empty = all categories use tags_and_collection_only.
     */
    'category_config' => [],

    /*
     * Fields restricted to specific slugs. Empty = no type-based auto-enabling.
     */
    'restrict_fields' => [],

    /*
     * Dominant color fields: special defaults for image (asset) categories.
     * Disabled by default; can be enabled per category by user.
     *
     * dominant_color_bucket: filter-only field.
     * - Never in Quick View, Upload, or Primary filters.
     * - Appears in Secondary filters only when is_filter_hidden=false (user enables).
     * - Uses ColorSwatchFilter. Enforced via filter_only_enforced_fields.
     */
    'dominant_colors_visibility' => [
        'dominant_colors' => [
            'is_hidden' => true,
            'is_upload_hidden' => true,
            'is_filter_hidden' => true,
            'is_primary' => null,
        ],
        'dominant_color_bucket' => [
            'is_hidden' => true,
            'is_upload_hidden' => true,
            'is_edit_hidden' => true, // Never in Quick View
            'is_filter_hidden' => true,
            'is_primary' => false, // Never primary; secondary only when enabled
        ],
    ],

    /*
     * System fields never shown in upload, quick view, or filters.
     * Excludes dominant_color_bucket: it is filter-only (see filter_only_enforced_fields).
     */
    'always_hidden_fields' => ['dimensions', 'dominant_colors'],

    /*
     * Filter-only fields: never in Quick View, Upload, or Primary.
     * Appear in Secondary filters only when is_filter_hidden=false (user enables).
     * When saving visibility: force is_primary=false, is_upload_hidden=true, is_edit_hidden=true.
     */
    'filter_only_enforced_fields' => ['dominant_color_bucket'],

    /*
     * System fields that use custom rendering and do NOT support manual option editing.
     * Product integrity rule: these fields are not customizable option fields.
     * Used by: MetadataFieldModal (hide Options), addValue endpoint, TenantMetadataFieldService.
     * Also restricted: fields with custom display_widget or type=rating (checked at runtime).
     */
    'restricted_option_edit_keys' => [
        'collection',
        'tags',
        'quality_rating',
        'starred',
        'dominant_color_bucket',
        'dominant_colors',
    ],
];
