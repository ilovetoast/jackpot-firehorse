<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Downloads Queue Name
    |--------------------------------------------------------------------------
    |
    | BuildDownloadZipJob is pushed to this queue. Default 'default' so a
    | single `php artisan queue:work` processes downloads. Set to 'downloads'
    | and run a dedicated worker (e.g. --queue=downloads) for heavy ZIP builds.
    |
    */
    'downloads_queue' => env('QUEUE_DOWNLOADS_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Images / Asset Pipeline Queue Name
    |--------------------------------------------------------------------------
    |
    | ProcessAssetJob, thumbnails, previews, metadata extraction, and related
    | pipeline jobs use this queue. Horizon should run a dedicated supervisor
    | for it (see config/horizon.php) so heavy work does not starve default.
    |
    */
    'images_queue' => env('QUEUE_IMAGES_QUEUE', 'images'),

    /*
    |--------------------------------------------------------------------------
    | Heavy asset pipeline queue (large originals)
    |--------------------------------------------------------------------------
    |
    | ProcessAssetJob routes Bus::chain() here when the source file is at or above
    | assets.processing.heavy_queue_min_bytes. Horizon should run a supervisor with
    | higher memory and timeout than the default images worker (see horizon.php).
    |
    */
    'images_heavy_queue' => env('QUEUE_IMAGES_HEAVY_QUEUE', 'images-heavy'),

    /*
    |--------------------------------------------------------------------------
    | PSD/PSB-only pipeline queue (optional)
    |--------------------------------------------------------------------------
    |
    | When non-empty, ProcessAssetJob and thumbnail regen use this queue for
    | image/vnd.adobe.photoshop and .psd/.psb so huge Photoshop files do not
    | contend with other “heavy” images. Requires a Horizon supervisor (see
    | horizon.php supervisor-images-psd) with high memory and a long timeout.
    | Empty string = disabled (use images vs images-heavy by byte size only).
    |
    */
    'images_psd_queue' => env('QUEUE_IMAGES_PSD_QUEUE', ''),

    /*
    |--------------------------------------------------------------------------
    | Fast images queue (optional — quick grid thumbnail path)
    |--------------------------------------------------------------------------
    |
    | Not wired into Horizon by default. When ASSET_QUICK_GRID_THUMBNAILS is enabled
    | and {@see \App\Jobs\QuickGridThumbnailJob} is implemented, add a supervisor that
    | listens to this queue name so fast thumbs do not sit behind heavy pipeline work.
    |
    */
    'images_fast_queue' => env('QUEUE_IMAGES_FAST_QUEUE', 'images-fast'),

    /*
    |--------------------------------------------------------------------------
    | PDF Processing Queue Name
    |--------------------------------------------------------------------------
    |
    | Dedicated queue for PDF page rendering and full extraction jobs.
    | Keep this isolated from default/download queues to prevent long-running
    | PDF work from delaying regular asset processing.
    |
    */
    'pdf_processing_queue' => env('QUEUE_PDF_PROCESSING_QUEUE', 'pdf-processing'),

    /*
    |--------------------------------------------------------------------------
    | AI queue (video insights, future long-running AI work)
    |--------------------------------------------------------------------------
    |
    | Isolated from the images pipeline so vision + optional transcription
    | do not compete with thumbnail workers. Run Horizon with this queue
    | (see config/horizon.php supervisor-ai).
    |
    */
    'ai_queue' => env('QUEUE_AI_QUEUE', 'ai'),

    /*
    |--------------------------------------------------------------------------
    | Low-priority AI queue (video insights batch fan-out)
    |--------------------------------------------------------------------------
    */
    'ai_low_queue' => env('QUEUE_AI_LOW_QUEUE', 'ai-low'),

    /*
    |--------------------------------------------------------------------------
    | Video queues (light UX vs heavy / export)
    |--------------------------------------------------------------------------
    |
    | Target topology (see docs/environments/PRODUCTION_ARCHITECTURE_AWS.md):
    | - video-light: poster extraction, short previews, fast post-process on returned clips
    | - video-heavy: long / high-RAM work — final composited Studio export, long ffmpeg graphs
    |
    | No jobs are routed here until feature code calls ->onQueue(config('queue.video_light_queue')).
    | Horizon supervisors listen to these names so staging/production workers stay ready.
    |
    */
    'video_light_queue' => env('QUEUE_VIDEO_LIGHT_QUEUE', 'video-light'),

    'video_heavy_queue' => env('QUEUE_VIDEO_HEAVY_QUEUE', 'video-heavy'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
