<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Provider
    |--------------------------------------------------------------------------
    |
    | floodfill — local GD flood-fill (basic / free by default). sam — SAM-style contract with local shim
    | or future remote backend; see `sam` and `Studio\LayerExtraction\Providers\SamStudioLayerExtractionProvider`.
    | Inpainting: STUDIO_LAYER_INPAINT_* + `StudioLayerExtractionInpaintBackgroundInterface` (separate from extraction).
    |
    */
    'provider' => env('STUDIO_LAYER_EXTRACTION_PROVIDER', 'floodfill'),

    /** user-facing default for Extract layers: local|ai (ai only when allowed + SAM available). */
    'default_extraction_method' => env('STUDIO_LAYER_EXTRACTION_DEFAULT_METHOD', 'local'),

    /** when false, AI segmentation option and method=ai are disallowed. */
    'allow_ai' => (bool) env('STUDIO_LAYER_EXTRACTION_ALLOW_AI', true),

    /**
     * Optional background inpainting (separate from segmentation). none = disabled UI.
     * @see \App\Studio\LayerExtraction\Providers\HeuristicInpaintBackgroundProvider
     */
    'inpaint_provider' => env('STUDIO_LAYER_INPAINT_PROVIDER', 'none'),

    'inpaint_enabled' => (bool) env('STUDIO_LAYER_INPAINT_ENABLED', false),

    'inpaint' => [
        'max_source_mb' => (int) env('STUDIO_LAYER_INPAINT_MAX_SOURCE_MB', 25),
        'timeout' => (int) env('STUDIO_LAYER_INPAINT_TIMEOUT', 120),
    ],

    /**
     * When false, background fill (Clipdrop, etc.) does not consume or pre-check `studio_layer_background_fill` AI credits.
     * Provider failure must still not charge; this only disables credit gating/tracking.
     */
    'background_fill_credits_enabled' => (bool) env('STUDIO_LAYER_BACKGROUND_FILL_CREDITS_ENABLED', true),

    /**
     * When true, floodfill extraction still bills studio_layer_extraction credits (default off: local is free).
     * SAM / remote-backed providers always bill when extraction succeeds.
     */
    'bill_floodfill_extraction' => (bool) env('STUDIO_LAYER_EXTRACTION_BILL_FLOODFILL', false),

    'floodfill' => [
        'model' => 'gd_floodfill_v1',
        /** Max long edge (px) for segmentation pass; bbox is mapped back to full resolution. */
        'max_segmentation_edge' => (int) env('STUDIO_LAYER_EXTRACTION_MAX_EDGE', 1024),
        /** RGB Euclidean distance (0–441) for classifying pixels similar to sampled background. */
        'color_tolerance' => (int) env('STUDIO_LAYER_EXTRACTION_COLOR_TOLERANCE', 45),
    ],

    /**
     * Local multi-candidate extraction (FloodfillStudioLayerExtractionProvider).
     * future: swap STUDIO_LAYER_EXTRACTION_PROVIDER=sam or use an inpaint provider for background fill
     * future: STUDIO_LAYER_EXTRACTION_INPAINT_PROVIDER=... for filled-background layers
     */
    'local_floodfill' => [
        'max_candidates' => (int) env('STUDIO_LAYER_EXTRACTION_LOCAL_MAX_CANDIDATES', 6),
        'min_area_ratio' => (float) env('STUDIO_LAYER_EXTRACTION_LOCAL_MIN_AREA_RATIO', 0.01),
        'max_area_ratio' => (float) env('STUDIO_LAYER_EXTRACTION_LOCAL_MAX_AREA_RATIO', 0.85),
        /** Grid size for interior sampling heuristics (reserved / tuning). */
        'sample_grid' => max(2, (int) env('STUDIO_LAYER_EXTRACTION_LOCAL_SAMPLE_GRID', 5)),
        'edge_threshold' => (int) env('STUDIO_LAYER_EXTRACTION_LOCAL_EDGE_THRESHOLD', 12),
        'merge_iou_threshold' => (float) env('STUDIO_LAYER_EXTRACTION_LOCAL_MERGE_IOU', 0.65),
        'enable_multi_candidate' => (bool) env('STUDIO_LAYER_EXTRACTION_LOCAL_MULTI', true),
        /** Refuse very large sources before multi-pass analysis (pixels). */
        'max_analysis_pixels' => (int) env('STUDIO_LAYER_EXTRACTION_LOCAL_MAX_PIXELS', 3_000_000),
        /** Radius around each negative point in segmentation space ≈ ratio × max(segW, segH). */
        'negative_point_radius_ratio' => (float) env('STUDIO_LAYER_EXTRACTION_NEGATIVE_POINT_RADIUS_RATIO', 0.04),
        'max_negative_points' => (int) env('STUDIO_LAYER_EXTRACTION_MAX_NEGATIVE_POINTS', 8),
        /** Max extra “include” clicks when unioning multiple foreground components. */
        'max_positive_refine_points' => (int) env('STUDIO_LAYER_EXTRACTION_MAX_POSITIVE_REFINE_POINTS', 8),
        'refine_enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_REFINE_ENABLED', true),
        /** Box drag on preview (no paid API). */
        'box_pick_enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_BOX_PICK_ENABLED', true),
        'box_fallback_rectangle' => (bool) env('STUDIO_LAYER_EXTRACTION_BOX_FALLBACK_RECTANGLE', true),
        'box_min_size_ratio' => (float) env('STUDIO_LAYER_EXTRACTION_BOX_MIN_SIZE_RATIO', 0.02),
        'box_max_size_ratio' => (float) env('STUDIO_LAYER_EXTRACTION_BOX_MAX_SIZE_RATIO', 0.75),
        /** Text/graphic box mode: high-contrast mask inside the box (local floodfill only). */
        'box_text_graphic_enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_BOX_TEXT_GRAPHIC_ENABLED', true),
        'box_text_threshold' => (float) env('STUDIO_LAYER_EXTRACTION_BOX_TEXT_THRESHOLD', 0.18),
        'box_text_min_area_ratio' => (float) env('STUDIO_LAYER_EXTRACTION_BOX_TEXT_MIN_AREA_RATIO', 0.002),
        'box_text_dilate' => (int) env('STUDIO_LAYER_EXTRACTION_BOX_TEXT_DILATE', 1),
        /**
         * When no text/graphic pixels are found, return a full rectangle in the box (object-style).
         * Default false: return null and a user-visible warning instead.
         */
        'box_text_fallback_rectangle' => (bool) env('STUDIO_LAYER_EXTRACTION_BOX_TEXT_FALLBACK_RECTANGLE', false),
    ],

    /**
     * SAM-style provider (facade; default backend is local flood-fill until a remote API is wired).
     */
    'sam' => [
        'enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_SAM_ENABLED', false),
        'model' => (string) env('STUDIO_LAYER_EXTRACTION_SAM_MODEL', 'segment_anything_v1'),
        /** sam_provider: fal | replicate — which remote HTTP client to use when a key is present. */
        'sam_provider' => (string) env('STUDIO_LAYER_EXTRACTION_SAM_PROVIDER', 'fal'),
        'max_source_mb' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_MAX_SOURCE_MB', 25),
        'timeout' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_TIMEOUT', 120),
        /**
         * Longest edge of the downscaled copy sent to Fal (original kept for final masks).
         * @see \App\Studio\LayerExtraction\Sam\SamLayerExtractionImage::downscaleToMaxLongEdge
         */
        'fal_max_long_edge' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_FAL_MAX_LONG_EDGE', 2048),
        /** If true, requests Fal with sync_mode when supported. */
        'fal_sync_mode' => (bool) env('STUDIO_LAYER_EXTRACTION_SAM_FAL_SYNC_MODE', false),
        /** Queue API polling when POST returns request_id (max wait ≈ polls × interval). */
        'fal_queue_max_polls' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_FAL_QUEUE_MAX_POLLS', 180),
        'fal_queue_poll_interval_ms' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_FAL_QUEUE_POLL_INTERVAL_MS', 1000),
        /** floodfill_shim: delegate to {@see \App\Studio\LayerExtraction\Providers\FloodfillStudioLayerExtractionProvider} with SAM metadata. */
        'backend' => env('STUDIO_LAYER_EXTRACTION_SAM_BACKEND', 'floodfill_shim'),
        'max_input_edge' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_MAX_INPUT_EDGE', 4096),
        'max_input_pixels' => (int) env('STUDIO_LAYER_EXTRACTION_SAM_MAX_INPUT_PIXELS', 16_000_000),
        'refine_enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_SAM_REFINE_ENABLED', true),
        'box_pick_enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_SAM_BOX_PICK_ENABLED', true),
        /** When true, extraction is queued more often (async worker). */
        'prefer_queue' => (bool) env('STUDIO_LAYER_EXTRACTION_SAM_PREFER_QUEUE', true),

        /** App UX / admin: static USD guess when Fal pricing API is off. */
        'estimated_cost_usd' => (string) env('STUDIO_LAYER_EXTRACTION_SAM_ESTIMATED_COST_USD', ''),

        /**
         * When true, {@see \App\Services\Fal\FalModelPricingService} may call Fal pricing (best-effort; fails closed).
         * Off by default so a wrong endpoint does not spam logs in production.
         */
        'pricing_api_enabled' => (bool) env('STUDIO_LAYER_EXTRACTION_SAM_PRICING_API', false),
    ],

    /** Session TTL for abandoned candidate review (hours). */
    'session_ttl_hours' => (int) env('STUDIO_LAYER_EXTRACTION_SESSION_TTL_HOURS', 24),

    /** Queue name for {@see \App\Jobs\StudioExtractLayersJob}. */
    'queue' => env('STUDIO_LAYER_EXTRACTION_QUEUE', 'ai'),

    /**
     * When true, extraction always runs inside {@see \App\Jobs\StudioExtractLayersJob}.
     * When false, small images run synchronously in the HTTP request (faster UX in dev).
     */
    'always_queue' => (bool) env('STUDIO_LAYER_EXTRACTION_ALWAYS_QUEUE', false),

    /** If width×height exceeds this, extraction is always queued. */
    'async_pixel_threshold' => (int) env('STUDIO_LAYER_EXTRACTION_ASYNC_PIXEL_THRESHOLD', 2_500_000),
];
