<?php

namespace App\Services;

use App\Enums\AITaskType;
use App\Enums\EventType;
use App\Enums\ThumbnailStatus;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\AIAgentRun;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * System Reliability Agent Service
 *
 * Analyzes system health and generates AI-powered insights about reliability.
 * This agent:
 * - Analyzes recent failed jobs
 * - Reviews assets with processing issues
 * - Checks scheduler heartbeat health
 * - Examines queue depth and failures
 * - Identifies recent S3-related errors
 * - Generates concise system health summary
 * - Identifies likely root causes
 * - Emits structured recommendations
 *
 * Output:
 * - AI run recorded in ai_agent_runs table
 * - Insight logged to activity_events (type: ai.system_insight)
 * - No external notifications (tickets, emails, webhooks)
 */
class SystemReliabilityAgentService
{
    protected AIService $aiService;

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * Execute the system reliability analysis.
     *
     * @return array Contains agent_run_id, insight_id, summary, severity, recommendations
     */
    public function analyze(): array
    {
        try {
            // Gather system health data
            $healthData = $this->gatherHealthData();

            // Build prompt for AI analysis
            $prompt = $this->buildPrompt($healthData);

            // Execute AI agent
            $result = $this->aiService->executeAgent(
                'system_reliability_agent',
                AITaskType::SYSTEM_RELIABILITY_ANALYSIS,
                $prompt,
                [
                    'triggering_context' => 'system',
                    'environment' => app()->environment(),
                ]
            );

            // Parse AI response
            $insight = $this->parseInsight($result['text'], $healthData);

            // Log insight to activity_events
            $activityEvent = $this->logInsight($insight, $result['agent_run_id']);

            Log::info('System reliability agent executed successfully', [
                'agent_run_id' => $result['agent_run_id'],
                'insight_id' => $activityEvent->id,
                'severity' => $insight['severity'],
            ]);

            return [
                'agent_run_id' => $result['agent_run_id'],
                'insight_id' => $activityEvent->id,
                'summary' => $insight['summary'],
                'severity' => $insight['severity'],
                'recommendations' => $insight['recommendations'],
                'root_causes' => $insight['root_causes'],
                'timestamp' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('System reliability agent execution failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Gather system health data for analysis.
     *
     * @return array
     */
    protected function gatherHealthData(): array
    {
        // Recent failed jobs (last 24 hours, limit 20)
        $recentFailedJobs = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subDay())
            ->orderBy('failed_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($job) {
                $payload = json_decode($job->payload, true);
                $jobName = $payload['displayName'] ?? class_basename($payload['job'] ?? 'Unknown');
                $exceptionMessage = substr($job->exception, 0, 300); // Truncate for prompt

                return [
                    'job_name' => $jobName,
                    'queue' => $job->queue,
                    'failed_at' => $job->failed_at,
                    'exception_preview' => $exceptionMessage,
                ];
            })
            ->toArray();

        // Assets with thumbnail issues
        $assetsWithIssues = Asset::whereNull('deleted_at')
            ->whereIn('thumbnail_status', [
                ThumbnailStatus::PENDING->value,
                ThumbnailStatus::PROCESSING->value,
                ThumbnailStatus::FAILED->value,
            ])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get(['id', 'title', 'thumbnail_status', 'thumbnail_error', 'created_at'])
            ->map(function ($asset) {
                return [
                    'id' => $asset->id,
                    'title' => $asset->title ?? $asset->original_filename ?? 'Untitled',
                    'thumbnail_status' => $asset->thumbnail_status?->value ?? 'null',
                    'thumbnail_error' => substr($asset->thumbnail_error ?? '', 0, 200),
                    'created_at' => $asset->created_at?->toIso8601String(),
                ];
            })
            ->toArray();

        // Scheduler heartbeat health
        $lastHeartbeat = Cache::get('laravel_scheduler_last_heartbeat');
        $schedulerHealth = [
            'last_heartbeat' => $lastHeartbeat ? \Carbon\Carbon::parse($lastHeartbeat)->toIso8601String() : null,
            'is_healthy' => $lastHeartbeat ? \Carbon\Carbon::parse($lastHeartbeat)->diffInMinutes(now()) <= 5 : false,
            'minutes_since_heartbeat' => $lastHeartbeat ? \Carbon\Carbon::parse($lastHeartbeat)->diffInMinutes(now()) : null,
        ];

        // Queue depth and recent failures
        $queueHealth = [
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs_24h' => DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subDay())
                ->count(),
            'failed_jobs_total' => DB::table('failed_jobs')->count(),
        ];

        // Recent S3-related errors (from logs - approximate)
        // Note: This is a simplified check - in production, you'd query log aggregators
        $s3Errors = [
            'count_24h' => 0, // Would be populated from log aggregation
            'sample_errors' => [], // Would be populated from logs
        ];

        return [
            'failed_jobs' => $recentFailedJobs,
            'assets_with_issues' => $assetsWithIssues,
            'scheduler_health' => $schedulerHealth,
            'queue_health' => $queueHealth,
            's3_errors' => $s3Errors,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Build prompt for AI analysis.
     *
     * @param array $healthData
     * @return string
     */
    protected function buildPrompt(array $healthData): string
    {
        $failedJobsCount = count($healthData['failed_jobs']);
        $assetsIssuesCount = count($healthData['assets_with_issues']);
        $pendingJobs = $healthData['queue_health']['pending_jobs'];
        $failedJobs24h = $healthData['queue_health']['failed_jobs_24h'];
        $schedulerHealthy = $healthData['scheduler_health']['is_healthy'];

        $prompt = "Analyze the following system health data and provide a concise system reliability assessment.\n\n";
        $prompt .= "SYSTEM HEALTH DATA:\n";
        $prompt .= "- Recent failed jobs (last 24h): {$failedJobsCount}\n";
        $prompt .= "- Assets with processing issues: {$assetsIssuesCount}\n";
        $prompt .= "- Queue depth (pending jobs): {$pendingJobs}\n";
        $prompt .= "- Failed jobs in last 24h: {$failedJobs24h}\n";
        $prompt .= "- Scheduler heartbeat: " . ($schedulerHealthy ? "Healthy" : "Unhealthy") . "\n";

        if ($failedJobsCount > 0) {
            $prompt .= "\nRECENT FAILED JOBS (sample):\n";
            foreach (array_slice($healthData['failed_jobs'], 0, 5) as $job) {
                $prompt .= "- {$job['job_name']} (queue: {$job['queue']}, failed: {$job['failed_at']})\n";
                $prompt .= "  Error: " . substr($job['exception_preview'], 0, 150) . "\n";
            }
        }

        if ($assetsIssuesCount > 0) {
            $prompt .= "\nASSETS WITH ISSUES (sample):\n";
            foreach (array_slice($healthData['assets_with_issues'], 0, 5) as $asset) {
                $prompt .= "- {$asset['title']} (status: {$asset['thumbnail_status']})\n";
                if ($asset['thumbnail_error']) {
                    $prompt .= "  Error: " . substr($asset['thumbnail_error'], 0, 100) . "\n";
                }
            }
        }

        $prompt .= "\nPlease provide:\n";
        $prompt .= "1. A concise system health summary (2-3 sentences)\n";
        $prompt .= "2. Likely root causes (e.g., legacy assets, infrastructure misconfiguration, transient failures)\n";
        $prompt .= "3. Structured recommendations (3-5 actionable items)\n";
        $prompt .= "4. Overall severity assessment: 'low', 'medium', 'high', or 'critical'\n\n";
        $prompt .= "Format your response as JSON with keys: summary, severity, root_causes (array), recommendations (array).";

        return $prompt;
    }

    /**
     * Parse AI response into structured insight.
     *
     * @param string $aiResponse
     * @param array $healthData
     * @return array
     */
    protected function parseInsight(string $aiResponse, array $healthData): array
    {
        // Try to extract JSON from response
        $jsonMatch = null;
        if (preg_match('/\{[^}]+\}/s', $aiResponse, $matches)) {
            $jsonMatch = json_decode($matches[0], true);
        }

        if ($jsonMatch && is_array($jsonMatch)) {
            return [
                'summary' => $jsonMatch['summary'] ?? 'System reliability analysis completed.',
                'severity' => $jsonMatch['severity'] ?? 'medium',
                'root_causes' => $jsonMatch['root_causes'] ?? [],
                'recommendations' => $jsonMatch['recommendations'] ?? [],
                'raw_response' => $aiResponse,
            ];
        }

        // Fallback: parse from text if JSON extraction failed
        return [
            'summary' => substr($aiResponse, 0, 500),
            'severity' => $this->extractSeverity($aiResponse),
            'root_causes' => [],
            'recommendations' => [],
            'raw_response' => $aiResponse,
        ];
    }

    /**
     * Extract severity from text response.
     *
     * @param string $text
     * @return string
     */
    protected function extractSeverity(string $text): string
    {
        $textLower = strtolower($text);
        if (str_contains($textLower, 'critical')) {
            return 'critical';
        }
        if (str_contains($textLower, 'high')) {
            return 'high';
        }
        if (str_contains($textLower, 'low')) {
            return 'low';
        }
        return 'medium';
    }

    /**
     * Log insight to activity_events.
     *
     * @param array $insight
     * @param int $agentRunId
     * @return ActivityEvent
     */
    protected function logInsight(array $insight, int $agentRunId): ActivityEvent
    {
        // System-scoped insight - no tenant_id
        return ActivityRecorder::record(
            tenant: 1, // Use system tenant ID (1 is typically site owner)
            eventType: EventType::AI_SYSTEM_INSIGHT,
            subject: null,
            actor: 'system',
            brand: null,
            metadata: [
                'agent_run_id' => $agentRunId,
                'summary' => $insight['summary'],
                'severity' => $insight['severity'],
                'root_causes' => $insight['root_causes'],
                'recommendations' => $insight['recommendations'],
                'agent_id' => 'system_reliability_agent',
            ]
        );
    }

    /**
     * Get the latest system reliability insight.
     *
     * @return ActivityEvent|null
     */
    public function getLatestInsight(): ?ActivityEvent
    {
        return ActivityEvent::where('event_type', EventType::AI_SYSTEM_INSIGHT)
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
