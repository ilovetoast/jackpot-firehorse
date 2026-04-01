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
         * Max attempts for ProcessAssetJob / GenerateThumbnailsJob (after throttle releases each counts).
         * Keeps reliability timeline from growing without bound when a job will never succeed.
         */
        'pipeline_job_max_tries' => (int) env('ASSET_PIPELINE_JOB_MAX_TRIES', 5),

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
    ],

];
