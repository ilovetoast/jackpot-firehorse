<?php

/**
 * Metadata category default visibility configuration.
 *
 * Single source of truth for seeded defaults. Used by:
 * - TenantMetadataVisibilityService::applySeededDefaultsForCategory (new categories, Reset to default)
 *
 * System fields (scope=system) are enabled for all categories by default.
 * Tenant-scoped fields: only tags and collection enabled unless in category_config.
 *
 * System automated fields (orientation, resolution_class, dominant_colors, etc.) are
 * enabled for every category by default so execution assets (Print, Digital, etc.)
 * receive computed metadata without manual enablement.
 */
return [
    /*
     * System automated fields always enabled for every category.
     * Ensures dominant colors, orientation, resolution class, etc. are computed
     * and visible for all assets (including executions) without per-category setup.
     */
    'system_automated_enabled_for_all' => [
        'orientation',
        'resolution_class',
        'color_space',
        'dominant_colors',
        'dominant_hue_group',
    ],
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
        // Type fields for categories that may not exist in all tenants (suppress for others)
        'template_type' => [
            'templates' => ['enabled' => true, 'is_primary' => true],
        ],
        'audio_type' => [
            'audio' => ['enabled' => true, 'is_primary' => true],
        ],
        'model_3d_type' => [
            'model-3d' => ['enabled' => true, 'is_primary' => true],
        ],
    ],

    /*
     * Fields restricted to specific slugs. Type fields listed here are HIDDEN for categories
     * not in their category_config. Ensures e.g. Photography shows only Photo Type, not
     * Logo Type, Print Type, etc. Each type field is enabled only for its assigned category.
     */
    'restrict_fields' => [
        'photo_type',
        'logo_type',
        'graphic_type',
        'video_type',
        'scene_classification',
        'print_type',
        'digital_type',
        'ooh_type',
        'event_type',
        'execution_video_type',
        'sales_collateral_type',
        'pr_type',
        'packaging_type',
        'product_render_type',
        'radio_type',
        'template_type',
        'audio_type',
        'model_3d_type',
    ],

    /*
     * Dominant color fields: enabled by default for all categories.
     * dominant_hue_group: filter-only field (perceptual hue clusters).
     */
    'dominant_colors_visibility' => [
        'dominant_colors' => [
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => true, // Never in More filters (always_hidden_fields)
            'is_primary' => null,
        ],
        'dominant_hue_group' => [
            'is_hidden' => false,
            'is_upload_hidden' => true,
            'is_edit_hidden' => false,
            'is_filter_hidden' => false,
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
