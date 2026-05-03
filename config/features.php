<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Streaming Downloads (Phase D-4)
    |--------------------------------------------------------------------------
    |
    | When enabled, downloads exceeding the threshold are streamed directly
    | instead of being built by BuildDownloadZipJob. Avoids disk usage and
    | long-running build jobs for very large downloads.
    |
    | streaming_downloads: Enable streaming path (default false)
    | streaming_threshold_bytes: Min size to use streaming (default 500MB)
    |
    */
    'streaming_downloads' => env('FEATURE_STREAMING_DOWNLOADS', false),
    'streaming_threshold_bytes' => (int) (env('FEATURE_STREAMING_THRESHOLD_BYTES', 500 * 1024 * 1024)),

    /*
    |--------------------------------------------------------------------------
    | Async / durable Add Asset finalize (multi-file batch)
    |--------------------------------------------------------------------------
    |
    | When true: POST /app/assets/upload/finalize should snapshot work and return 202 quickly,
    | with progress driven by a queue job and GET .../uploads/sessions/* endpoints (see roadmap).
    | Default false: current synchronous manifest finalize in UploadController::finalize().
    |
    */
    'async_upload_finalize' => (bool) env('ASYNC_UPLOAD_FINALIZE', false),
];
