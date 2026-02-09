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
 */
return [
    /*
     * Per-field, per-category-slug settings. If a field/slug is listed here,
     * that visibility is applied. enabled = !is_hidden; is_primary for filter placement.
     */
    'category_config' => [
        'collection' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'photo_type' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => true,
                'ai_eligible' => true,
            ],
            'logos' => [
                'enabled' => false,
            ],
        ],
        'expiration_date' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => true,
            ],
        ],
        'scene_classification' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => true,
                'ai_eligible' => true,
            ],
        ],
        'usage_rights' => [
            'photography' => [
                'enabled' => true,
                'is_primary' => false,
            ],
        ],
        'logo_type' => [
            'logos' => [
                'enabled' => true,
                'is_primary' => true,
                'ai_eligible' => true,
            ],
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
        'logo_type',
        'scene_classification',
        'photo_type',
    ],

    /*
     * Video category: only tags and collection enabled; all other fields disabled.
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
     * Category slugs that get only tags and collection enabled (e.g. video).
     */
    'tags_and_collection_only_slugs' => ['video'],

    /*
     * System fields that must never be enabled for any category (upload, quick view, filters).
     * Seeder sets is_hidden=true for every category so they never appear in category-level config.
     */
    'always_hidden_fields' => ['dimensions'],
];
