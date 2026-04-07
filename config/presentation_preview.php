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
];
