<?php

/** Official Kling API: Access Key + Secret Key (JWT). */
$klingNativeAccessKey = trim((string) env('KLING_API_KEY', ''));
$klingNativeSecretKey = trim((string) env('KLING_SECRET_KEY', ''));

return [
    'enabled' => (bool) env('STUDIO_ANIMATION_ENABLED', true),

    /**
     * Queue list name for studio animation jobs (process / poll / finalize). Empty = automatic: `ai` outside local
     * (Horizon supervisor-ai); in `local`, the default connection's list (usually `default`) so a plain
     * `php artisan queue:work` picks them up. Set to `ai` if you use a dedicated local worker for the AI queue.
     */
    'dispatch_queue' => env('STUDIO_ANIMATION_QUEUE', ''),

    /**
     * Non-terminal studio animation jobs (queued, processing, etc.) older than this many minutes (by `created_at`)
     * may be removed from the Versions rail via DELETE. Set to 0 to disable (only failed/canceled jobs removable).
     */
    'stale_rail_removal' => [
        'after_minutes' => (int) env('STUDIO_ANIMATION_STALE_RAIL_REMOVAL_MINUTES', 15),
    ],

    /**
     * Before credits are charged, downloaded provider video is probed with ffprobe (required on the app host).
     * Fails the job (no credit charge) if the file has no decodable video, tiny dimensions, or a near-zero duration.
     */
    'finalize_validation' => [
        'min_size_bytes' => (int) env('STUDIO_ANIMATION_FINALIZE_MIN_BYTES', 256),
        /** Timeline length must exceed this (seconds) using format/stream duration. */
        'min_effective_duration_seconds' => (float) env('STUDIO_ANIMATION_FINALIZE_MIN_DURATION', 0.3),
        'min_width' => (int) env('STUDIO_ANIMATION_FINALIZE_MIN_WIDTH', 16),
        'min_height' => (int) env('STUDIO_ANIMATION_FINALIZE_MIN_HEIGHT', 16),
        /** Non-empty: use this ffprobe binary instead of PATH. */
        'ffprobe_path' => trim((string) env('STUDIO_ANIMATION_FFPROBE_PATH', '')),
    ],

    /**
     * Structured logs: [sa] <event> with compact keys (render_engine, renderer_version, drift_*, webhook, retry, finalize).
     * Disable in extremely noise-sensitive environments.
     */
    'observability' => [
        'enabled' => (bool) env('STUDIO_ANIMATION_OBSERVABILITY_ENABLED', true),
        /**
         * When true, each [sa] log also emits a compact [sa_metric] line (flat, parser-friendly) with rollout dimensions only.
         * Disable if log volume doubles are undesirable.
         */
        'emit_metric_line' => (bool) env('STUDIO_ANIMATION_OBSERVABILITY_METRICS', true),
    ],

    /**
     * When true, GET animation job JSON includes rollout_diagnostics (config flags, output row count, queue name).
     * For manual validation only; keep false in production unless operators need it.
     */
    'diagnostics_api' => [
        'enabled' => (bool) env('STUDIO_ANIMATION_DIAGNOSTICS_API', false),
    ],

    'default_provider' => env('STUDIO_ANIMATION_DEFAULT_PROVIDER', 'kling'),

    'default_motion_preset' => 'cinematic_pan',

    'render_disk' => env('STUDIO_ANIMATION_RENDER_DISK', 'local'),

    'output_disk' => env('STUDIO_ANIMATION_OUTPUT_DISK', 's3'),

    /** Cap JSON stored on job rows for poll excerpts (full payloads may live in logs). */
    'provider_debug_max_json_bytes' => (int) env('STUDIO_ANIMATION_PROVIDER_DEBUG_MAX_JSON_BYTES', 12000),

    'server_locked_frame' => [
        'enabled' => (bool) env('STUDIO_ANIMATION_SERVER_LOCKED_FRAME', true),
        'font_path' => env('STUDIO_ANIMATION_LOCKED_FRAME_FONT'),
    ],

    /**
     * When true (default), the start frame sent to the video provider uses the **browser-captured** PNG
     * (the same pixels the user sees), not a server re-render of locked_document_json. Server re-renders
     * currently resolve image layers from asset storage / current file version, which can disagree with
     * the editor when the user picked a different asset version (e.g. Original vs AI edit) or a
     * signed URL in layer.src. Set to false to restore the previous "prefer server raster when
     * available" behavior.
     */
    'prefer_client_snapshot_for_provider' => (bool) env('STUDIO_ANIMATION_PREFER_CLIENT_SNAPSHOT', true),

    /**
     * When true, run {@see \App\Jobs\GenerateThumbnailsJob} synchronously right after the MP4 is stored, so
     * Staged / library grids get a static poster (video frame) without waiting for the async pipeline worker.
     * If FFmpeg is missing, this no-ops and the queued pipeline still attempts thumbnails later.
     */
    'eager_video_thumbnails' => (bool) env('STUDIO_ANIMATION_EAGER_VIDEO_THUMBNAILS', true),

    /**
     * Optional headless/browser frame export: command_template must include {{DOCUMENT_JSON}} and {{OUTPUT_PNG}} placeholders (shell-escaped paths).
     * Example: node /var/www/scripts/studio-animation-chrome-frame.mjs {{DOCUMENT_JSON}} {{OUTPUT_PNG}}
     */
    'browser_locked_frame' => [
        'enabled' => (bool) env('STUDIO_ANIMATION_BROWSER_FRAME_ENABLED', false),
        'command_template' => (string) env('STUDIO_ANIMATION_BROWSER_FRAME_COMMAND', ''),
        'timeout_seconds' => (int) env('STUDIO_ANIMATION_BROWSER_FRAME_TIMEOUT', 120),
    ],

    /**
     * Drift gate: default warn_only (metadata only). Enable blocking in staging/production only when intentional.
     */
    'drift_gate' => [
        'enabled' => (bool) env('STUDIO_ANIMATION_DRIFT_GATE_ENABLED', false),
        'mode' => (string) env('STUDIO_ANIMATION_DRIFT_GATE_MODE', 'warn_only'), // warn_only | block_high | block_any
        'score_threshold' => (float) env('STUDIO_ANIMATION_DRIFT_GATE_SCORE_THRESHOLD', 0.15),
        /** When true with mode block_high, drift_level medium is also blocked (admin / policy). */
        'strict_drift_block' => (bool) env('STUDIO_ANIMATION_DRIFT_GATE_STRICT', false),
    ],

    /** Thresholds for drift_level (product signal); used by StudioAnimationDriftQualityClassifier. */
    'drift_quality' => [
        'high_score_threshold' => (float) env('STUDIO_ANIMATION_DRIFT_HIGH_SCORE', 0.02),
        'medium_score_threshold' => (float) env('STUDIO_ANIMATION_DRIFT_MEDIUM_SCORE', 0.0),
    ],

    /**
     * First-party Playwright renderer (scripts/studio-animation/playwright-locked-frame.mjs).
     * Install (app root): npm ci && npx playwright install chromium
     * Env: STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_ENABLED, SCRIPT, NODE, TIMEOUT, REQUIRE_HIGH_FI, DISABLE_LEGACY.
     * If disabled or script fails, CompositionSnapshotRenderer falls back to legacy browser command (if enabled),
     * then Imagick server_basic, then client_snapshot (see parity note in docs/internal/studio-animation-rollout.md).
     */
    'official_playwright_renderer' => [
        'enabled' => (bool) env('STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_ENABLED', false),
        /** When true, official path runs only if the job has high_fidelity_submit. */
        'require_high_fidelity_submit' => (bool) env('STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_REQUIRE_HIGH_FI', false),
        /** When true, skip legacy STUDIO_ANIMATION_BROWSER_FRAME_COMMAND path. */
        'disable_legacy_browser_command' => (bool) env('STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_DISABLE_LEGACY', false),
        'script_path' => (string) env('STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_SCRIPT', ''),
        'node_binary' => (string) env('STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_NODE', 'node'),
        'timeout_seconds' => (int) env('STUDIO_ANIMATION_OFFICIAL_PLAYWRIGHT_TIMEOUT', 120),
    ],

    'webhooks' => [
        'ingest_enabled' => (bool) env('STUDIO_ANIMATION_WEBHOOK_INGEST_ENABLED', false),
        'shared_secret' => env('STUDIO_ANIMATION_WEBHOOK_SECRET', ''),
        /** When set, raw body HMAC-SHA256 must match X-Fal-Signature / X-Fal-Webhook-Signature (see StudioAnimationWebhookSignatureVerifier). */
        'fal_signature_secret' => env('STUDIO_ANIMATION_FAL_WEBHOOK_SECRET', ''),
    ],

    'supported_aspect_ratios' => ['16:9', '9:16', '1:1', '4:5', '3:4'],

    'prompt_max_length' => 4000,

    'negative_prompt_max_length' => 2000,

    /*
    | Credits: feature key `studio_animation` uses weight 1 in ai_credits.php; each job consumes
    | N units via AiUsageService::checkUsage($tenant, 'studio_animation', N).
    |
    | Defaults price Studio video **above** lightweight editor calls (e.g. generative stills use 20 credits):
    | ~$1 Kling COGS is covered by a retail credit charge with markup. Tune STUDIO_ANIMATION_CREDITS_*
    | and list_price for your packs.
    */
    'credits' => [
        'base' => (int) env('STUDIO_ANIMATION_CREDITS_BASE', 60),
        'per_extra_second' => (int) env('STUDIO_ANIMATION_CREDITS_PER_EXTRA_SECOND', 12),
        /** First N seconds are covered by base; beyond that, per_extra_second applies per second. */
        'base_covers_seconds' => (int) env('STUDIO_ANIMATION_BASE_COVERS_SECONDS', 5),
        /**
         * For audit / admin display only: implied USD when credits are valued at a list pack price
         * (e.g. $29 / 500 ≈ 0.058). Does not change billing.
         */
        'list_price_usd_per_credit' => (float) env('STUDIO_ANIMATION_LIST_USD_PER_CREDIT', 0.058),
    ],

    /**
     * Recorded on AI agent runs as estimated_cost (USD) for finance / admin dashboards.
     * Kling’s API does not return per-invoice line items here — tune env to match your contract.
     */
    'cost_tracking' => [
        'estimated_usd_per_job' => (float) env('STUDIO_ANIMATION_ESTIMATED_USD_PER_JOB', 1.0),
        /** Vendor COGS for each second beyond base_covers_seconds (length add-on; same window as credits). */
        'estimated_usd_per_extra_second' => (float) env('STUDIO_ANIMATION_ESTIMATED_USD_PER_EXTRA_SECOND', 0.2),
        'disclaimer' => 'All USD figures are internal estimates (config), not Kling invoices. Update STUDIO_ANIMATION_ESTIMATED_USD_* to match your account.',
    ],

    'motion_presets' => [
        'cinematic_pan' => [
            'label' => 'Cinematic pan',
            'description' => 'Slow camera drift with gentle parallax; premium ad feel.',
            'camera_behavior' => 'slow_pan',
            'subject_priority' => 'full_frame',
            'text_safety' => 'preserve_layout',
        ],
        'subtle_alive' => [
            'label' => 'Subtle alive',
            'description' => 'Light ambient motion — fabric, light, particles — without reshaping layout.',
            'camera_behavior' => 'locked',
            'subject_priority' => 'balanced',
            'text_safety' => 'high',
        ],
        'hero_reveal' => [
            'label' => 'Hero reveal',
            'description' => 'Dramatic push-in toward the focal hero with controlled depth.',
            'camera_behavior' => 'push_in',
            'subject_priority' => 'hero',
            'text_safety' => 'standard',
        ],
        'product_orbit' => [
            'label' => 'Product orbit',
            'description' => 'Soft 3D-style orbit around the main product or pack shot.',
            'camera_behavior' => 'orbit',
            'subject_priority' => 'product',
            'text_safety' => 'standard',
        ],
    ],

    'providers' => [
        'kling' => [
            'label' => 'Kling 3.0',
            /** Official Kling API only. Use `mock` in tests via app config override. */
            'transport' => 'kling_api',
            'native' => [
                'base_url' => rtrim((string) env('KLING_API_BASE_URL', 'https://api-singapore.klingai.com'), '/'),
                'access_key' => $klingNativeAccessKey,
                'secret_key' => $klingNativeSecretKey,
                'default_model' => (string) env('KLING_NATIVE_IMAGE2VIDEO_MODEL', 'kling-v2-5-turbo'),
            ],
            'models' => [
                'kling_v3_standard_image_to_video' => [
                    'native_model_name' => env('KLING_NATIVE_MODEL_V3_STANDARD', 'kling-v2-5-turbo'),
                    'label' => 'Kling 3.0 Standard (image-to-video)',
                ],
            ],
            'duration_allowed' => [3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'capabilities' => [
                'supports_end_frame' => false,
                'supports_elements' => false,
                'supports_multi_shot' => false,
                'supports_audio' => true,
                'supports_layer_source' => false,
            ],
        ],
    ],
];
