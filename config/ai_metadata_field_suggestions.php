<?php

/**
 * Field-level suggestions derived from strong tag patterns per category.
 */
return [
    /** Minimum assets in the category to consider suggestions. */
    'min_assets' => 25,

    /** Minimum share of category assets that must carry the anchor tag (0–1). */
    'min_ratio' => 0.6,

    /** Minimum distinct co-occurring tags to list as suggested select options (after lift + noise filters). */
    'min_co_occurring_tags' => (int) env('AI_FIELD_SUGGEST_MIN_CO_TAGS', 4),

    /** Max suggested option labels stored on the row (keep small — options must be anchor-relevant). */
    'max_suggested_options' => (int) env('AI_FIELD_SUGGEST_MAX_OPTIONS', 12),

    /** Max anchor tags (strongest by ratio) evaluated per category per sync. */
    'max_anchors_per_category' => 5,

    /**
     * Minimum lift: P(co-tag | anchor assets) / P(co-tag | category) must exceed this.
     * Filters values that are frequent in the category but not especially tied to the anchor.
     */
    'min_co_lift_ratio' => (float) env('AI_FIELD_SUGGEST_MIN_LIFT', 1.35),

    /**
     * Regex patterns (case-insensitive); co-options matching any are dropped.
     *
     * @var list<string>
     */
    'co_option_noise_regexes' => [
        '/^\d+x\d+$/i',
        '/^\d+\s*x\s*\d+$/',
        '/^\d{3,5}\s*x\s*\d{3,5}$/',
        '/^[#]?[0-9a-f]{6}$/i',
        '/^(srgb|adobe\\s*rgb|prophoto|display\\s*p3|p3)$/i',
        '/^\d+$/',
    ],

    /**
     * Substrings; if the normalized co-option equals or contains these (word-ish), drop.
     *
     * @var list<string>
     */
    'co_option_blocklist_substrings' => [
        'photoshop',
        'lightroom',
        'illustrator',
        'capture one',
        'emerge-pdp',
        'jan-2026',
        '-select',
        'selects',
    ],

    /**
     * Skip if this fraction of co-occurring tags already match existing select/multiselect
     * option labels (case-insensitive) for the tenant — pattern already modeled.
     */
    'max_option_coverage_ratio' => 0.45,

    /**
     * Tags to ignore as anchor or co-tags (too generic).
     *
     * @var list<string>
     */
    'stop_tags' => [
        'tagged',
        'new',
        'photo',
        'image',
        'asset',
        'jpg',
        'jpeg',
        'png',
        'webp',
        'raw',
    ],

    /**
     * Anchors that are too broad for automatic field naming — skip (no field suggestion).
     *
     * @var list<string>
     */
    'naming_skip_anchors' => [
        'outdoor',
        'lifestyle',
    ],

    /**
     * Anchors treated as “species”-style fields (not naive *ing → *_species).
     *
     * @var list<string>
     */
    'naming_species_anchors' => [
        'fishing',
        'hunting',
    ],

    /** Max rows returned on Insights → Review (values + fields tabs). */
    'insight_review_max_items' => 50,

    /**
     * Minimum minutes between manual “Run library pattern scan” requests (Insights / AI settings).
     * The scheduled job still uses its own 24h cooldown; this only limits repeat queue clicks.
     */
    'manual_insights_run_cooldown_minutes' => (int) env('MANUAL_INSIGHTS_RUN_COOLDOWN_MINUTES', 45),
];
