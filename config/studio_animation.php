<?php

/** Official Kling API: Access Key + Secret Key (JWT). Not fal.ai. */
$klingNativeAccessKey = trim((string) env('KLING_API_KEY', ''));
$klingNativeSecretKey = trim((string) env('KLING_SECRET_KEY', ''));
/** fal.ai queue (only when STUDIO_ANIMATION_KLING_TRANSPORT=fal_queue). */
$falKeyForStudioAnimation = trim((string) env('FAL_KEY', ''));

return [
    'enabled' => (bool) env('STUDIO_ANIMATION_ENABLED', true),

    /**
     * Queue list name for studio animation jobs (process / poll / finalize). Empty = automatic: `ai` outside local
     * (Horizon supervisor-ai); in `local`, the default connection's list (usually `default`) so a plain
     * `php artisan queue:work` picks them up. Set to `ai` if you use a dedicated local worker for the AI queue.
     */
    'dispatch_queue' => env('STUDIO_ANIMATION_QUEUE', ''),

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
    */
    'credits' => [
        'base' => (int) env('STUDIO_ANIMATION_CREDITS_BASE', 40),
        'per_extra_second' => (int) env('STUDIO_ANIMATION_CREDITS_PER_EXTRA_SECOND', 5),
        /** First N seconds are covered by base; beyond that, per_extra_second applies per second. */
        'base_covers_seconds' => (int) env('STUDIO_ANIMATION_BASE_COVERS_SECONDS', 5),
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
            // kling_api = official Kling (JWT, KLING_API_KEY + KLING_SECRET_KEY). fal_queue = fal.ai (FAL_KEY). mock = tests.
            'transport' => env('STUDIO_ANIMATION_KLING_TRANSPORT', 'kling_api'),
            'native' => [
                'base_url' => rtrim((string) env('KLING_API_BASE_URL', 'https://api-singapore.klingai.com'), '/'),
                'access_key' => $klingNativeAccessKey,
                'secret_key' => $klingNativeSecretKey,
                'default_model' => (string) env('KLING_NATIVE_IMAGE2VIDEO_MODEL', 'kling-v2-5-turbo'),
            ],
            'fal' => [
                'api_key' => $falKeyForStudioAnimation,
                'queue_base_url' => rtrim((string) env('FAL_QUEUE_BASE_URL', 'https://queue.fal.run'), '/'),
                'model_path' => env('FAL_KLING_I2V_MODEL', 'fal-ai/kling-video/v3/standard/image-to-video'),
            ],
            'models' => [
                'kling_v3_standard_image_to_video' => [
                    'fal_model_path' => 'fal-ai/kling-video/v3/standard/image-to-video',
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
