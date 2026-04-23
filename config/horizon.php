<?php

use Illuminate\Support\Str;

/**
 * When QUEUE_WORKERS_ENABLED is false, staging/production environments resolve to
 * an empty supervisor list: Horizon stays up but runs zero workers (no jobs processed).
 */
$horizonQueueWorkersEnabled = filter_var(env('QUEUE_WORKERS_ENABLED', true), FILTER_VALIDATE_BOOL);

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Name
    |--------------------------------------------------------------------------
    |
    | This name appears in notifications and in the Horizon UI. Unique names
    | can be useful while running multiple instances of Horizon within an
    | application, allowing you to identify the Horizon you're viewing.
    |
    */

    'name' => env('HORIZON_NAME', env('APP_NAME', 'Laravel')),

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => env('HORIZON_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => env('HORIZON_PATH', 'horizon'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is fired.
    |
    */

    'waits' => [
        'redis:default' => 90,
        'redis:downloads' => 300,
        'redis:images' => 300,
        'redis:images-heavy' => 600,
        'redis:pdf-processing' => 300,
        'redis:ai' => 600,
        'redis:ai-low' => 900,
        'redis:video-light' => 300,
        'redis:video-heavy' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, recent jobs are kept
    | for one hour while all failed jobs are stored for an entire week.
    |
    */

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Silencing a job will instruct Horizon to not place the job in the list
    | of completed jobs within the Horizon dashboard. This setting may be
    | used to fully remove any noisy jobs from the completed jobs list.
    |
    */

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you can configure how many snapshots should be kept to display in
    | the metrics graph. This will get used in combination with Horizon's
    | `horizon:snapshot` schedule to define how long to retain metrics.
    |
    */

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers to terminate unless the --wait option is
    | provided. Fast termination can shorten deployment delay by
    | allowing a new instance of Horizon to start while the last
    | instance will continue to terminate each of its workers.
    |
    */

    'fast_termination' => true,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory the Horizon master
    | supervisor may consume before it is terminated and restarted. For
    | configuring these limits on your workers, see the next section.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may define the queue worker settings used by your application
    | in all environments. These supervisors and settings handle all your
    | queued jobs and will be provisioned by Horizon during deployment.
    |
    | default: light jobs (mail, webhooks, etc.) + optional downloads queue
    | images:   asset pipeline (ProcessAssetJob, thumbnails, previews, …)
    | pdf-processing: PDF page render / extraction (see config/queue.php)
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default', 'downloads'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 2,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 3,
            'timeout' => 120,
            'nice' => 0,
        ],
        'supervisor-images' => [
            'connection' => 'redis',
            'queue' => ['images'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 200,
            'memory' => 256,
            // Must be >= GenerateThumbnailsJob / large-asset pipeline timeouts (see assets.thumbnail.*)
            // Job $tries bounds release() deferrals; $maxExceptions stops crash loops (see heavy jobs).
            // tries=2 lets transient S3/rsvg/ffmpeg hiccups recover without permanently failing an asset.
            'tries' => 2,
            'timeout' => (int) env('HORIZON_IMAGES_WORKER_TIMEOUT', 300),
            'nice' => 0,
        ],
        /*
         * Large originals (see ASSET_PIPELINE_HEAVY_MIN_BYTES): same job classes as images queue,
         * but workers need more RAM and a longer kill timeout. Keep maxProcesses low.
         */
        'supervisor-images-heavy' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_IMAGES_HEAVY_QUEUE', 'images-heavy')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 50,
            'memory' => (int) env('HORIZON_IMAGES_HEAVY_MEMORY', 2048),
            // Heavy originals: one retry for transient OOM / S3 / rsvg failures.
            'tries' => 2,
            'timeout' => (int) env('HORIZON_IMAGES_HEAVY_WORKER_TIMEOUT', 1800),
            'nice' => 0,
        ],
        'supervisor-pdf-processing' => [
            'connection' => 'redis',
            'queue' => ['pdf-processing'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 100,
            'memory' => 256,
            // One retry for transient Ghostscript / S3 failures; heavy jobs can still set $tries locally.
            'tries' => 2,
            'timeout' => 600,
            'nice' => 0,
        ],
        'supervisor-ai' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_AI_QUEUE', 'ai'), env('QUEUE_AI_LOW_QUEUE', 'ai-low')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 100,
            'memory' => (int) env('HORIZON_AI_MEMORY', 1024),
            // {@see \App\Jobs\GenerateVideoInsightsJob}: may release() many times while storage paths appear;
            // worker max attempts must stay >= that deferral budget (job $tries is 32). Override via HORIZON_AI_SUPERVISOR_TRIES.
            'tries' => (int) env('HORIZON_AI_SUPERVISOR_TRIES', 40),
            'timeout' => (int) env('HORIZON_AI_WORKER_TIMEOUT', 960),
            'nice' => 0,
        ],
        'supervisor-video-light' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_VIDEO_LIGHT_QUEUE', 'video-light')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 200,
            'memory' => (int) env('HORIZON_VIDEO_LIGHT_MEMORY', 512),
            'tries' => 2,
            'timeout' => (int) env('HORIZON_VIDEO_LIGHT_WORKER_TIMEOUT', 600),
            'nice' => 0,
        ],
        // Studio canvas-runtime export defaults to the same queue; set QUEUE_VIDEO_HEAVY_STUDIO_CANVAS_QUEUE + a
        // second supervisor to isolate Playwright/Chromium workloads. See docs/studio/CANVAS_RUNTIME_EXPORT.md.
        'supervisor-video-heavy' => [
            'connection' => 'redis',
            'queue' => [env('QUEUE_VIDEO_HEAVY_QUEUE', 'video-heavy')],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 50,
            'memory' => (int) env('HORIZON_VIDEO_HEAVY_MEMORY', 2048),
            // tries=1 caused MaxAttemptsExceeded on the *next* reservation (timeout/OOM/redeploy) before handle()
            // ran again — noisy Sentry + stuck studio export rows. Allow a small retry budget like images-heavy.
            'tries' => (int) env('HORIZON_VIDEO_HEAVY_SUPERVISOR_TRIES', 3),
            // Must be >= studio_video.export_job_timeout_seconds (canvas capture + FFmpeg merge can exceed 1h).
            'timeout' => (int) env('HORIZON_VIDEO_HEAVY_WORKER_TIMEOUT', 12_600),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => $horizonQueueWorkersEnabled ? [
            'supervisor-default' => [
                'maxProcesses' => 10,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-images' => [
                'maxProcesses' => 4,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-images-heavy' => [
                'maxProcesses' => 2,
                'minProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
            'supervisor-pdf-processing' => [
                'maxProcesses' => 2,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-ai' => [
                'maxProcesses' => 2,
                'minProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-video-light' => [
                'maxProcesses' => 3,
                'minProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 3,
            ],
            'supervisor-video-heavy' => [
                'maxProcesses' => 2,
                'minProcesses' => 1,
                'balanceMaxShift' => 1,
                'balanceCooldown' => 5,
            ],
        ] : [],

        'staging' => $horizonQueueWorkersEnabled ? [
            'supervisor-default' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
                'tries' => 3,
            ],
            'supervisor-images' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
            ],
            'supervisor-images-heavy' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
                'timeout' => (int) env('HORIZON_IMAGES_HEAVY_WORKER_TIMEOUT', 1800),
            ],
            'supervisor-pdf-processing' => [
                'maxProcesses' => 1,
            ],
            'supervisor-ai' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
            ],
            'supervisor-video-light' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
            ],
            'supervisor-video-heavy' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
            ],
        ] : [],

        'local' => [
            'supervisor-default' => [
                'maxProcesses' => 1,
            ],
            'supervisor-images' => [
                'maxProcesses' => 1,
            ],
            'supervisor-images-heavy' => [
                'maxProcesses' => 1,
                'minProcesses' => 1,
            ],
            'supervisor-pdf-processing' => [
                'maxProcesses' => 1,
            ],
            'supervisor-ai' => [
                'maxProcesses' => 1,
            ],
            'supervisor-video-light' => [
                'maxProcesses' => 1,
            ],
            'supervisor-video-heavy' => [
                'maxProcesses' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Watcher Configuration
    |--------------------------------------------------------------------------
    |
    | The following list of directories and files will be watched when using
    | the `horizon:listen` command. Whenever any directories or files are
    | changed, Horizon will automatically restart to apply all changes.
    |
    */

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'public/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
