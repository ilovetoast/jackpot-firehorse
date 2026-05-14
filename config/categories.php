<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Max visible categories per brand (by asset_type)
    |--------------------------------------------------------------------------
    |
    | Non-hidden categories count toward this cap. Applies to asset and
    | deliverable libraries only (not reference_material / REFERENCE).
    |
    */
    'max_visible_per_brand_by_asset_type' => [
        'asset' => 20,
        'deliverable' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder Quick Filters (Phase 1 — foundation only)
    |--------------------------------------------------------------------------
    |
    | Future feature: when a user selects a folder, surface a small nested list
    | of "quick filter" shortcuts under it. These are NOT a separate filter
    | engine — they are shortcuts into the existing metadata filter system
    | (see MetadataFieldFilterEligibility, TenantMetadataVisibilityService,
    | filterTierResolver).
    |
    | Phase 1 scope: config + eligibility service only. No UI, no DB rows,
    | no count queries, no AI suggestions, no behavioral changes to any
    | existing filter / category code path.
    |
    | Type names map to the existing `metadata_fields.type` string vocabulary
    | (`select` / `multiselect` / `boolean` / `text` / `number` / `date` /
    | `rating` / `computed`). The "single_select" / "multi_select" aliases
    | accepted here exist so future request payloads / configs can use either
    | spelling without churn — the eligibility service normalizes both.
    |
    */
    'folder_quick_filters' => [
        'enabled' => true,
        'desktop_only' => true,
        'max_visible_per_folder' => 3,
        'max_values_per_filter' => 12,
        'show_counts' => true,
        'lazy_load_values' => true,
        'allowed_types' => [
            'single_select',
            'multi_select',
            'boolean',
        ],

        /*
         * Phase 2 (architecture only — not enforced yet).
         *
         * Future ceiling on how many distinct values a filter may have before it
         * becomes ineligible as a quick filter. Prevents UI explosions like
         * "Subject: 20,000 AI-generated tags" landing in the sidebar. Nothing
         * reads this number today; the assignment + eligibility services intend
         * to consult it once we have a value-cardinality source of truth.
         */
        'max_distinct_values_for_quick_filter' => 100,

        /*
         * Provenance vocabulary for `metadata_field_visibility.folder_quick_filter_source`.
         * The seeder writes 'seeded'; admin toggles write 'manual'; Phase 3+ may
         * write 'ai_suggested'. Values are stored as a nullable string so future
         * additions do not require a schema change.
         */
        'sources' => [
            'seeded' => 'seeded',
            'manual' => 'manual',
            'ai_suggested' => 'ai_suggested',
        ],

        /*
         * Phase 4 — value picker flyout limits.
         *
         * `max_visible_values_per_filter`: hard ceiling the values endpoint
         *   returns per request. Higher counts truncate; the response sets
         *   `has_more=true` so the UI can show the truncation notice. Keeps
         *   sidebar payloads small for AI-generated tag fields with long tails.
         * `search_threshold`: when the (limited) value list is longer than this,
         *   the flyout renders a small client-side filter input. Below this it
         *   stays a flat list — no input on a 4-option filter.
         * `close_on_single_select` / `close_on_boolean_select`: drives whether
         *   the flyout dismisses itself after a definitive single-value pick.
         *   Multi-select always stays open after a toggle.
         */
        'max_visible_values_per_filter' => 20,
        'search_threshold' => 12,
        'close_on_single_select' => true,
        'close_on_boolean_select' => true,

        /*
         * Phase 5 — facet/value count behavior.
         *
         * `counts_enabled`: master switch for the count column in the flyout.
         *   When false, the value endpoint omits counts and the UI degrades
         *   gracefully to label-only.
         * `facet_cache_enabled`: short-TTL caching of provider results.
         *   Keyed by tenant + brand + folder + field + active-filter hash.
         * `facet_cache_ttl_seconds`: TTL for the cache. Counts in a DAM
         *   are not lifecycle-critical; 30s is enough to absorb a single
         *   user opening the same flyout repeatedly without paying for
         *   the per-value count queries again.
         */
        'counts_enabled' => true,
        'facet_cache_enabled' => true,
        'facet_cache_ttl_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder Quick Filter Defaults (mapping only — no DB rows seeded yet)
    |--------------------------------------------------------------------------
    |
    | Default suggested quick filters keyed by system category slug. Phase 2
    | will read this map when seeding tenant rows and presenting "reset to
    | default" affordances. Phase 1 ships the map as static reference data
    | only; nothing reads from this key yet, by design.
    |
    | Slugs mirror SystemCategoryTemplateSeeder. Field keys mirror
    | MetadataFieldsSeeder (e.g. `photo_type`, `social_format`).
    |
    */
    'folder_quick_filter_defaults' => [
        // Phase 2 narrowed these maps from the Phase-1 reference data. The
        // explicit goal is "intentionally minimal" — quick filters are a
        // contextual narrowing surface, not a registry. Every entry below
        // must clear the eligibility service (single_select / multi_select /
        // boolean only) AND not exceed `max_visible_per_folder` per folder.
        // Order in each array is the seeded `folder_quick_filter_order`.
        'asset_folders' => [
            'photography' => ['photo_type', 'environment_type', 'subject_type'],
            'graphics' => ['graphic_type', 'subject_type'],
            'illustrations' => ['graphic_type', 'subject_type'],
            'brand-elements' => ['graphic_type'],
            'logos' => ['logo_type'],
            'fonts' => ['font_role'],
            'video' => ['video_type'],
            'audio' => ['audio_type'],
            'templates' => ['template_type'],
            'model-3d' => ['model_3d_type'],
            'documents' => [],
        ],
        'execution_folders' => [
            'social' => ['social_platform', 'social_format'],
            'digital-ads' => ['digital_type'],
            'web' => ['web_type'],
            'print' => ['print_type'],
            'videos' => ['execution_video_type'],
            'packaging' => ['packaging_type'],
            'ooh' => ['ooh_type'],
            'sales-collateral' => ['sales_collateral_type'],
            'pr' => ['pr_type'],
            'events' => ['event_type'],
            'email' => ['email_type'],
            'product-renders' => ['product_render_type'],
            'radio' => ['radio_type'],
        ],
        'special_folders' => [
            'reference_material' => [],
        ],
    ],
];
