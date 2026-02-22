<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic Page Rendering Guardrail
    |--------------------------------------------------------------------------
    |
    | Maximum number of pages the system should auto-render in unattended
    | flows. Full extraction beyond this should be user-triggered.
    |
    */
    'max_auto_pages' => (int) env('PDF_MAX_AUTO_PAGES', 25),

    /*
    |--------------------------------------------------------------------------
    | Absolute Page Count Guardrail
    |--------------------------------------------------------------------------
    |
    | PDFs above this threshold are treated as unsupported-large to protect
    | worker capacity and storage cost.
    |
    */
    'max_allowed_pages' => (int) env('PDF_MAX_ALLOWED_PAGES', 500),

    /*
    |--------------------------------------------------------------------------
    | Full Extraction Guardrail (Non-Admin)
    |--------------------------------------------------------------------------
    |
    | Full extraction requires admin override when PDF page count exceeds this
    | threshold.
    |
    */
    'max_full_extract_without_admin' => (int) env('PDF_MAX_FULL_EXTRACT_WITHOUT_ADMIN', 100),

    /*
    |--------------------------------------------------------------------------
    | Rendering Quality / Cost Controls
    |--------------------------------------------------------------------------
    */
    'dpi' => (int) env('PDF_RENDER_DPI', 150),
    'compression_quality' => (int) env('PDF_RENDER_COMPRESSION_QUALITY', 82),
    'large_preview_width' => (int) env('PDF_RENDER_LARGE_PREVIEW_WIDTH', 1600),

    /*
    |--------------------------------------------------------------------------
    | Queue Isolation
    |--------------------------------------------------------------------------
    */
    'queue' => env('QUEUE_PDF_PROCESSING_QUEUE', 'pdf-processing'),

    /*
    |--------------------------------------------------------------------------
    | PDF Page Cache Behavior
    |--------------------------------------------------------------------------
    |
    | Rendered page objects should be immutable and long-lived in cache.
    |
    */
    'cache_control' => env('PDF_PAGE_CACHE_CONTROL', 'public, max-age=31536000, immutable'),
];
