<?php

/**
 * AI presentation preview thumbnails (async job; drawer-triggered).
 *
 * @see \App\Jobs\GeneratePresentationPreviewJob
 */
return [

    'max_attempts' => max(1, (int) env('PRESENTATION_PREVIEW_MAX_ATTEMPTS', 3)),

    'cooldown_seconds' => max(0, (int) env('PRESENTATION_PREVIEW_COOLDOWN_SECONDS', 120)),

    /** Minimum source raster dimensions (preferred or original pipeline thumbnail). */
    'min_source_width' => max(1, (int) env('PRESENTATION_PREVIEW_MIN_SOURCE_WIDTH', 400)),
    'min_source_height' => max(1, (int) env('PRESENTATION_PREVIEW_MIN_SOURCE_HEIGHT', 400)),

    /**
     * Thumbnail styles to generate under metadata.thumbnails.presentation.*.
     * Must exist in config/assets.php thumbnail_styles.
     */
    'styles' => ['thumb', 'medium'],

    /** Registry key in config/ai.php models (e.g. gpt-image-1). */
    'model_key' => env('PRESENTATION_PREVIEW_MODEL_KEY', 'gpt-image-1'),

    /** Agent id in config/ai.php agents. */
    'agent_id' => 'presentation_preview',

    /**
     * Allowed model registry keys; when null, falls back to generative_editor.edit_allowed_model_keys,
     * then allowed_model_keys.
     */
    'allowed_model_keys' => null,

    /**
     * Prepended to every presentation-preview image-edit prompt (before optional user scene text).
     * Emphasize: preserve supplied creative; only environmental integration.
     */
    'ai_instruction_prefix' => 'Take this piece of creative and preserve it exactly: do not modify, redraw, replace, or crop away the artwork itself—only integrate it believably into a scene. The output will be used for marketing presentations and asset reviews. ',

    /** Max length for optional user "environment" line from the compare modal (stored & echoed in metadata). */
    'max_scene_description_length' => max(32, min(2000, (int) env('PRESENTATION_PREVIEW_MAX_SCENE_CHARS', 500))),
];
