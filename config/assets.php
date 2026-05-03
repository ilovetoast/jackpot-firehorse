<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Asset Deletion Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for asset deletion lifecycle and grace period.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Grace Period
    |--------------------------------------------------------------------------
    |
    | Number of days to wait before permanently deleting soft-deleted assets.
    | During this period, assets can be restored.
    | After this period, assets are permanently deleted via async job.
    |
    */

    'deletion_grace_period_days' => env('ASSET_DELETION_GRACE_PERIOD_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Delivery (Local Presign)
    |--------------------------------------------------------------------------
    |
    | In local environment, thumbnail URLs use S3 temporaryUrl (presigned) so
    | they load without CORS. TTL in seconds (default 900 = 15 min).
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Thumbnail profiling (local / staging diagnostics)
    |--------------------------------------------------------------------------
    |
    | When ASSET_THUMBNAIL_PROFILING=true, emits [thumbnail_profiling] structured logs and
    | optionally persists a summary on asset version metadata (thumbnail_profiling key).
    */
    'thumbnail_profiling' => [
        'enabled' => (bool) env('ASSET_THUMBNAIL_PROFILING', false),
        'store_in_version_metadata' => (bool) env('ASSET_THUMBNAIL_PROFILING_STORE_METADATA', true),
    ],

    'delivery' => [
        'local_presign_ttl' => (int) env('ASSET_DELIVERY_LOCAL_PRESIGN_TTL', 900),
        // Placeholder URL when VIDEO_PREVIEW or PDF_PAGE variant has no file (1x1 transparent PNG data URL)
        'placeholder_url' => env('ASSET_PLACEHOLDER_URL', 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Calculation
    |--------------------------------------------------------------------------
    |
    | Configuration for how storage is calculated for plan limits.
    | Soft-deleted assets are excluded from storage calculations.
    |
    */

    'storage' => [
        /*
        | Include soft-deleted assets in storage calculations.
        | Set to false to exclude soft-deleted assets (default behavior).
        */
        'include_soft_deleted' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload batch size (preflight, validate, initiate-batch)
    |--------------------------------------------------------------------------
    |
    | Maximum file rows per HTTP preflight / initiate-batch request and per in-modal
    | queue. Must match validation in UploadController. Env allows ops tuning without code.
    |
    */
    'upload_max_files_per_batch' => max(1, min(10000, (int) env('UPLOAD_MAX_FILES_PER_BATCH', 500))),

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Styles
    |--------------------------------------------------------------------------
    |
    | Canonical thumbnail style definitions for asset preview generation.
    | These styles are used consistently across the application for:
    |   - Grid thumbnails (thumb)
    |   - Drawer previews (medium)
    |   - High-resolution previews (large)
    |
    | All thumbnails are generated atomically per asset via GenerateThumbnailsJob.
    | Thumbnails are stored in S3 alongside the original asset.
    |
    */

    'thumbnail_styles' => [
        /*
         * Step 6: Low-quality preview thumbnail (LQIP).
         * Extremely small, heavily blurred preview shown immediately while final thumbnails process.
         * Generated early in pipeline to provide instant visual feedback.
         *
         * Rules:
         * - Size: ~32x32 (extremely small for fast generation and transfer)
         * - Format: same as final (jpg/webp)
         * - Heavy blur applied during generation
         * - Preview and final URLs are ALWAYS distinct (no cache confusion)
         * - Preview existence does NOT mark COMPLETED (final controls completion)
         */
        'preview' => [
            'width' => 32,
            'height' => 32,
            'quality' => 60, // Lower quality for smaller file size
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
            'blur' => true, // Apply heavy blur for LQIP effect
        ],

        /*
         * Small grid thumbnail.
         * Used for asset grid views and list previews.
         */
        'thumb' => [
            'width' => 320,
            'height' => 320,
            'quality' => 85,
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
        ],

        /*
         * Medium drawer preview.
         * Used for asset detail drawers, modal previews, and public page logos.
         * preserve_transparency ensures logos display without gray background block.
         */
        'medium' => [
            'width' => 1024,
            'height' => 1024,
            'quality' => 90,
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
            'preserve_transparency' => true,
        ],

        /*
         * Large high-resolution preview.
         * Used for full-screen previews and high-quality displays.
         * Maximum dimension capped at 4096px to prevent excessive processing.
         */
        'large' => [
            'width' => 4096,
            'height' => 4096,
            'quality' => 95,
            'fit' => 'contain', // maintain aspect ratio, fit within dimensions
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for manual thumbnail retry feature.
    | Allows users to retry thumbnail generation from the asset drawer UI.
    |
    | IMPORTANT: This feature respects the locked thumbnail pipeline:
    | - Does not modify existing GenerateThumbnailsJob
    | - Does not mutate Asset.status
    | - Retry attempts are tracked for audit purposes
    |
    */

    'thumbnail' => [
        /*
         * Output format for generated thumbnails: 'webp' or 'jpeg'
         *
         * WebP offers significantly better compression (25-35% smaller files)
         * while maintaining similar quality to JPEG. Modern browser support
         * is excellent (Chrome, Firefox, Safari, Edge all support WebP).
         *
         * Recommendation: Use 'webp' for better performance and bandwidth savings.
         * Fallback: Use 'jpeg' if you need maximum compatibility with older browsers.
         */
        'output_format' => env('THUMBNAIL_OUTPUT_FORMAT', 'webp'), // 'webp' or 'jpeg'

        /*
         * Maximum number of manual retry attempts per asset.
         * Once this limit is reached, users cannot retry again for that asset.
         * This prevents abuse and infinite retry loops.
         */
        'max_retries' => env('THUMBNAIL_MAX_RETRIES', 3),

        /*
         * Job timeout in seconds (normal assets). GenerateThumbnailsJob sets $this->timeout
         * dynamically; large assets use large_asset_timeout_seconds.
         * Rule: worker_timeout_seconds >= max(job_timeout_seconds, large_asset_timeout_seconds).
         */
        'job_timeout_seconds' => (int) env('THUMBNAIL_JOB_TIMEOUT_SECONDS', 900),

        /*
         * Worker timeout in seconds. Horizon/supervisor must use this so jobs are not killed early.
         * ThumbnailTimeoutGuard derives stuck threshold from this (worker_timeout/60 + buffer).
         */
        'worker_timeout_seconds' => (int) env('QUEUE_WORKER_TIMEOUT', 900),

        /*
         * Max pixel area (width × height). Assets exceeding this are SKIPPED (soft fail),
         * not processed — prevents OOM and runaway memory.
         */
        'max_pixels' => (int) env('THUMBNAIL_MAX_PIXELS', 100_000_000),

        /*
         * Pixel area above which an asset is treated as "large" and gets large_asset_timeout_seconds.
         * 30M covers ~8K-ish TIFFs; below this, job_timeout_seconds applies.
         */
        'large_asset_threshold_pixels' => (int) env('THUMBNAIL_LARGE_THRESHOLD_PIXELS', 30_000_000),

        /*
         * Job timeout in seconds for large assets (pixel count > large_asset_threshold_pixels).
         * Rule: worker_timeout_seconds >= this value.
         */
        'large_asset_timeout_seconds' => (int) env('THUMBNAIL_LARGE_TIMEOUT_SECONDS', 1800),

        /*
         * PSD/PSB: {@see GenerateThumbnailsJob} raises its timeout to at least this so flatten is not
         * killed mid-run. Keep <= HORIZON_IMAGES_PSD_WORKER_TIMEOUT when using the images-psd queue.
         */
        'psd_timeout_seconds' => (int) env('THUMBNAIL_PSD_TIMEOUT_SECONDS', 7200),

        /*
         * When true, OOM / ImageMagick resource-limit errors end in one step: asset SKIPPED with
         * thumbnail_skip_reason=server_resource_limit, job returns successfully (no queue retries).
         * Low-RAM servers: also lower THUMBNAIL_MAX_SOURCE_BYTES and tune system ImageMagick policy.xml.
         */
        'resource_exhaustion_terminal' => filter_var(
            env('THUMBNAIL_RESOURCE_EXHAUSTION_TERMINAL', true),
            FILTER_VALIDATE_BOOL
        ),

        /*
         * Max source file size (bytes) for thumbnail/preview rasterization. When set > 0, larger
         * files skip thumbnail generation gracefully (SKIPPED) so workers do not OOM or retry forever.
         * Production: raise if workers have more RAM (e.g. 5368709120 = 5GB). 0 = no limit.
         */
        'max_source_bytes' => (int) env('THUMBNAIL_MAX_SOURCE_BYTES', 524_288_000),

        'svg_timeout_minutes' => env('THUMBNAIL_SVG_TIMEOUT_MINUTES', 12),

        /*
         * PDF thumbnail generation limits and safety guards.
         * These limits prevent resource exhaustion from large or malformed PDFs.
         */
        'pdf' => [
            /*
             * Maximum PDF file size in bytes (default: 150MB).
             * PDFs larger than this will be rejected for thumbnail generation.
             * This prevents memory exhaustion and processing timeouts.
             * Can be overridden via THUMBNAIL_PDF_MAX_SIZE_BYTES environment variable.
             */
            'max_size_bytes' => env('THUMBNAIL_PDF_MAX_SIZE_BYTES', 150 * 1024 * 1024), // 150MB

            /*
             * Maximum page number to process (default: 1).
             * Only the first page is used for thumbnail generation.
             * This is enforced to prevent processing multi-page PDFs.
             */
            'max_page' => env('THUMBNAIL_PDF_MAX_PAGE', 1),

            /*
             * Timeout for PDF processing in seconds (default: 60).
             * If PDF thumbnail generation takes longer than this, it will fail.
             * This prevents stuck jobs from large or complex PDFs.
             */
            'timeout_seconds' => env('THUMBNAIL_PDF_TIMEOUT_SECONDS', 60),

            /*
             * Maximum number of pages for automatic full extraction.
             * Large PDFs above this threshold are rendered on-demand only.
             */
            'auto_extract_max_pages' => (int) env('THUMBNAIL_PDF_AUTO_EXTRACT_MAX_PAGES', 150),

            /*
             * Rasterization DPI for rendered PDF pages (on-demand + full extraction).
             */
            'render_dpi' => (int) env('THUMBNAIL_PDF_RENDER_DPI', 220),

            /*
             * Faster on-demand page rendering for the in-app viewer.
             * Lower DPI and max size so page 2, 3, ... finish quickly.
             * Override via THUMBNAIL_PDF_VIEWER_DPI and THUMBNAIL_PDF_VIEWER_MAX_SIZE.
             */
            'viewer_dpi' => (int) env('THUMBNAIL_PDF_VIEWER_DPI', 150),
            'viewer_max_size' => (int) env('THUMBNAIL_PDF_VIEWER_MAX_SIZE', 1600),
        ],

        /*
         * Canon CR2 / camera RAW thumbnails (Imagick + LibRaw delegate).
         *
         * Full RAW demosaic without proper WB/colorspace often shows green/magenta casts.
         * We prefer embedded preview JPEG when multiple layers exist, apply delegate hints
         * (camera WB, thumbnail-only), then force sRGB before resize.
         */
        'cr2' => [
            'prefer_smallest_sensible_layer' => env('THUMBNAIL_CR2_PREFER_SMALLEST_LAYER', true),
            'layer_min_area_pixels' => (int) env('THUMBNAIL_CR2_LAYER_MIN_AREA', 40 * 40),
            /*
             * Prefer subimages that decode as JPEG (embedded preview). Full RAW demosaic layers often
             * show green/magenta on some ImageMagick/LibRaw builds; staging IM versions differ from dev.
             */
            'prefer_embedded_jpeg_layer' => env('THUMBNAIL_CR2_PREFER_EMBEDDED_JPEG', true),
            /*
             * Skip choosing a subimage larger than this (pixels) when a smaller layer exists — avoids
             * full-resolution Bayer decode for thumbnails when an embedded preview is available.
             */
            'max_raw_decode_pixels' => (int) env('THUMBNAIL_CR2_MAX_RAW_DECODE_PIXELS', 25_000_000),
            /*
             * LibRaw: auto-wb + camera-wb together can disagree on some delegates; default auto-wb off.
             */
            'raw_auto_white_balance' => env('THUMBNAIL_CR2_RAW_AUTO_WB', false),
        ],

        /*
         * TIFF (print, spot/PMS, CMYK, transparency) — ImageMagick can otherwise produce flat white
         * or “washed” WebP thumbnails while the full original opens correctly in design tools.
         * Flatten layers, map to sRGB, and premultiply alpha onto white before resize/WebP.
         */
        'tiff' => [
            'normalize_for_web' => env('THUMBNAIL_TIFF_NORMALIZE_FOR_WEB', true),
            /* When [0] decodes to a flat white composite (common with PMS/spot + transparency), try merged stack + extra IFDs. */
            'max_subimage_scan' => 6,
        ],

        /*
         * Async "preferred" thumbnails (smart-cropped margins) — generated after original completes.
         * See GeneratePreferredThumbnailJob and ThumbnailSmartCropService.
         *
         * Default false: optional second pass; set THUMBNAIL_PREFERRED_ENABLED=true to re-enable when
         * workers can absorb the extra queue load.
         */
        'preferred' => [
            'enabled' => env('THUMBNAIL_PREFERRED_ENABLED', false),
            /*
             * When smart crop was applied, reject preferred thumbnails below this confidence (0–1).
             * Prevents visibly bad crops; asset keeps original thumbnails only.
             */
            'min_crop_confidence' => (float) env('THUMBNAIL_PREFERRED_MIN_CROP_CONFIDENCE', 0.55),
            /*
             * Styles that must exist in S3 (headObject) for idempotent "complete" — subset of generated finals.
             * Typically thumb + medium; large optional. Intersected with styles actually present in metadata.
             */
            'completion_verify_styles' => ['thumb', 'medium'],
            'smart_crop' => [
                'min_dimension' => (int) env('THUMBNAIL_PREFERRED_MIN_DIMENSION', 400),
                'tight_area_ratio' => (float) env('THUMBNAIL_PREFERRED_TIGHT_AREA_RATIO', 0.95),
                'max_content_area_ratio' => (float) env('THUMBNAIL_PREFERRED_MAX_CONTENT_AREA_RATIO', 0.80),
                'min_content_area_ratio' => (float) env('THUMBNAIL_PREFERRED_MIN_CONTENT_AREA_RATIO', 0.05),
                'padding_fraction' => (float) env('THUMBNAIL_PREFERRED_PADDING_FRACTION', 0.07),
                'fuzz_quantum_fraction' => (float) env('THUMBNAIL_PREFERRED_TRIM_FUZZ', 0.08),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Print-ready layout (preferred thumbnails)
    |--------------------------------------------------------------------------
    |
    | Preferred crop: projection-based content bounds — {@see PrintLayoutCropService}.
    | Edge/corner/bar heuristics below are unused for cropping; kept for optional tooling/tests.
    |
    */
    'print_layout' => [
        'edge_threshold' => (float) env('PRINT_LAYOUT_EDGE_THRESHOLD', 0.15),
        'corner_threshold' => (float) env('PRINT_LAYOUT_CORNER_THRESHOLD', 0.2),
        'min_confidence' => (float) env('PRINT_LAYOUT_MIN_CONFIDENCE', 0.5),
        /* Strip this fraction of min(width,height) on each side before projection (ignore outer printer marks). */
        'margin_ignore_percent' => (float) env('PRINT_LAYOUT_MARGIN_IGNORE_PERCENT', 0.20),
        'analysis_max_side' => (int) env('PRINT_LAYOUT_ANALYSIS_MAX_SIDE', 512),
        'edge_strip_fraction' => (float) env('PRINT_LAYOUT_EDGE_STRIP_FRACTION', 0.075),
        'crop_padding_fraction' => (float) env('PRINT_LAYOUT_CROP_PADDING_FRACTION', 0.04),
        /* Legacy keys; unused by PrintLayoutCropService — kept for env stability. */
        'bbox_threshold_fraction' => (float) env('PRINT_LAYOUT_BBOX_THRESHOLD_FRACTION', 0.92),
        'bbox_morphology_dilate_iterations' => (int) env('PRINT_LAYOUT_BBOX_MORPH_DILATE_ITERATIONS', 2),
        'bbox_max_inner_coverage' => (float) env('PRINT_LAYOUT_BBOX_MAX_INNER_COVERAGE', 0.97),
        'bbox_edge_radius' => (float) env('PRINT_LAYOUT_BBOX_EDGE_RADIUS', 1.0),
        'bbox_edge_threshold_fraction' => (float) env('PRINT_LAYOUT_BBOX_EDGE_THRESHOLD', 0.2),
        'bbox_close_morphology_iterations' => (int) env('PRINT_LAYOUT_BBOX_CLOSE_ITERATIONS', 2),
        'bbox_min_component_area' => (int) env('PRINT_LAYOUT_BBOX_MIN_COMPONENT_AREA', 64),
        /* After normalizing column/row sums to [0,1], keep pixels where value > this × peak (default 0.15). */
        'projection_density_threshold_fraction' => (float) env('PRINT_LAYOUT_PROJECTION_THRESHOLD', 0.15),
        /* Expand detected bounds by this fraction of analysis width/height (3–5%). */
        'projection_expand_fraction' => (float) env('PRINT_LAYOUT_PROJECTION_EXPAND', 0.04),
        'bbox_padding_fraction' => (float) env('PRINT_LAYOUT_BBOX_PADDING_FRACTION', 0.05),
        /* Reject bbox if either dimension exceeds this fraction of full image (likely still includes trim marks). */
        'bbox_strict_max_full_dimension_ratio' => (float) (env('PRINT_LAYOUT_BBOX_STRICT_MAX_DIM')
            ?? env('PRINT_LAYOUT_MAX_CONTENT_BBOX_RATIO', 0.85)),
        /* Reject if mapped bbox width or height is below this fraction of full image (default 40%). */
        'min_content_bbox_dimension_ratio' => (float) env('PRINT_LAYOUT_MIN_CONTENT_BBOX_RATIO', 0.4),
        'min_cropped_dimension_ratio' => (float) env('PRINT_LAYOUT_MIN_CROPPED_RATIO', 0.5),
        'max_aspect_ratio' => (float) env('PRINT_LAYOUT_MAX_ASPECT_RATIO', 6.0),
        'header_ink_fraction' => (float) env('PRINT_LAYOUT_HEADER_INK_FRACTION', 0.08),
        'color_bar_strip_fraction' => (float) env('PRINT_LAYOUT_COLOR_BAR_STRIP_FRACTION', 0.06),
        'crop_luma_cutoff' => (int) env('PRINT_LAYOUT_CROP_LUMA_CUTOFF', 235),

        /*
         * When false, preferred pipeline does not fall back to smart trim if print crop is skipped (debug only).
         */
        'fallback_to_smart_crop' => (bool) env('PRINT_LAYOUT_FALLBACK_SMART_CROP', true),

        /*
         * Temporary diagnostics (local / staging only). Do not enable in production.
         * PRINT_LAYOUT_DEBUG_CROP: writes /tmp/debug-crop*.png with red bbox on full-res source.
         * PRINT_LAYOUT_FORCE_PRINT_CROP: skip validation and always apply crop when a bbox exists.
         */
        'debug_print_crop_overlay' => (bool) env('PRINT_LAYOUT_DEBUG_CROP', false),
        'debug_print_crop_force' => (bool) env('PRINT_LAYOUT_FORCE_PRINT_CROP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset processing pipeline (ProcessAssetJob)
    |--------------------------------------------------------------------------
    |
    | Redis throttle caps how many assets may enter the heavy pipeline (storage
    | inspection, thumbnail chain, etc.) per time window. Bursts of uploads
    | otherwise enqueue hundreds of jobs at once and can overload workers and I/O.
    |
    | When the limit is hit, the job calls release() and is retried after a short delay.
    |
    */
    'processing' => [
        /**
         * When true, log [pipeline_timing] lines for ProcessAssetJob, chain steps, and
         * GenerateThumbnailsJob (per-step ms + ms since processing_started_at).
         */
        'log_step_timings' => (bool) env('ASSET_PIPELINE_LOG_STEP_TIMINGS', false),
        'throttle_enabled' => env('ASSET_PROCESSING_THROTTLE_ENABLED', true),
        'throttle_key' => env('ASSET_PROCESSING_THROTTLE_KEY', 'asset-processing'),
        'throttle_per_tenant' => env('ASSET_PROCESSING_THROTTLE_PER_TENANT', false),
        'throttle_max' => (int) env('ASSET_PROCESSING_THROTTLE_MAX', 5),
        'throttle_decay_seconds' => (int) env('ASSET_PROCESSING_THROTTLE_DECAY', 60),
        'throttle_release_seconds' => (int) env('ASSET_PROCESSING_THROTTLE_RELEASE', 10),

        /*
         * Files at or above this size (bytes) use QUEUE_IMAGES_HEAVY_QUEUE for the processing chain.
         * Tune per environment; heavy workers should use more memory and longer Horizon timeout.
         */
        'heavy_queue_min_bytes' => (int) env('ASSET_PIPELINE_HEAVY_MIN_BYTES', 200 * 1024 * 1024),

        /*
         * Max attempts for ProcessAssetJob / GenerateThumbnailsJob.
         * ProcessAssetJob: each Redis throttle hit calls release() — Laravel counts that as an attempt, so a low
         * number exhausts quickly during upload bursts (MaxAttemptsExceededException). Default 64; raise for very
         * large backlogs or lower throttle_max.
         */
        'pipeline_job_max_tries' => (int) env('ASSET_PIPELINE_JOB_MAX_TRIES', 64),

        /*
         * Stop retrying after this wall-clock window from first dispatch (works with tries; whichever limit hits first).
         * Prevents jobs from sitting in release() loops indefinitely if tries is very high.
         */
        'pipeline_job_retry_until_minutes' => (int) env('ASSET_PIPELINE_JOB_RETRY_UNTIL_MINUTES', 120),

        /*
         * Objects larger than this skip a full S3 download + Imagick probe in FileInspectionService.
         * MIME/size come from HEAD (tenant bucket) or disk size + mimeType (default S3 disk); dimensions stay null.
         * Prevents ProcessAssetJob from timing out before the pipeline chain is dispatched.
         * Set to 0 to always download (debug / smaller environments only).
         */
        'inspect_max_full_download_bytes' => (int) env('ASSET_INSPECT_MAX_FULL_DOWNLOAD_BYTES', 150 * 1024 * 1024),

        /*
         * Job-level timeouts (seconds) for ProcessAssetJob. Should stay at or slightly below the matching
         * Horizon supervisor timeout (HORIZON_IMAGES_WORKER_TIMEOUT / HORIZON_IMAGES_HEAVY_WORKER_TIMEOUT).
         */
        'process_asset_job_timeout_seconds' => (int) env('PROCESS_ASSET_JOB_TIMEOUT_SECONDS', 290),
        'process_asset_job_timeout_heavy_seconds' => (int) env('PROCESS_ASSET_JOB_TIMEOUT_HEAVY_SECONDS', 1780),
        /*
         * ProcessAssetJob on QUEUE_IMAGES_PSD_QUEUE only. Keep <= Horizon supervisor timeout for
         * supervisor-images-psd (HORIZON_IMAGES_PSD_WORKER_TIMEOUT). Large PSD flatten can run long.
         */
        'process_asset_job_timeout_psd_seconds' => (int) env('PROCESS_ASSET_JOB_TIMEOUT_PSD_SECONDS', 7200),

        /*
         * AiMetadataGenerationJob runs on the AI queue in parallel with GenerateThumbnailsJob (images queue).
         * RAW/heavy files can take minutes before metadata contains a medium/preview path; a short wait caused
         * false "skipped:thumbnail_unavailable" while thumbnails were still generating.
         * Keep below Horizon AI worker timeout (HORIZON_AI_WORKER_TIMEOUT, default 960s) minus headroom for the vision API call.
         */
        'ai_metadata_thumbnail_max_wait_seconds' => (int) env('AI_METADATA_THUMBNAIL_MAX_WAIT_SECONDS', 540),
        'ai_metadata_thumbnail_poll_seconds' => (int) env('AI_METADATA_THUMBNAIL_POLL_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quick grid thumbnails (feature-flagged stub)
    |--------------------------------------------------------------------------
    |
    | When enabled, {@see \App\Jobs\ProcessAssetJob} dispatches {@see \App\Jobs\QuickGridThumbnailJob}
    | right after the main pipeline chain is queued. The job is a no-op until real
    | grid-thumb generation + dedupe with {@see \App\Jobs\GenerateThumbnailsJob} is implemented.
    | Set QUEUE_IMAGES_FAST_QUEUE and add a Horizon supervisor before enabling in production.
    |
    */
    'quick_grid_thumbnails' => [
        'enabled' => (bool) env('ASSET_QUICK_GRID_THUMBNAILS', false),
        /** Null/empty falls back to queue.images_fast_queue */
        'queue' => env('ASSET_QUICK_GRID_THUMBNAIL_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video AI (async insights — tags, summary, structured hints)
    |--------------------------------------------------------------------------
    |
    | Triggered after upload via GenerateVideoInsightsJob (non-blocking).
    | Frames are sampled locally with FFmpeg; not stored in S3 unless
    | video.store_frames is enabled (off by default).
    |
    */
    'video_ai' => [
        'enabled' => (bool) env('ASSET_VIDEO_AI_ENABLED', true),
        /**
         * When true, library uploads dispatch async video insights after the asset pipeline completes
         * ({@see \App\Jobs\ProcessAssetJob}). When false, use bulk actions, the asset drawer (failed retry),
         * or staged publish with "Run AI pipeline" checked.
         */
        'auto_run_after_upload' => (bool) env('ASSET_VIDEO_AI_AUTO_RUN_AFTER_UPLOAD', false),
        'max_frames' => (int) env('ASSET_VIDEO_AI_MAX_FRAMES', 20),
        'frame_interval_seconds' => (int) env('ASSET_VIDEO_AI_FRAME_INTERVAL', 3),
        'max_duration_seconds' => (int) env('ASSET_VIDEO_AI_MAX_DURATION', 120),
        /** When true, transcribe audio via OpenAI (Whisper); cheap add-on when key is configured. */
        'transcription_enabled' => (bool) env('ASSET_VIDEO_AI_TRANSCRIPTION', true),
        /** Batch dispatcher: jobs per chunk and pause between chunks (seconds). */
        'batch_size' => (int) env('ASSET_VIDEO_AI_BATCH_SIZE', 5),
        'batch_delay_seconds' => (int) env('ASSET_VIDEO_AI_BATCH_DELAY', 2),
        /**
         * Queue for {@see \App\Jobs\GenerateVideoInsightsJob}. When null, uses {@see config('queue.ai_low_queue')}.
         * The job maps `default` to that AI queue so Horizon workers are not capped at supervisor-default tries
         * (video insights may release() many times while storage paths appear).
         */
        'queue' => env('ASSET_VIDEO_AI_QUEUE', null),
        /** Surface cumulative video AI USD in asset drawer (metadata-backed). Off by default. */
        'show_cost_in_drawer' => (bool) env('ASSET_VIDEO_AI_SHOW_COST_IN_DRAWER', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video-derived files (optional persistence)
    |--------------------------------------------------------------------------
    */
    'video' => [
        /** When true, persist sampled frames under tenant system prefix (excluded from user storage billing). */
        'store_frames' => (bool) env('ASSET_VIDEO_STORE_FRAMES', false),
    ],

];
