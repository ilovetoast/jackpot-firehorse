<?php

return [
    'page_types' => [
        'cover',
        'table_of_contents',
        'brand_story',
        'strategy',
        'archetype',
        'purpose',
        'promise',
        'positioning',
        'beliefs',
        'values',
        'brand_voice',
        'brand_style',
        'visual_identity',
        'typography',
        'color_palette',
        'logo_usage',
        'photography',
        'example_gallery',
        'product_examples',
        'contact',
        'appendix',
        'unknown',
    ],

    'allowed_fields_by_page_type' => [
        'archetype' => [
            'personality.primary_archetype',
            'personality.traits',
            'personality.tone_keywords',
        ],
        'purpose' => [
            'identity.mission',
            'identity.vision',
        ],
        'promise' => [
            'identity.positioning',
        ],
        'positioning' => [
            'identity.positioning',
            'identity.industry',
            'identity.tagline',
        ],
        'beliefs' => [
            'identity.beliefs',
        ],
        'values' => [
            'identity.values',
        ],
        'brand_voice' => [
            'personality.tone_keywords',
            'personality.traits',
        ],
        'brand_story' => [
            'identity.mission',
            'identity.positioning',
            'identity.beliefs',
            'identity.values',
        ],
        'strategy' => [
            'identity.mission',
            'identity.positioning',
            'identity.industry',
            'identity.tagline',
        ],
        'color_palette' => [
            'visual.primary_colors',
            'visual.secondary_colors',
            'scoring_rules.allowed_color_palette',
        ],
        'typography' => [
            'visual.fonts',
            'typography.primary_font',
            'typography.secondary_font',
            'typography.heading_style',
            'typography.body_style',
        ],
        'logo_usage' => [
            'visual.logo_detected',
        ],
        'photography' => [
            'visual.photography_style',
            'visual.visual_style',
        ],
        'example_gallery' => [
            'visual.photography_style',
            'visual.visual_style',
            'visual.design_cues',
        ],
        'product_examples' => [
            'visual.photography_style',
            'visual.visual_style',
        ],
        'visual_identity' => [
            'visual.primary_colors',
            'visual.fonts',
            'visual.logo_detected',
        ],
        'brand_style' => [
            'visual.visual_style',
            'personality.tone_keywords',
        ],
        'cover' => [],
        'table_of_contents' => [],
        'contact' => [],
        'appendix' => [],
        'unknown' => [],
    ],

    'extraction_priority_by_page_type' => [
        'high' => ['archetype', 'purpose', 'promise', 'positioning', 'color_palette', 'typography', 'brand_voice'],
        'medium' => ['brand_story', 'strategy', 'beliefs', 'values', 'logo_usage', 'visual_identity', 'brand_style'],
        'low' => ['cover', 'photography', 'example_gallery', 'product_examples', 'table_of_contents', 'contact', 'appendix', 'unknown'],
    ],

    'routing_rules' => [
        'table_of_contents' => ['use_for_structure_only' => true, 'no_content_extraction' => true],
        'example_gallery' => ['no_strategic_fields' => true, 'only_visual_cues' => true],
        'product_examples' => ['no_strategic_fields' => true],
        'color_palette' => ['prefer_visual' => true],
        'typography' => ['prefer_visual' => true],
        'cover' => ['extraction_priority' => 'low'],
        'appendix' => ['extraction_priority' => 'low'],
        'contact' => ['extraction_priority' => 'low'],
    ],

    'aggressive_auto_trust_fields' => [
        'visual.primary_colors',
        'visual.secondary_colors',
        'scoring_rules.allowed_color_palette',
        'visual.fonts',
        'typography.primary_font',
        'typography.secondary_font',
        'visual.logo_detected',
        'visual.photography_style',
    ],

    'conservative_suggestion_fields' => [
        'identity.mission',
        'identity.positioning',
        'personality.primary_archetype',
        'personality.tone_keywords',
        'identity.values',
        'identity.beliefs',
    ],
];
