<?php

/**
 * Metadata category default visibility configuration.
 *
 * Single source of truth for seeded defaults. Used by:
 * - TenantMetadataVisibilityService::applySeededDefaultsForCategory (new categories, Reset to default)
 *
 * System fields (scope=system) are enabled for all categories by default.
 * Tenant-scoped fields: only tags and collection enabled unless in category_config.
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
     * Enables type fields for their corresponding system categories (e.g. photo_type for photography).
     * Checked before tags_and_collection_only so these override the minimal default.
     */
    'category_config' => [
        // Asset categories: type fields
        'photo_type' => [
            'photography' => ['enabled' => true, 'is_primary' => true],
        ],
        'logo_type' => [
            'logos' => ['enabled' => true, 'is_primary' => true],
        ],
        'graphic_type' => [
            'graphics' => ['enabled' => true, 'is_primary' => true],
        ],
        'video_type' => [
            'video' => ['enabled' => true, 'is_primary' => true],
        ],
        // Scene classification for image categories
        'scene_classification' => [
            'photography' => ['enabled' => true, 'is_primary' => false],
            'graphics' => ['enabled' => true, 'is_primary' => false],
        ],
        // Deliverable categories: type fields
        'print_type' => [
            'print' => ['enabled' => true, 'is_primary' => true],
        ],
        'digital_type' => [
            'digital-ads' => ['enabled' => true, 'is_primary' => true],
        ],
        'ooh_type' => [
            'ooh' => ['enabled' => true, 'is_primary' => true],
        ],
        'event_type' => [
            'events' => ['enabled' => true, 'is_primary' => true],
        ],
        'execution_video_type' => [
            'videos' => ['enabled' => true, 'is_primary' => true],
        ],
        'sales_collateral_type' => [
            'sales-collateral' => ['enabled' => true, 'is_primary' => true],
        ],
        'pr_type' => [
            'pr' => ['enabled' => true, 'is_primary' => true],
        ],
        'packaging_type' => [
            'packaging' => ['enabled' => true, 'is_primary' => true],
        ],
        'product_render_type' => [
            'product-renders' => ['enabled' => true, 'is_primary' => true],
        ],
        'radio_type' => [
            'radio' => ['enabled' => true, 'is_primary' => true],
        ],
    ],

    /*
     * Fields restricted to specific slugs. Empty = no type-based auto-enabling.
     */
    'restrict_fields' => [],

    /*
     * Dominant color fields: special defaults for image (asset) categories.
     * dominant_hue_group: filter-only field (perceptual hue clusters).
     */
    'dominant_colors_visibility' => [
        'dominant_colors' => [
            'is_hidden' => true,
            'is_upload_hidden' => true,
            'is_filter_hidden' => true,
            'is_primary' => null,
        ],
        'dominant_hue_group' => [
            'is_hidden' => true,
            'is_upload_hidden' => true,
            'is_edit_hidden' => true,
            'is_filter_hidden' => true,
            'is_primary' => false,
        ],
    ],

    'always_hidden_fields' => ['dimensions', 'dominant_colors'],

    'filter_only_enforced_fields' => ['dominant_hue_group'],

    'restricted_option_edit_keys' => [
        'collection',
        'tags',
        'quality_rating',
        'starred',
        'dominant_hue_group',
        'dominant_colors',
    ],
];
