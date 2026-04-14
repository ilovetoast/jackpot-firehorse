<?php

/**
 * Template-based composited "enhanced" previews (async only; does not run in main thumbnail pipeline).
 *
 * @see \App\Jobs\GenerateEnhancedPreviewJob
 * @see \App\Services\TemplateRenderer
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Transparent Studio plate (no template gradient / no GD drop shadow block)
    |--------------------------------------------------------------------------
    |
    | When true, enhanced (Studio) thumbnails are composited on a fully transparent
    | canvas so CSS presentation presets (desk / wall / neutral) read through padding
    | and transparent areas. Drop shadow is left to the client (ExecutionPresentationFrame).
    | Set to false to restore the legacy gradient card behind the crop.
    |
    */
    'transparent_plate' => filter_var(env('ENHANCED_PREVIEW_TRANSPARENT_PLATE', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Styles to generate for enhanced mode
    |--------------------------------------------------------------------------
    |
    | Large composites are expensive; default mirrors practical drawer/grid use.
    |
    */
    'styles' => array_values(array_filter(array_map('trim', explode(',', (string) env('ENHANCED_PREVIEW_STYLES', 'thumb,medium'))))),

    /*
    |--------------------------------------------------------------------------
    | Template visual presets (catalog / surface / neutral)
    |--------------------------------------------------------------------------
    */
    'templates' => [
        'catalog_v1' => [
            // Bump when compositing or pre-crop behavior changes (staleness / regenerate)
            'version' => env('ENHANCED_TEMPLATE_CATALOG_V1_VERSION', '1.2.2'),
            'bg_top' => [245, 247, 250],
            'bg_bottom' => [220, 225, 232],
            'shadow_alpha' => 55,
            'shadow_offset' => 5,
            // Tighter frame after inner crop so the ad fills more of the card
            'padding_ratio' => (float) env('ENHANCED_TEMPLATE_CATALOG_PADDING', 0.055),
        ],
        'surface_v1' => [
            'version' => env('ENHANCED_TEMPLATE_SURFACE_V1_VERSION', '1.2.2'),
            'bg_top' => [252, 252, 250],
            'bg_bottom' => [235, 232, 226],
            'shadow_alpha' => 50,
            'shadow_offset' => 6,
            'padding_ratio' => (float) env('ENHANCED_TEMPLATE_SURFACE_PADDING', 0.055),
        ],
        'neutral_v1' => [
            'version' => env('ENHANCED_TEMPLATE_NEUTRAL_V1_VERSION', '1.2.2'),
            'bg_top' => [248, 248, 248],
            'bg_bottom' => [232, 232, 232],
            'shadow_alpha' => 60,
            'shadow_offset' => 4,
            'padding_ratio' => (float) env('ENHANCED_TEMPLATE_NEUTRAL_PADDING', 0.055),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-crop: trim print-proof margins before compositing (enhanced mode only)
    |--------------------------------------------------------------------------
    |
    | Column/row luminance variance on a downscaled copy finds the main content
    | band and drops outer crop marks / color bars when they sit in calmer margins.
    |
    */
    'content_crop' => [
        'enabled' => filter_var(env('ENHANCED_PREVIEW_CONTENT_CROP', true), FILTER_VALIDATE_BOOL),
        'analysis_max_width' => (int) env('ENHANCED_PREVIEW_CONTENT_CROP_MAX_W', 420),
        'min_side_ratio' => (float) env('ENHANCED_PREVIEW_CONTENT_CROP_MIN_SIDE', 0.38),
        'edge_ignore_ratio' => (float) env('ENHANCED_PREVIEW_CONTENT_CROP_EDGE_IGNORE', 0.02),
        'variance_threshold_ratio' => (float) env('ENHANCED_PREVIEW_CONTENT_CROP_VAR_RATIO', 0.12),
        'content_pad_ratio' => (float) env('ENHANCED_PREVIEW_CONTENT_CROP_PAD', 0.015),
    ],

    /*
    |--------------------------------------------------------------------------
    | Guardrails (business logic; separate from Laravel job tries)
    |--------------------------------------------------------------------------
    */
    'max_attempts' => (int) env('ENHANCED_PREVIEW_MAX_ATTEMPTS', 2),

    'cooldown_seconds' => (int) env('ENHANCED_PREVIEW_COOLDOWN_SECONDS', 60),
];
