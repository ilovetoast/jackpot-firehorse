<?php

/**
 * Studio Versions (color × scene × optional format pack) generation.
 *
 * Queue requirement (staging/production): {@see \App\Support\StudioCreativeSetGenerationQueueGuard}
 * rejects {@code QUEUE_CONNECTION=sync} outside local/testing so generation never runs inline on web workers.
 * Configure Redis (or database) + Horizon / queue workers for async item processing.
 */
return [
    'max_colors' => (int) env('STUDIO_CREATIVE_SET_GENERATION_MAX_COLORS', 6),
    'max_scenes' => 5,
    /** Curated output sizes (V1 — no arbitrary canvas sizes). */
    'max_formats' => 3,
    'max_outputs_per_request' => 24,

    /**
     * When true, each generation item skips the generative image edit and marks the duplicate ready.
     * Use with {@code QUEUE_CONNECTION=sync} for deterministic Studio Versions E2E (no external AI).
     */
    'fake_complete_generation' => (bool) env('STUDIO_GENERATION_FAKE_COMPLETE', false),

    /**
     * Curated canvas presets (id-stable for axis keys). Optional keys: group, description, recommended (bool).
     *
     * @var list<array<string, mixed>>
     */
    'preset_formats' => [
        // —— Social (legacy ids preserved for existing sets + Version Builder format pack) ——
        [
            'id' => 'square_1080',
            'group' => 'social',
            'label' => 'Social feed · Square',
            'description' => 'Facebook / Instagram feed, 1:1 (1080).',
            'recommended' => true,
            'width' => 1080,
            'height' => 1080,
        ],
        [
            'id' => 'portrait_1080x1350',
            'group' => 'social',
            'label' => 'Social feed · Portrait',
            'description' => 'Facebook / Instagram feed portrait (4:5).',
            'recommended' => true,
            'width' => 1080,
            'height' => 1350,
        ],
        [
            'id' => 'story_1080x1920',
            'group' => 'social',
            'label' => 'Story / Reels',
            'description' => 'Instagram Story / vertical short video cover (9:16).',
            'recommended' => true,
            'width' => 1080,
            'height' => 1920,
        ],
        [
            'id' => 'social_link_preview',
            'group' => 'social',
            'label' => 'Link preview · Wide',
            'description' => 'LinkedIn / X / Facebook link-style landscape (1.91:1).',
            'width' => 1200,
            'height' => 627,
        ],
        // —— Marketplace ——
        [
            'id' => 'amazon_secondary_square',
            'group' => 'marketplace',
            'label' => 'Amazon · Secondary square',
            'description' => 'High-res square for marketplace secondary / detail slots.',
            'width' => 1600,
            'height' => 1600,
        ],
        [
            'id' => 'amazon_supporting_landscape',
            'group' => 'marketplace',
            'label' => 'Amazon · Supporting landscape',
            'description' => 'Wide supporting graphic / EBC-style module (distinct from web OG 1200×628).',
            'width' => 970,
            'height' => 600,
        ],
        [
            'id' => 'marketplace_square_2000',
            'group' => 'marketplace',
            'label' => 'Marketplace · Square 2K',
            'description' => 'Large square for platforms that prefer 2000px-class masters.',
            'width' => 2000,
            'height' => 2000,
        ],
        // —— Web & display ——
        [
            'id' => 'web_hero_banner',
            'group' => 'web',
            'label' => 'Web · Hero banner',
            'description' => 'Common hero strip for landing pages.',
            'width' => 1920,
            'height' => 600,
        ],
        [
            'id' => 'web_promo_og',
            'group' => 'web',
            'label' => 'Web · Promo / OG',
            'description' => 'Open Graph / paid social landscape promo.',
            'width' => 1200,
            'height' => 628,
        ],
        [
            'id' => 'web_tile_square',
            'group' => 'web',
            'label' => 'Web · Tile / card',
            'description' => 'Smaller square for grids, cards, and merchandising tiles.',
            'width' => 800,
            'height' => 800,
        ],
        // —— Presentation ——
        [
            'id' => 'presentation_slide_16_9',
            'group' => 'presentation',
            'label' => 'Presentation · 16:9 slide',
            'description' => 'Standard widescreen slide / deck frame.',
            'width' => 1920,
            'height' => 1080,
        ],
    ],

    /**
     * Version Builder “Format pack” shortcut: stable ids (subset of preset_formats).
     *
     * @var list<string>
     */
    'format_pack_quick_ids' => [
        'square_1080',
        'portrait_1080x1350',
        'story_1080x1920',
    ],

    /**
     * @var list<string>
     */
    'format_group_order' => ['social', 'marketplace', 'web', 'presentation', 'other'],

    /**
     * @var array<string, string>
     */
    'format_group_labels' => [
        'social' => 'Social',
        'marketplace' => 'Marketplace',
        'web' => 'Web & display',
        'presentation' => 'Presentation',
        'other' => 'Other',
    ],

    /**
     * Placeholders: :label, :hex (hex may be empty).
     */
    'color_instruction_template' => 'Recolor the main product or garment to :label (reference color :hex). Keep the same pose, camera angle, and realism. Do not change logos, text, or background unless needed for seamless integration.',

    /**
     * Built-in scene presets (merged with user-supplied scenes that pass validation).
     */
    'preset_scenes' => [
        [
            'id' => 'studio',
            'label' => 'Studio',
            'instruction' => 'Place the product on a clean neutral studio sweep, soft even lighting, subtle shadow, ecommerce packshot style.',
        ],
        [
            'id' => 'minimal',
            'label' => 'Minimal',
            'instruction' => 'Minimal abstract background, soft gradient, lots of negative space, premium editorial look.',
        ],
        [
            'id' => 'lifestyle_indoor',
            'label' => 'Indoor lifestyle',
            'instruction' => 'Natural indoor lifestyle setting, warm ambient light, realistic context that complements the product.',
        ],
        [
            'id' => 'lifestyle_outdoor',
            'label' => 'Outdoor lifestyle',
            'instruction' => 'Outdoor lifestyle scene with natural daylight, shallow depth of field, believable environment.',
        ],
    ],

    'preset_colors' => [
        ['id' => 'black', 'label' => 'Black', 'hex' => '#111111'],
        ['id' => 'white', 'label' => 'White', 'hex' => '#f5f5f5'],
        ['id' => 'navy', 'label' => 'Navy', 'hex' => '#1a2744'],
        ['id' => 'red', 'label' => 'Red', 'hex' => '#b91c1c'],
        ['id' => 'green', 'label' => 'Green', 'hex' => '#166534'],
        ['id' => 'blue', 'label' => 'Blue', 'hex' => '#1d4ed8'],
    ],
];
