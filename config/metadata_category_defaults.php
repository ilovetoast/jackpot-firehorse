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
 * enabled for every category by default so execution assets receive computed metadata
 * without manual enablement.
 *
 * system_fields_hidden_for_category_slugs: omit specific system fields
 * from the tags+collection+system baseline for listed category slugs.
 *
 * Extension notes (not seeded as fields yet):
 * - campaign: prefer linking to Campaign records when that model is wired for metadata.
 * - audience, region: free text or controlled vocabulary fields — add as tenant custom
 *   or system fields when product scope is finalized.
 * - status: use asset / workflow status elsewhere; not duplicated as metadata_fields.status.
 */
return [
    'system_automated_enabled_for_all' => [
        'orientation',
        'resolution_class',
        'color_space',
        'dominant_colors',
        'dominant_hue_group',
    ],

    /*
     * Category slugs that start from the “tags + collection + all system fields” baseline.
     * Type and other restrict_fields are layered via category_config.
     */
    'tags_and_collection_only_slugs' => [
        // Assets
        'photography',
        'graphics',
        'logos',
        'video',
        'audio',
        'documents',
        'templates',
        'fonts',
        'model-3d',
        'illustrations',
        'brand-elements',
        // Deliverables / executions
        'social',
        'digital-ads',
        'print',
        'videos',
        'packaging',
        'ooh',
        'sales-collateral',
        'pr',
        'events',
        'web',
        'email',
        // Legacy templates (hidden / no auto-provision for new brands)
        'product-renders',
        'radio',
    ],

    'category_config' => [
        // --- Asset type fields ---
        'photo_type' => [
            'photography' => ['enabled' => true, 'is_primary' => true],
        ],
        'environment_type' => [
            'photography' => ['enabled' => true, 'is_primary' => false],
            'graphics' => ['enabled' => true, 'is_primary' => false],
            'illustrations' => ['enabled' => true, 'is_primary' => false],
            'brand-elements' => ['enabled' => true, 'is_primary' => false],
        ],
        'subject_type' => [
            'photography' => ['enabled' => true, 'is_primary' => false],
            'graphics' => ['enabled' => true, 'is_primary' => false],
            'illustrations' => ['enabled' => true, 'is_primary' => false],
            'brand-elements' => ['enabled' => true, 'is_primary' => false],
        ],
        'logo_type' => [
            'logos' => ['enabled' => true, 'is_primary' => true],
        ],
        'font_role' => [
            'fonts' => ['enabled' => true, 'is_primary' => true],
        ],
        'graphic_type' => [
            'graphics' => ['enabled' => true, 'is_primary' => true],
            'illustrations' => ['enabled' => true, 'is_primary' => true],
            'brand-elements' => ['enabled' => true, 'is_primary' => true],
        ],
        'video_type' => [
            'video' => ['enabled' => true, 'is_primary' => true],
        ],
        'template_type' => [
            'templates' => ['enabled' => true, 'is_primary' => true],
        ],
        'audio_type' => [
            'audio' => ['enabled' => true, 'is_primary' => true],
        ],
        'model_3d_type' => [
            'model-3d' => ['enabled' => true, 'is_primary' => true],
        ],

        // --- Deliverable / execution type fields ---
        'social_format' => [
            'social' => ['enabled' => true, 'is_primary' => true],
        ],
        'social_platform' => [
            'social' => ['enabled' => true, 'is_primary' => false],
        ],
        'digital_type' => [
            'digital-ads' => ['enabled' => true, 'is_primary' => true],
        ],
        'channel_platform' => [
            'digital-ads' => ['enabled' => true, 'is_primary' => false],
            'social' => ['enabled' => true, 'is_primary' => false],
            'web' => ['enabled' => true, 'is_primary' => false],
        ],
        'print_type' => [
            'print' => ['enabled' => true, 'is_primary' => true],
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
        // pr_type select options (PR / Communications): see MetadataFieldsSeeder — press_release, media_kit, internal, fact_sheet, announcement
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
        'email_type' => [
            'email' => ['enabled' => true, 'is_primary' => true],
        ],
        'web_type' => [
            'web' => ['enabled' => true, 'is_primary' => true],
        ],
    ],

    'restrict_fields' => [
        'photo_type',
        'logo_type',
        'font_role',
        'graphic_type',
        'video_type',
        'environment_type',
        'subject_type',
        'social_format',
        'social_platform',
        'digital_type',
        'channel_platform',
        'print_type',
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
        'email_type',
        'web_type',
    ],

    /**
     * Default visibility for the pinned asset field (DB key remains `starred`).
     */
    'pinned_default_visibility' => [
        'is_hidden' => false,
        'is_upload_hidden' => false,
        'is_filter_hidden' => true,
        'is_primary' => false,
        'is_edit_hidden' => false,
    ],

    'dominant_colors_visibility' => [
        'dominant_colors' => [
            'is_hidden' => false,
            'is_upload_hidden' => false,
            'is_filter_hidden' => true,
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

    /**
     * System field keys hidden for specific category slugs (baseline: tags + collection + all system fields).
     * Evaluated in TenantMetadataVisibilityService::computeSeededDefaultForField for tags_and_collection_only_slugs.
     */
    'system_fields_hidden_for_category_slugs' => [],

    'restricted_option_edit_keys' => [
        'collection',
        'tags',
        'quality_rating',
        'starred',
        'dominant_hue_group',
        'dominant_colors',
    ],
];
