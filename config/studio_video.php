<?php

return [

    'export_enabled' => (bool) env('STUDIO_VIDEO_EXPORT_ENABLED', true),

    /**
     * Allowed video layer count per composition export (V1: typically 1 base + image overlays).
     */
    'export_max_video_layers' => (int) env('STUDIO_VIDEO_EXPORT_MAX_VIDEO_LAYERS', 3),

    /**
     * FFmpeg binary; must exist on workers running {@see \App\Jobs\ProcessStudioCompositionVideoExportJob}.
     */
    'ffmpeg_binary' => env('STUDIO_VIDEO_FFMPEG_BINARY', 'ffmpeg'),

    /** Probing duration and streams; must exist on workers running video export. */
    'ffprobe_binary' => env('STUDIO_VIDEO_FFPROBE_BINARY', 'ffprobe'),

    /**
     * Canvas runtime export (headless browser frame capture + FFmpeg). When false, jobs with
     * {@see \App\Services\Studio\StudioCompositionVideoExportRenderMode::CANVAS_RUNTIME} fail fast with a clear error.
     */
    'canvas_runtime_export_enabled' => (bool) env('STUDIO_VIDEO_CANVAS_RUNTIME_EXPORT_ENABLED', false),

    /**
     * Dedicated queue for canvas-runtime export. Empty string = reuse {@see config('queue.video_heavy_queue')}.
     * Set e.g. {@code video-heavy-studio-canvas} in production to scale workers separately from legacy FFmpeg-only export.
     */
    'canvas_export_queue' => env('QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE', ''),

    /** TTL (minutes) for signed URLs to the internal export render page (Playwright opens this URL). */
    'canvas_export_render_url_ttl_minutes' => (int) env('STUDIO_VIDEO_CANVAS_EXPORT_RENDER_URL_TTL', 120),

    /** Target FPS for canvas frame capture (must match {@see CompositionRenderPayloadV1} contract). */
    'canvas_export_default_fps' => (int) env('STUDIO_VIDEO_CANVAS_EXPORT_DEFAULT_FPS', 30),

    /**
     * Node binary for {@see scripts/studio-canvas-export.mjs} (Playwright frame capture).
     */
    'canvas_export_node_binary' => env('STUDIO_VIDEO_CANVAS_EXPORT_NODE_BINARY', 'node'),

    /**
     * Path to the capture script (absolute, or relative to {@code base_path()}).
     */
    'canvas_export_playwright_script' => env('STUDIO_VIDEO_CANVAS_EXPORT_PLAYWRIGHT_SCRIPT', 'scripts/studio-canvas-export.mjs'),

    /**
     * Max wall time for the entire Playwright process (navigation + readiness + all frames).
     */
    'canvas_export_capture_timeout_seconds' => (int) env('STUDIO_VIDEO_CANVAS_EXPORT_CAPTURE_TIMEOUT_SECONDS', 7200),

    /** Max wait for {@code __COMPOSITION_EXPORT_BRIDGE__.getState().ready} after navigation. */
    'canvas_export_readiness_timeout_ms' => (int) env('STUDIO_VIDEO_CANVAS_EXPORT_READINESS_TIMEOUT_MS', 120_000),

    /** Max wait for {@code page.goto} (signed URL). */
    'canvas_export_navigation_timeout_ms' => (int) env('STUDIO_VIDEO_CANVAS_EXPORT_NAVIGATION_TIMEOUT_MS', 120_000),

    /** Extra deterministic delay after each {@code setTimeMs} (after double rAF). */
    'canvas_export_frame_settle_ms' => (int) env('STUDIO_VIDEO_CANVAS_EXPORT_FRAME_SETTLE_MS', 50),

    /** Playwright {@code deviceScaleFactor} for screenshots (1 = deterministic CSS pixels). */
    'canvas_export_device_scale_factor' => (float) env('STUDIO_VIDEO_CANVAS_EXPORT_DEVICE_SCALE_FACTOR', 1.0),

    /**
     * FFmpeg merge (canvas_runtime): wall-clock timeout for the merge subprocess (seconds).
     */
    'canvas_runtime_merge_timeout_seconds' => (float) env('STUDIO_VIDEO_CANVAS_RUNTIME_MERGE_TIMEOUT_SECONDS', 3600),

    /** libx264 preset for canvas_runtime merge output. */
    'canvas_runtime_merge_x264_preset' => env('STUDIO_VIDEO_CANVAS_RUNTIME_MERGE_X264_PRESET', 'veryfast'),

    /** libx264 CRF for canvas_runtime merge output (lower = higher quality). */
    'canvas_runtime_merge_x264_crf' => (int) env('STUDIO_VIDEO_CANVAS_RUNTIME_MERGE_X264_CRF', 23),

    /** Output pixel format for canvas_runtime merge (broad playback). */
    'canvas_runtime_merge_pixel_format' => env('STUDIO_VIDEO_CANVAS_RUNTIME_MERGE_PIXEL_FORMAT', 'yuv420p'),

    /**
     * When true, delete captured {@code frame_*.png} files after a successful merge + publish (diagnostics and manifest remain).
     * Default false: retain frames for inspection until retention policy is decided.
     */
    'canvas_runtime_merge_delete_png_frames_after_success' => (bool) env('STUDIO_VIDEO_CANVAS_RUNTIME_MERGE_DELETE_PNG_FRAMES_AFTER_SUCCESS', false),

    /**
     * Short human-readable retention summary copied into {@code meta_json.canvas_runtime_retention.policy_note} on success/failure (ops dashboards).
     */
    'canvas_runtime_retention_policy_note' => (string) env(
        'STUDIO_VIDEO_CANVAS_RUNTIME_RETENTION_POLICY_NOTE',
        'Success: working_dir + manifest snapshot in meta_json; PNG frames optional-delete only after DB row marked complete. Failure: PNGs and working_dir kept for debugging unless ops prune manually.'
    ),

    /**
     * When canvas-runtime Playwright looks like a worker/dependency issue (exit 1, missing playwright, etc.),
     * a row is written to application_error_events (Operations Center → Application errors) if that table exists.
     */
    'worker_infra_event_dedupe_minutes' => (int) env('STUDIO_WORKER_INFRA_EVENT_DEDUPE_MINUTES', 30),

    /**
     * Optional: send a plain-text alert when the above fires. Throttled by worker_infra_alert_mail_minutes per alert code.
     * Leave empty to disable. Use site owner (user id 1) email when worker_infra_alert_use_site_owner_email is true.
     */
    'worker_infra_alert_email' => env('STUDIO_WORKER_INFRA_ALERT_EMAIL', ''),
    'worker_infra_alert_use_site_owner_email' => (bool) env('STUDIO_WORKER_INFRA_ALERT_USE_SITE_OWNER_EMAIL', false),
    'worker_infra_alert_mail_minutes' => (int) env('STUDIO_WORKER_INFRA_ALERT_MAIL_MINUTES', 360),
];
