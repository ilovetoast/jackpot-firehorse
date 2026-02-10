<?php

/**
 * Metadata category default visibility configuration.
 *
 * Single source of truth for seeded defaults. Used by:
 * - MetadataFieldsSeeder (configureCategorySettings) to seed category visibility
 * - TenantMetadataVisibilityService::applySeededDefaultsForCategory for "Reset to default"
 *
 * Format: 'field_key' => [
 *     'category_slug' => [
 *         'enabled' => true|false,
 *         'is_primary' => true|false|null,
 *         'ai_eligible' => true|false (optional, field-level override),
 *     ]
 * ]
 *
 * Execution/Deliverables: exactly 10 categories and type fields (no more).
 * For every *_type field: enabled = true for its category, is_primary = true,
 * visible in upload, quick view, and filters. No type field leaks into other categories.
 * Dimensions is always hidden via always_hidden_fields.
 */
return [
    /*
     * Per-field, per-category-slug settings. If a field/slug is listed here,
     * that visibility is applied. enabled = !is_hidden; is_primary for filter placement.
     */
    'category_config' => [
        // General
        'collection' => [
            'photography' => ['enabled' => true, 'is_primary' => true],
        ],
        'tags' => [], // Enabled everywhere by default (not in restrict_fields)

        // --- Asset type fields (one type field per category) ---
        'photo_type' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => true,
                'ai_eligible' => true,
            ],
        ],
        'logo_type' => [
            'logos' => [
                'enabled' => true,
                'is_primary' => true,
                'ai_eligible' => false,
            ],
        ],
        'graphic_type' => [
            'graphics' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'video_type' => [
            'video' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'template_type' => [
            'templates' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'audio_type' => [
            'audio' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'model_3d_type' => [
            'model-3d' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],

        // --- Execution / Deliverables type fields (exactly these 10; no others) ---
        // 1. Print  2. Digital  3. OOH  4. Events  5. Video (Executions)  6. Sales Collateral
        // 7. PR  8. Packaging  9. Product Renders  10. Radio
        'print_type' => [
            'print' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'digital_type' => [
            'digital-ads' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'ooh_type' => [
            'ooh' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'event_type' => [
            'events' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'execution_video_type' => [
            'videos' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'sales_collateral_type' => [
            'sales-collateral' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'pr_type' => [
            'pr' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'packaging_type' => [
            'packaging' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'product_render_type' => [
            'product-renders' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'radio_type' => [
            'radio' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],

        // Other fields (category-specific where needed)
        'expiration_date' => [
            'photography' => ['enabled' => true, 'is_primary' => true],
        ],
        'scene_classification' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => true,
                'ai_eligible' => true,
            ],
        ],
        'usage_rights' => [
            'photography' => ['enabled' => true, 'is_primary' => false],
        ],
        'dominant_colors' => [],
        'dominant_color_bucket' => [],
    ],

    /*
     * Fields that are only enabled for category slugs listed in category_config for that field.
     * All other categories get these fields disabled (is_hidden = true).
     */
    'restrict_fields' => [
        'expiration_date',
        'usage_rights',
        'scene_classification',
        'photo_type',
        'logo_type',
        'graphic_type',
        'video_type',
        'template_type',
        'audio_type',
        'model_3d_type',
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
    ],

    /*
     * Video category (asset): only tags and collection enabled; all other fields disabled.
     */
    'video_slug' => 'video',

    /*
     * Dominant color fields: special defaults for all image (asset_type=asset) categories.
     * dominant_colors: enabled, hidden from upload, not filterable by default.
     * dominant_color_bucket: enabled, hidden from upload, filterable.
     */
    'dominant_colors_visibility' => [
        'dominant_colors' => [
            'is_hidden' => false,
            'is_upload_hidden' => true,
            'is_filter_hidden' => true,
            'is_primary' => null,
        ],
        'dominant_color_bucket' => [
            'is_hidden' => false,
            'is_upload_hidden' => true,
            'is_filter_hidden' => false,
            'is_primary' => null,
        ],
    ],

    /*
     * Category slugs that get only tags and collection enabled (e.g. asset video).
     */
    'tags_and_collection_only_slugs' => ['video'],

    /*
     * System fields that are behind-the-scenes only: never shown in upload, quick view, or filters.
     * Seeder sets is_hidden=true, is_upload_hidden=true, is_filter_hidden=true for every category.
     * Dimensions: auto-populated from file; must not appear in More filters or filterable schema.
     *
     * Dominant color fields: visible in asset drawer (show_on_edit=true) but never in More filters.
     * They cannot be selected to appear in filters; drawer visibility is configurable per category.
     */
    'always_hidden_fields' => ['dimensions', 'dominant_color_bucket', 'dominant_colors'],
];
