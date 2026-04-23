<?php

/**
 * Studio composition export rendering (native FFmpeg vs legacy headless browser).
 *
 * @see docs/studio/FFMPEG_NATIVE_EXPORT.md
 */
return [

    /**
     * Default export engine for compositions that need full-scene rendering (text, etc.).
     * - ffmpeg_native: server-side FFmpeg filter graph (no Playwright/Chromium).
     * - browser_canvas: legacy signed URL + Playwright frame capture + FFmpeg merge.
     */
    'driver' => env('STUDIO_RENDERING_DRIVER', 'ffmpeg_native'),

    /**
     * When set, overrides {@see driver} for debugging (values: ffmpeg_native, browser_canvas).
     */
    'force_driver' => env('STUDIO_RENDERING_FORCE_DRIVER', ''),

    /**
     * When true and {@see driver} is ffmpeg_native, compositions that use unsupported V1
     * features (mask layers, non-normal blend on raster/video, gradient fills) may fall
     * back to browser_canvas if canvas runtime export is enabled.
     */
    'browser_fallback_when_unsupported' => (bool) env('STUDIO_RENDERING_BROWSER_FALLBACK_WHEN_UNSUPPORTED', false),

    /** Root for per-export workspaces (subdirs are created per job). */
    'render_workspace_parent' => env('STUDIO_RENDERING_WORKSPACE_PARENT', ''),

    /** Relative to storage_path('app') when render_workspace_parent is empty. */
    'render_workspace_subdir' => env('STUDIO_RENDERING_WORKSPACE_SUBDIR', 'tmp/studio-ffmpeg-native'),

    /** Text PNG cache under storage_path('app'). */
    'text_raster_cache_subdir' => env('STUDIO_RENDERING_TEXT_RASTER_CACHE_SUBDIR', 'cache/studio-text-raster'),

    'ffmpeg_binary' => env('STUDIO_RENDERING_FFMPEG_BINARY', ''),

    'ffprobe_binary' => env('STUDIO_RENDERING_FFPROBE_BINARY', ''),

    'max_canvas_width' => (int) env('STUDIO_RENDERING_MAX_CANVAS_WIDTH', 4096),

    'max_canvas_height' => (int) env('STUDIO_RENDERING_MAX_CANVAS_HEIGHT', 4096),

    /** Hard cap on output duration (seconds) for worker safety. */
    'max_output_duration_seconds' => (float) env('STUDIO_RENDERING_MAX_OUTPUT_DURATION_SECONDS', 7200),

    /**
     * Absolute path to a TTF/OTF used when no font mapping matches (required for text export).
     * Debian/Ubuntu example: /usr/share/fonts/truetype/dejavu/DejaVuSans.ttf
     */
    'default_font_path' => env('STUDIO_RENDERING_DEFAULT_FONT_PATH', ''),

    /**
     * Directory under storage_path('app') for staged tenant font binaries (TTF/OTF only).
     */
    'font_cache_dir' => env('STUDIO_RENDERING_FONT_CACHE_DIR', 'studio/font-cache'),

    /**
     * Comma-separated extensions allowed for native text rendering (no dot).
     */
    'allowed_font_extensions' => env('STUDIO_RENDERING_ALLOWED_FONT_EXTENSIONS', 'ttf,otf'),

    /**
     * Laravel disk names allowed for {@code font.storage_path}+{@code font.disk} without an asset id (V1: local binaries only).
     */
    'font_direct_read_disks' => ['local', 'public'],

    /**
     * Map first token of fontFamily (e.g. "Inter" from "Inter, system-ui") to absolute font file path.
     * JSON object in env is awkward; use config override in a service provider if needed.
     */
    'font_family_map' => [],

    'ffmpeg_subprocess_timeout_seconds' => (float) env('STUDIO_RENDERING_FFMPEG_TIMEOUT_SECONDS', 3600),

    'x264_preset' => env('STUDIO_RENDERING_X264_PRESET', 'veryfast'),

    'x264_crf' => (int) env('STUDIO_RENDERING_X264_CRF', 23),
];
