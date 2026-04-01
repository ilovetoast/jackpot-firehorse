<?php

namespace App\Listeners;

use App\Models\AssetVersion;
use App\Services\SystemIncidentService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * Capture queue failures for asset-processing jobs.
 *
 * Records system_incident when GenerateThumbnailsJob, ProcessAssetJob, ExtractMetadataJob, or ExtractEmbeddedMetadataJob fails.
 * Ensures MaxAttemptsExceededException and timeout failures are captured.
 *
 * Deduplication: one open incident per asset + job class (signature), refreshed on repeat failures.
 */
class QueueFailureListener
{
    protected const TRACKED_JOBS = [
        'GenerateThumbnailsJob',
        'ProcessAssetJob',
        'ExtractMetadataJob',
        'ExtractEmbeddedMetadataJob',
    ];

    public function handle(JobFailed $event): void
    {
        $jobName = $this->getJobName($event);
        if (!$jobName || !$this->isTrackedJob($jobName)) {
            return;
        }

        try {
            $payload = $event->job->payload();
            $command = $payload['data']['command'] ?? null;
            $assetId = $this->extractAssetId($command, $jobName);

            $jobId = null;
            try {
                $jobId = $event->job->getJobId();
            } catch (\Throwable) {
                $jobId = $payload['uuid'] ?? null;
            }

            $meta = [
                'exception_class' => $event->exception ? get_class($event->exception) : null,
                'exception_message' => $event->exception?->getMessage(),
                'attempts' => $payload['attempts'] ?? null,
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'laravel_job_id' => $jobId,
            ];

            $reportPayload = [
                'source_type' => 'job',
                'source_id' => $assetId,
                'tenant_id' => null,
                'severity' => 'error',
                'title' => "Job failed: {$jobName}",
                'message' => $event->exception?->getMessage(),
                'retryable' => true,
                'metadata' => $meta,
            ];

            if ($assetId !== null && $assetId !== '') {
                $reportPayload['unique_signature'] = 'queue_job_failure:'.$jobName.':'.$assetId;
            }

            app(SystemIncidentService::class)->recordOrRefreshBySignature($reportPayload);
        } catch (\Throwable $e) {
            Log::error('[QueueFailureListener] Failed to record incident', [
                'error' => $e->getMessage(),
                'job' => $jobName ?? 'unknown',
            ]);
        }
    }

    protected function getJobName(JobFailed $event): ?string
    {
        $payload = $event->job->payload();
        $displayName = $payload['displayName'] ?? null;
        if ($displayName) {
            return class_basename($displayName);
        }
        $command = $payload['data']['command'] ?? null;
        if ($command && is_string($command)) {
            $unserialized = @unserialize($command);
            return $unserialized ? class_basename(get_class($unserialized)) : null;
        }
        return null;
    }

    protected function isTrackedJob(string $jobName): bool
    {
        return in_array($jobName, self::TRACKED_JOBS, true);
    }

    protected function extractAssetId(?string $command, string $jobName): ?string
    {
        if (! $command) {
            return null;
        }
        try {
            $unserialized = @unserialize($command);
            if (! $unserialized || ! is_object($unserialized)) {
                return null;
            }
            if (property_exists($unserialized, 'assetId')) {
                $rawId = (string) $unserialized->assetId;
                // ProcessAssetJob accepts version UUID or asset UUID; incidents must key on asset id.
                if ($jobName === 'ProcessAssetJob') {
                    $version = AssetVersion::query()->find($rawId);

                    return $version ? (string) $version->asset_id : $rawId;
                }

                return $rawId;
            }
            if (property_exists($unserialized, 'assetVersionId')) {
                $version = AssetVersion::query()->find($unserialized->assetVersionId);

                return $version ? (string) $version->asset_id : null;
            }
        } catch (\Throwable) {
            // Ignore
        }

        return null;
    }
}
