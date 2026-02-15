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
            'is_filter_hidden' => true,
            'is_primary' => null,
        ],
    ],

    /*
     * System fields never shown in upload, quick view, or filters.
     */
    'always_hidden_fields' => ['dimensions', 'dominant_color_bucket', 'dominant_colors'],
];
