<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\ActivityEvent;
use App\Enums\StorageBucketStatus;
use App\Models\Asset;
use App\Models\StorageBucket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schedule;
use Inertia\Inertia;
use Inertia\Response;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;

/**
 * System Status Controller
 * 
 * Admin-only read-only operational dashboard for system observability.
 * Provides visibility into queues, scheduler, storage, and asset processing.
 */
class SystemStatusController extends Controller
{
    /**
     * Display the system status page.
     * 
     * Only accessible to users with system_admin role or user ID 1 (site owner).
     */
    public function index(): Response
    {
        $user = Auth::user();
        
        // Authorization: Only user ID 1 (site owner) or users with site_admin/site_owner role
        $siteRoles = $user->getSiteRoles();
        $isSiteOwner = $user->id === 1;
        $isSiteAdmin = in_array('site_admin', $siteRoles) || in_array('site_owner', $siteRoles);
        
        if (!$isSiteOwner && !$isSiteAdmin) {
            abort(403, 'Only system administrators can access this page.');
        }

        // Gather system health data
        $systemHealth = $this->getSystemHealth();
        $recentFailedJobs = $this->getRecentFailedJobs(10);
        $assetsWithIssues = $this->getAssetsWithIssues();
        $scheduledTasks = $this->getScheduledTasks();
        $queueNextRun = $this->getQueueNextRunTime();

        // Horizon dashboard is served by this app (web server); it reads from the same Redis
        // that the worker server uses. No need to hit the worker server.
        $horizonAvailable = class_exists(\Laravel\Horizon\Horizon::class);
        $horizonPath = $horizonAvailable ? (config('horizon.path', 'horizon')) : null;
        $horizonUrl = $horizonPath ? url($horizonPath) : null;

        // Get latest AI insight
        $latestInsight = $this->getLatestAIInsight();

        // Deployment info from DEPLOYED_AT file (written on each deploy)
        $deployedAt = $this->getDeployedAtInfo();

        return Inertia::render('Admin/SystemStatus', [
            'systemHealth' => $systemHealth,
            'recentFailedJobs' => $recentFailedJobs,
            'assetsWithIssues' => $assetsWithIssues,
            'latestAIInsight' => $latestInsight,
            'scheduledTasks' => $scheduledTasks,
            'queueNextRun' => $queueNextRun,
            'horizonAvailable' => $horizonAvailable,
            'horizonUrl' => $horizonUrl,
            'deployedAt' => $deployedAt,
        ]);
    }

    /**
     * Read deployment info from DEPLOYED_AT file in project root (created on each deploy).
     * Returns parsed key-value array or null if file does not exist.
     *
     * @return array<string, string>|null
     */
    protected function getDeployedAtInfo(): ?array
    {
        $path = base_path('DEPLOYED_AT');

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $lines = explode("\n", trim($content));
        $info = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Split on first ": " to handle values that might contain colons
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $info[$key] = $value;
            }
        }

        return $info === [] ? null : $info;
    }

    /**
     * Get system health metrics.
     * 
     * @return array
     */
    protected function getSystemHealth(): array
    {
        return [
            'queue' => $this->getQueueHealth(),
            'scheduler' => $this->getSchedulerHealth(),
            'storage' => $this->getStorageHealth(),
            'thumbnails' => $this->getThumbnailHealth(),
        ];
    }

    /**
     * Get queue health metrics.
     *
     * When QUEUE_CONNECTION=redis (e.g. Horizon on a separate worker server), pending
     * jobs are read from Redis. Failed jobs remain in the database. When using
     * database driver, both pending and failed come from the DB.
     *
     * @return array
     */
    protected function getQueueHealth(): array
    {
        $driver = config('queue.default');

        try {
            if ($driver === 'redis') {
                return $this->getQueueHealthFromRedis();
            }

            return $this->getQueueHealthFromDatabase();
        } catch (\Exception $e) {
            Log::error('Failed to get queue health', ['error' => $e->getMessage()]);
            return [
                'status' => 'unknown',
                'pending_count' => 0,
                'failed_count' => 0,
                'last_processed_at' => null,
                'queue_driver' => $driver,
            ];
        }
    }

    /**
     * Queue health when using Redis (Horizon). Pending from Redis; failed from DB.
     *
     * @return array
     */
    protected function getQueueHealthFromRedis(): array
    {
        $pendingCount = 0;
        try {
            $defaultQueue = config('queue.connections.redis.queue', 'default');
            $pendingCount += Queue::size($defaultQueue);
            $pendingCount += Queue::size('downloads');
        } catch (\Exception $e) {
            Log::warning('Failed to get Redis queue size', ['error' => $e->getMessage()]);
        }

        $failedCount = (int) DB::table('failed_jobs')->count();

        $status = 'healthy';
        if ($failedCount > 0) {
            $status = 'warning';
        }
        if ($pendingCount > 100) {
            $status = 'warning';
        }
        if ($failedCount > 10) {
            $status = 'unhealthy';
        }

        return [
            'status' => $status,
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
            'last_processed_at' => null,
            'queue_driver' => 'redis',
        ];
    }

    /**
     * Queue health when using database driver.
     *
     * @return array
     */
    protected function getQueueHealthFromDatabase(): array
    {
        $pendingCount = (int) DB::table('jobs')->count();
        $failedCount = (int) DB::table('failed_jobs')->count();

        $lastJob = DB::table('jobs')
            ->orderBy('created_at', 'desc')
            ->first();

        $status = 'healthy';
        if ($failedCount > 0) {
            $status = 'warning';
        }
        if ($pendingCount > 100) {
            $status = 'warning';
        }
        if ($failedCount > 10) {
            $status = 'unhealthy';
        }

        return [
            'status' => $status,
            'pending_count' => $pendingCount,
            'failed_count' => $failedCount,
            'last_processed_at' => $lastJob ? date('c', $lastJob->created_at) : null,
            'queue_driver' => 'database',
        ];
    }

    /**
     * Get scheduler health metrics.
     *
     * Heartbeat is written by the process that runs schedule:run (worker in staging; same machine in local).
     * It is stored in the default cache store (Redis or database). Web and worker share the same cache,
     * so the web server can read the heartbeat with no direct network access to the worker.
     *
     * @return array
     */
    protected function getSchedulerHealth(): array
    {
        try {
            $lastHeartbeat = Cache::get('laravel_scheduler_last_heartbeat');
            
            if (! $lastHeartbeat) {
                $cacheStore = config('cache.default');
                $hint = 'Ensure the worker runs schedule:run and web and worker use the same cache (CACHE_STORE=redis or database).';
                if (app()->environment('staging')) {
                    $hint .= ' On the worker, SCHEDULER_ENABLED must be true or unset.';
                }

                return [
                    'status' => 'not_running',
                    'last_heartbeat' => null,
                    'message' => 'Scheduler has not sent a heartbeat.',
                    'hint' => $hint,
                    'cache_store' => $cacheStore,
                ];
            }
            
            $lastHeartbeatTime = \Carbon\Carbon::parse($lastHeartbeat);
            $minutesSinceHeartbeat = $lastHeartbeatTime->diffInMinutes(now());
            
            $status = 'healthy';
            $message = 'Scheduler is running normally.';
            
            if ($minutesSinceHeartbeat > 5) {
                $status = 'delayed';
                $message = "Scheduler heartbeat is delayed ({$minutesSinceHeartbeat} minutes ago).";
            }
            if ($minutesSinceHeartbeat > 15) {
                $status = 'unhealthy';
                $message = "Scheduler appears to be down ({$minutesSinceHeartbeat} minutes since last heartbeat).";
            }
            
            return [
                'status' => $status,
                'last_heartbeat' => $lastHeartbeatTime->toIso8601String(),
                'message' => $message,
                'cache_store' => config('cache.default'),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get scheduler health', ['error' => $e->getMessage()]);

            return [
                'status' => 'unknown',
                'last_heartbeat' => null,
                'message' => 'Failed to check scheduler health',
                'cache_store' => config('cache.default'),
            ];
        }
    }

    /**
     * Get storage (S3) health metrics.
     *
     * When provision strategy is per_company (staging/production), returns bucket count from DB
     * only — no S3 API calls from web. When shared (local), uses configured bucket and optional connectivity check.
     *
     * @return array
     */
    protected function getStorageHealth(): array
    {
        $strategy = config('storage.provision_strategy', 'shared');
        $env = config('app.env');

        // In staging/production we expect per_company; if still shared, show bucket count from DB and avoid S3 check from web
        if ($strategy !== 'per_company' && in_array($env, ['staging', 'production'], true)) {
            $strategy = 'per_company';
        }

        if ($strategy === 'per_company') {
            try {
                $bucketCount = StorageBucket::where('status', StorageBucketStatus::ACTIVE)->count();

                return [
                    'status' => $bucketCount > 0 ? 'healthy' : 'warning',
                    'bucket' => null,
                    'bucket_count' => $bucketCount,
                    'message' => $bucketCount === 0
                        ? 'No per-tenant buckets registered yet. Run on worker/CLI: php artisan tenants:ensure-buckets'
                        : "{$bucketCount} per-tenant bucket(s) registered",
                    'strategy' => 'per_company',
                ];
            } catch (\Exception $e) {
                Log::error('Failed to get storage health (per_company)', ['error' => $e->getMessage()]);

                return [
                    'status' => 'unknown',
                    'bucket' => null,
                    'bucket_count' => null,
                    'message' => 'Failed to count storage buckets',
                    'strategy' => 'per_company',
                ];
            }
        }

        try {
            $s3Client = $this->getS3Client();
            $bucketName = config('filesystems.disks.s3.bucket');

            if (! $bucketName) {
                return [
                    'status' => 'unhealthy',
                    'bucket' => null,
                    'bucket_count' => null,
                    'message' => 'S3 bucket not configured',
                    'strategy' => 'shared',
                ];
            }

            $s3Client->listObjectsV2([
                'Bucket' => $bucketName,
                'MaxKeys' => 1,
                'Prefix' => 'health-check-' . uniqid(),
            ]);

            return [
                'status' => 'healthy',
                'bucket' => $bucketName,
                'bucket_count' => null,
                'message' => 'S3 connection successful',
                'strategy' => 'shared',
            ];
        } catch (S3Exception $e) {
            Log::error('S3 connectivity check failed', ['error' => $e->getMessage()]);

            return [
                'status' => 'unhealthy',
                'bucket' => config('filesystems.disks.s3.bucket'),
                'bucket_count' => null,
                'message' => 'S3 connection failed: ' . $e->getMessage(),
                'strategy' => 'shared',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get storage health', ['error' => $e->getMessage()]);

            return [
                'status' => 'unknown',
                'bucket' => config('filesystems.disks.s3.bucket'),
                'bucket_count' => null,
                'message' => 'Failed to check storage health',
                'strategy' => 'shared',
            ];
        }
    }

    /**
     * Get thumbnail processing health metrics.
     * 
     * @return array
     */
    protected function getThumbnailHealth(): array
    {
        try {
            // Count assets by thumbnail_status (null counts as 'pending')
            // Use groupByRaw to handle COALESCE in GROUP BY clause for MySQL strict mode
            $counts = Asset::selectRaw('COALESCE(thumbnail_status, \'pending\') as status, COUNT(*) as count')
                ->whereNull('deleted_at')
                ->groupByRaw('COALESCE(thumbnail_status, \'pending\')')
                ->pluck('count', 'status')
                ->toArray();

            $pending = $counts['pending'] ?? 0;
            $processing = $counts['processing'] ?? 0;
            $completed = $counts['completed'] ?? 0;
            $failed = $counts['failed'] ?? 0;

            $status = 'healthy';
            if ($failed > 0) {
                $status = 'unhealthy';
            } elseif ($pending > 0 || $processing > 0) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'pending' => $pending,
                'processing' => $processing,
                'completed' => $completed,
                'failed' => $failed,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get thumbnail health', ['error' => $e->getMessage()]);
            return [
                'status' => 'unknown',
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
            ];
        }
    }

    /**
     * Get recent failed jobs.
     * 
     * @param int $limit
     * @return array
     */
    protected function getRecentFailedJobs(int $limit = 10): array
    {
        try {
            $failedJobs = DB::table('failed_jobs')
                ->orderBy('failed_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($job) {
                    // Extract job name from payload
                    $payload = json_decode($job->payload, true);
                    $jobName = $payload['displayName'] ?? class_basename($payload['job'] ?? 'Unknown');
                    
                    // Extract exception message (truncate if too long)
                    $exception = $job->exception;
                    $exceptionMessage = 'Unknown error';
                    if (preg_match('/^(.+?)(?:\n|$)/', $exception, $matches)) {
                        $exceptionMessage = $matches[1];
                        // Truncate if longer than 200 chars
                        if (strlen($exceptionMessage) > 200) {
                            $exceptionMessage = substr($exceptionMessage, 0, 200) . '...';
                        }
                    }

                    // Handle failed_at - it's a string from the database, convert to ISO8601 format
                    $failedAtString = is_string($job->failed_at) 
                        ? date('c', strtotime($job->failed_at))
                        : ($job->failed_at instanceof \Carbon\Carbon 
                            ? $job->failed_at->toIso8601String() 
                            : date('c'));

                    return [
                        'id' => $job->id,
                        'uuid' => $job->uuid,
                        'job_name' => $jobName,
                        'queue' => $job->queue,
                        'failed_at' => $failedAtString,
                        'exception_message' => $exceptionMessage,
                    ];
                })
                ->toArray();

            return $failedJobs;
        } catch (\Exception $e) {
            Log::error('Failed to get recent failed jobs', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get assets with processing issues.
     * 
     * @param int $limit
     * @return array
     */
    protected function getAssetsWithIssues(int $limit = 50): array
    {
        try {
            $assets = Asset::whereNull('deleted_at')
                ->where(function ($query) {
                    $query->where('thumbnail_status', ThumbnailStatus::FAILED)
                        ->orWhere(function ($q) {
                            $q->whereNotNull('metadata->promotion_failed')
                                ->where('metadata->promotion_failed', true);
                        });
                })
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get(['id', 'title', 'original_filename', 'created_at', 'thumbnail_status', 'thumbnail_error', 'metadata'])
                ->map(function ($asset) {
                    $issues = [];
                    $errorMessages = [];

                    if ($asset->thumbnail_status === ThumbnailStatus::FAILED) {
                        $issues[] = 'thumbnail_generation_failed';
                        if ($asset->thumbnail_error) {
                            $errorMessages[] = "Thumbnail: {$asset->thumbnail_error}";
                        }
                    }

                    if (isset($asset->metadata['promotion_failed']) && $asset->metadata['promotion_failed'] === true) {
                        $issues[] = 'promotion_failed';
                        if (isset($asset->metadata['promotion_error'])) {
                            $errorMessages[] = "Promotion: {$asset->metadata['promotion_error']}";
                        }
                    }

                    return [
                        'id' => $asset->id,
                        'title' => $asset->title ?? $asset->original_filename ?? 'Untitled Asset',
                        'created_at' => $asset->created_at?->toIso8601String(),
                        'issues' => $issues,
                        'error_messages' => $errorMessages,
                    ];
                })
                ->toArray();

            return $assets;
        } catch (\Exception $e) {
            Log::error('Failed to get assets with issues', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get or create S3 client instance.
     * 
     * @return S3Client
     */
    protected function getS3Client(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException('AWS SDK not installed. Install aws/aws-sdk-php.');
        }

        $config = [
            'version' => 'latest',
            'region' => config('storage.default_region', config('filesystems.disks.s3.region', 'us-east-1')),
        ];

        // Add credentials if provided
        if (config('filesystems.disks.s3.key') && config('filesystems.disks.s3.secret')) {
            $config['credentials'] = [
                'key' => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ];
        }

        // Add endpoint for MinIO/local S3
        if (config('filesystems.disks.s3.endpoint')) {
            $config['endpoint'] = config('filesystems.disks.s3.endpoint');
            $config['use_path_style_endpoint'] = config('filesystems.disks.s3.use_path_style_endpoint', false);
        }

        return new S3Client($config);
    }

    /**
     * Get latest AI system reliability insight.
     *
     * @return array|null
     */
    protected function getLatestAIInsight(): ?array
    {
        try {
            $insight = ActivityEvent::where('event_type', EventType::AI_SYSTEM_INSIGHT)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$insight) {
                return null;
            }

            $metadata = $insight->metadata ?? [];

            return [
                'id' => $insight->id,
                'summary' => $metadata['summary'] ?? 'No summary available',
                'severity' => $metadata['severity'] ?? 'medium',
                'recommendations' => $metadata['recommendations'] ?? [],
                'root_causes' => $metadata['root_causes'] ?? [],
                'created_at' => $insight->created_at?->toIso8601String(),
                'agent_run_id' => $metadata['agent_run_id'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get latest AI insight', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get scheduled tasks with their next run times.
     *
     * @return array
     */
    protected function getScheduledTasks(): array
    {
        try {
            $events = Schedule::events();
            $tasks = [];

            foreach ($events as $event) {
                try {
                    $nextRunDate = $event->nextRunDate();
                    $description = $event->description ?? 'No description';
                    
                    // Get the cron expression
                    $expression = $event->expression ?? 'N/A';
                    
                    // Try to extract command name if it's a command
                    $command = null;
                    // Check for mutex name which often contains the command
                    if (method_exists($event, 'mutexName')) {
                        $mutexName = $event->mutexName();
                        // Extract command from mutex name if it follows a pattern
                        if (strpos($mutexName, 'schedule-') === 0) {
                            $command = str_replace('schedule-', '', $mutexName);
                        }
                    }
                    
                    // Try to get command from the event directly
                    if (!$command && method_exists($event, 'buildCommand')) {
                        $fullCommand = $event->buildCommand();
                        // Extract just the command name (before first space or --)
                        if (preg_match('/^([^\s]+)/', $fullCommand, $matches)) {
                            $command = $matches[1];
                        }
                    }

                    $tasks[] = [
                        'description' => $description,
                        'command' => $command,
                        'expression' => $expression,
                        'next_run_at' => $nextRunDate ? $nextRunDate->toIso8601String() : null,
                        'next_run_in' => $nextRunDate ? $this->formatTimeUntil($nextRunDate) : null,
                    ];
                } catch (\Exception $e) {
                    // Skip events that can't be processed
                    Log::warning('Failed to process scheduled event', ['error' => $e->getMessage()]);
                    continue;
                }
            }

            // Sort by next run time
            usort($tasks, function ($a, $b) {
                if (!$a['next_run_at'] && !$b['next_run_at']) return 0;
                if (!$a['next_run_at']) return 1;
                if (!$b['next_run_at']) return -1;
                return strcmp($a['next_run_at'], $b['next_run_at']);
            });

            return $tasks;
        } catch (\Exception $e) {
            Log::error('Failed to get scheduled tasks', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get the next run time for the queue (next job available_at).
     * Only available when using database queue driver; with Redis/Horizon returns null.
     *
     * @return array|null
     */
    protected function getQueueNextRunTime(): ?array
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $nextJob = DB::table('jobs')
                ->where('available_at', '>', now()->timestamp)
                ->orderBy('available_at', 'asc')
                ->first();

            if (! $nextJob) {
                return null;
            }

            $nextRunDate = \Carbon\Carbon::createFromTimestamp($nextJob->available_at);

            $payload = json_decode($nextJob->payload, true);
            $jobName = $payload['displayName'] ?? class_basename($payload['job'] ?? 'Unknown');

            return [
                'job_name' => $jobName,
                'next_run_at' => $nextRunDate->toIso8601String(),
                'next_run_in' => $this->formatTimeUntil($nextRunDate),
                'queue' => $nextJob->queue ?? 'default',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get queue next run time', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Format time until a date in human-readable format.
     *
     * @param \Carbon\Carbon $date
     * @return string
     */
    protected function formatTimeUntil(\Carbon\Carbon $date): string
    {
        $diff = now()->diff($date);
        
        if ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ' . $diff->h . ' hour' . ($diff->h !== 1 ? 's' : '');
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ' . $diff->i . ' minute' . ($diff->i !== 1 ? 's' : '');
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i !== 1 ? 's' : '');
        } else {
            return $diff->s . ' second' . ($diff->s !== 1 ? 's' : '');
        }
    }
}
