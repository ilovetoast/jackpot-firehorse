<?php

use Illuminate\Support\Str;

/**
 * When QUEUE_WORKERS_ENABLED is false, staging/production environments resolve to
 * an empty supervisor list: Horizon stays up but runs zero workers (no jobs processed).
 */
$horizonQueueWorkersEnabled = filter_var(env('QUEUE_WORKERS_ENABLED', true), FILTER_VALIDATE_BOOL);

/*
 * When QUEUE_IMAGES_PSD_QUEUE is empty, PSD/PSB assets use the size-based “heavy” queue only; the
 * images-psd worker then receives no jobs. Set QUEUE_IMAGES_PSD_QUEUE=images-psd to enable isolation.
 */
$imagesPsdQueueName = env('QUEUE_IMAGES_PSD_QUEUE') ?: 'images-psd';

/**
 * Resolve max worker processes per pool from env. Falls back differ by APP_ENV when the env key is unset
 * (staging = conservative; production = higher throughput; local = dev-friendly).
 *
 * If the resolved count is 0, that supervisor is not registered at all — no workers, no RAM, no CPU.
 *
 * Do not run images-psd (multi-GB RAM per worker) or video-heavy (Playwright/FFmpeg) on t3.small / t3.medium
 * or other memory-tight instances; set HORIZON_IMAGES_PSD_PROCESSES=0 and HORIZON_VIDEO_HEAVY_PROCESSES=0
 * unless you have dedicated capacity and have enabled those queues on purpose.
 */
$horizonProcessCount = static function (string $envKey, int $stagingDefault, int $productionDefault, int $localDefault): int {
    $app = env('APP_ENV', 'production');
    $fallback = match ($app) {
        'staging', 'testing' => $stagingDefault,
        'production' => $productionDefault,
        default => $localDefault,
    };

    return max(0, (int) env($envKey, $fallback));
};

$pcDefault = $horizonProcessCount('HORIZON_DEFAULT_PROCESSES', 1, 10, 1);
$pcDownloads = $horizonProcessCount('HORIZON_DOWNLOADS_PROCESSES', 1, 2, 1);
$pcImages = $horizonProcessCount('HORIZON_IMAGES_PROCESSES', 1, 4, 2);
$pcImagesHeavy = $horizonProcessCount('HORIZON_IMAGES_HEAVY_PROCESSES', 0, 2, 1);
$pcImagesPsd = $horizonProcessCount('HORIZON_IMAGES_PSD_PROCESSES', 0, 1, 1);
$pcPdf = $horizonProcessCount('HORIZON_PDF_PROCESSES', 0, 2, 1);
$pcAi = $horizonProcessCount('HORIZON_AI_PROCESSES', 0, 2, 1);
$pcVideoLight = $horizonProcessCount('HORIZON_VIDEO_LIGHT_PROCESSES', 0, 3, 1);
$pcVideoHeavy = $horizonProcessCount('HORIZON_VIDEO_HEAVY_PROCESSES', 0, 2, 1);

/** @return array<string, array<string, mixed>> */
$horizonBuildStagingOrProduction = static function (
    bool $enabled,
    int $pcDefault,
    int $pcDownloads,
    int $pcImages,
    int $pcImagesHeavy,
    int $pcImagesPsd,
    int $pcPdf,
    int $pcAi,
    int $pcVideoLight,
    int $pcVideoHeavy,
    bool $isProduction
): array {
    if (! $enabled) {
        return [];
    }

    $out = [];

    $minP = static fn (int $n) => $n <= 0 ? 0 : min(1, $n);

    if ($pcDefault > 0) {
        $out['supervisor-default'] = array_filter([
            'maxProcesses' => $pcDefault,
            'minProcesses' => $minP($pcDefault),
            'tries' => $isProduction ? null : 3,
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 3 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcDownloads > 0) {
        $out['supervisor-downloads'] = array_filter([
            'maxProcesses' => $pcDownloads,
            'minProcesses' => $minP($pcDownloads),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 3 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcImages > 0) {
        $out['supervisor-images'] = array_filter([
            'maxProcesses' => $pcImages,
            'minProcesses' => $minP($pcImages),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 3 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcImagesHeavy > 0) {
        $out['supervisor-images-heavy'] = array_filter([
            'maxProcesses' => $pcImagesHeavy,
            'minProcesses' => $minP($pcImagesHeavy),
            'timeout' => (int) env('HORIZON_IMAGES_HEAVY_WORKER_TIMEOUT', 1800),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 5 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcImagesPsd > 0) {
        $out['supervisor-images-psd'] = array_filter([
            'maxProcesses' => $pcImagesPsd,
            'minProcesses' => $minP($pcImagesPsd),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 10 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcPdf > 0) {
        $out['supervisor-pdf-processing'] = array_filter([
            'maxProcesses' => $pcPdf,
            'minProcesses' => $minP($pcPdf),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 3 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcAi > 0) {
        $out['supervisor-ai'] = array_filter([
            'maxProcesses' => $pcAi,
            'minProcesses' => $minP($pcAi),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 3 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcVideoLight > 0) {
        $out['supervisor-video-light'] = array_filter([
            'maxProcesses' => $pcVideoLight,
            'minProcesses' => $minP($pcVideoLight),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 3 : null,
        ], static fn ($v) => $v !== null);
    }

    if ($pcVideoHeavy > 0) {
        $out['supervisor-video-heavy'] = array_filter([
            'maxProcesses' => $pcVideoHeavy,
            'minProcesses' => $minP($pcVideoHeavy),
            'balanceMaxShift' => $isProduction ? 1 : null,
            'balanceCooldown' => $isProduction ? 5 : null,
        ], static fn ($v) => $v !== null);
    }

    return $out;
};

$horizonEnvironmentLocal = static function (
    int $pcDefault,
    int $pcDownloads,
    int $pcImages,
    int $pcImagesHeavy,
    int $pcImagesPsd,
    int $pcPdf,
    int $pcAi,
    int $pcVideoLight,
    int $pcVideoHeavy
): array {
    $out = [];

    $minP = static fn (int $n) => $n <= 0 ? 0 : min(1, $n);

    if ($pcDefault > 0) {
        $out['supervisor-default'] = ['maxProcesses' => $pcDefault, 'minProcesses' => $minP($pcDefault)];
    }
    if ($pcDownloads > 0) {
        $out['supervisor-downloads'] = ['maxProcesses' => $pcDownloads, 'minProcesses' => $minP($pcDownloads)];
    }
    if ($pcImages > 0) {
        $out['supervisor-images'] = ['maxProcesses' => $pcImages, 'minProcesses' => $minP($pcImages)];
    }
    if ($pcImagesHeavy > 0) {
        $out['supervisor-images-heavy'] = [
            'maxProcesses' => $pcImagesHeavy,
            'minProcesses' => $minP($pcImagesHeavy),
        ];
    }
    if ($pcImagesPsd > 0) {
        $out['supervisor-images-psd'] = [
            'maxProcesses' => $pcImagesPsd,
            'minProcesses' => $minP($pcImagesPsd),
        ];
    }
    if ($pcPdf > 0) {
        $out['supervisor-pdf-processing'] = ['maxProcesses' => $pcPdf, 'minProcesses' => $minP($pcPdf)];
    }
    if ($pcAi > 0) {
        $out['supervisor-ai'] = ['maxProcesses' => $pcAi, 'minProcesses' => $minP($pcAi)];
    }
    if ($pcVideoLight > 0) {
        $out['supervisor-video-light'] = [
            'maxProcesses' => $pcVideoLight,
            'minProcesses' => $minP($pcVideoLight),
        ];
    }
    if ($pcVideoHeavy > 0) {
        $out['supervisor-video-heavy'] = [
            'maxProcesses' => $pcVideoHeavy,
            'minProcesses' => $minP($pcVideoHeavy),
        ];
    }

    return $out;
};

$horizonStaging = $horizonBuildStagingOrProduction(
    $horizonQueueWorkersEnabled,
    $pcDefault,
    $pcDownloads,
    $pcImages,
    $pcImagesHeavy,
    $pcImagesPsd,
    $pcPdf,
    $pcAi,
    $pcVideoLight,
    $pcVideoHeavy,
    false
);

$horizonProduction = $horizonBuildStagingOrProduction(
    $horizonQueueWorkersEnabled,
    $pcDefault,
    $pcDownloads,
    $pcImages,
    $pcImagesHeavy,
    $pcImagesPsd,
    $pcPdf,
    $pcAi,
    $pcVideoLight,
    $pcVideoHeavy,
    true
);

$horizonLocal = $horizonEnvironmentLocal(
    $pcDefault,
    $pcDownloads,
    $pcImages,
    $pcImagesHeavy,
    $pcImagesPsd,
    $pcPdf,
    $pcAi,
    $pcVideoLight,
    $pcVideoHeavy
);

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
    | affect the paths of any of its internal API that aren't exposed to users.
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
    | These middleware will be attached to each Horizon route, giving you
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
        'redis:images-psd' => 1200,
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

    /* d
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Per-pool process counts: see HORIZON_*_PROCESSES at the top of this file.
    | Supervisors with a process count of 0 are omitted entirely.
    |
    | default:   mail, webhooks, app default queue (HORIZON_DEFAULT_PROCESSES)
    | downloads: BuildDownloadZipJob when QUEUE_DOWNLOADS_QUEUE=downloads (HORIZON_DOWNLOADS_PROCESSES)
    | images:    asset pipeline (ProcessAssetJob, thumbnails, previews, …)
    | Not for t3.small / t3.medium without explicit tuning: images-psd, video-heavy.
    |
    */

    'defaults' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
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
        'supervisor-downloads' => [
            'connection' => 'redis',
            'queue' => ['downloads'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
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
            'tries' => 2,
            'timeout' => (int) env('HORIZON_IMAGES_WORKER_TIMEOUT', 300),
            'nice' => 0,
        ],
        /*
         * Large originals (see ASSET_PIPELINE_HEAVY_MIN_BYTES): same job classes as images queue,
         * but workers need more RAM and a longer kill timeout. Keep HORIZON_IMAGES_HEAVY_PROCESSES low.
         *
         * supervisor-images-psd: only register when HORIZON_IMAGES_PSD_PROCESSES > 0 and
         * QUEUE_IMAGES_PSD_QUEUE is set. Multi-GB RAM; do not enable on t3.small / t3.medium.
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
            'tries' => 2,
            'timeout' => (int) env('HORIZON_IMAGES_HEAVY_WORKER_TIMEOUT', 1800),
            'nice' => 0,
        ],
        'supervisor-images-psd' => [
            'connection' => 'redis',
            'queue' => [$imagesPsdQueueName],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 30,
            'memory' => (int) env('HORIZON_IMAGES_PSD_MEMORY', 8192),
            'tries' => 2,
            'timeout' => (int) env('HORIZON_IMAGES_PSD_WORKER_TIMEOUT', 7200),
            'nice' => 0,
        ],
        'supervisor-pdf-processing' => [
            'connection' => 'redis',
            'queue' => ['pdf-processing'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses' => 1,
            'minProcesses' => 1,
            'maxTime' => 3600,
            'maxJobs' => 100,
            'memory' => 256,
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
        // video-heavy: Playwright/FFmpeg — do not run on t3.small / t3.medium; set HORIZON_VIDEO_HEAVY_PROCESSES=0
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
            'tries' => (int) env('HORIZON_VIDEO_HEAVY_SUPERVISOR_TRIES', 3),
            'timeout' => (int) env('HORIZON_VIDEO_HEAVY_WORKER_TIMEOUT', 12_600),
            'nice' => 0,
        ],
    ],

    'environments' => [
        'production' => $horizonProduction,
        'staging' => $horizonStaging,
        // PHPUnit: same pool selection as staging (conservative); process counts use staging fallbacks in $horizonProcessCount.
        'testing' => $horizonStaging,
        'local' => $horizonLocal,
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
