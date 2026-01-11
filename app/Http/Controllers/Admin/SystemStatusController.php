<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\ActivityEvent;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
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
        
        // Get latest AI insight
        $latestInsight = $this->getLatestAIInsight();

        return Inertia::render('Admin/SystemStatus', [
            'systemHealth' => $systemHealth,
            'recentFailedJobs' => $recentFailedJobs,
            'assetsWithIssues' => $assetsWithIssues,
            'latestAIInsight' => $latestInsight,
        ]);
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
     * @return array
     */
    protected function getQueueHealth(): array
    {
        try {
            $pendingCount = DB::table('jobs')->count();
            $failedCount = DB::table('failed_jobs')->count();
            
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
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get queue health', ['error' => $e->getMessage()]);
            return [
                'status' => 'unknown',
                'pending_count' => 0,
                'failed_count' => 0,
                'last_processed_at' => null,
            ];
        }
    }

    /**
     * Get scheduler health metrics.
     * 
     * @return array
     */
    protected function getSchedulerHealth(): array
    {
        try {
            $lastHeartbeat = Cache::get('laravel_scheduler_last_heartbeat');
            
            if (!$lastHeartbeat) {
                return [
                    'status' => 'not_running',
                    'last_heartbeat' => null,
                    'message' => 'Scheduler has not sent a heartbeat.',
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
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get scheduler health', ['error' => $e->getMessage()]);
            return [
                'status' => 'unknown',
                'last_heartbeat' => null,
                'message' => 'Failed to check scheduler health',
            ];
        }
    }

    /**
     * Get storage (S3) health metrics.
     * 
     * @return array
     */
    protected function getStorageHealth(): array
    {
        try {
            $s3Client = $this->getS3Client();
            $bucketName = config('filesystems.disks.s3.bucket');
            
            if (!$bucketName) {
                return [
                    'status' => 'unhealthy',
                    'bucket' => null,
                    'message' => 'S3 bucket not configured',
                ];
            }
            
            // Perform lightweight connectivity check
            $s3Client->listObjectsV2([
                'Bucket' => $bucketName,
                'MaxKeys' => 1,
                'Prefix' => 'health-check-' . uniqid(),
            ]);
            
            return [
                'status' => 'healthy',
                'bucket' => $bucketName,
                'message' => 'S3 connection successful',
            ];
        } catch (S3Exception $e) {
            Log::error('S3 connectivity check failed', ['error' => $e->getMessage()]);
            return [
                'status' => 'unhealthy',
                'bucket' => config('filesystems.disks.s3.bucket'),
                'message' => 'S3 connection failed: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get storage health', ['error' => $e->getMessage()]);
            return [
                'status' => 'unknown',
                'bucket' => config('filesystems.disks.s3.bucket'),
                'message' => 'Failed to check storage health',
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
}
