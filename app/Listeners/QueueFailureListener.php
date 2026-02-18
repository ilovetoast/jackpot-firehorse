<?php

namespace App\Listeners;

use App\Services\Reliability\ReliabilityEngine;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * Capture queue failures for asset-processing jobs.
 *
 * Records system_incident when GenerateThumbnailsJob, ProcessAssetJob, or ExtractMetadataJob fails.
 * Ensures MaxAttemptsExceededException and timeout failures are captured.
 */
class QueueFailureListener
{
    protected const TRACKED_JOBS = [
        'GenerateThumbnailsJob',
        'ProcessAssetJob',
        'ExtractMetadataJob',
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
            $assetId = $this->extractAssetId($command, $payload);

            app(ReliabilityEngine::class)->report([
                'source_type' => 'job',
                'source_id' => $assetId,
                'tenant_id' => null, // Could be extracted from asset if needed
                'severity' => 'error',
                'title' => "Job failed: {$jobName}",
                'message' => $event->exception?->getMessage(),
                'retryable' => true,
                'metadata' => [
                    'exception_class' => $event->exception ? get_class($event->exception) : null,
                    'exception_message' => $event->exception?->getMessage(),
                    'attempts' => $payload['attempts'] ?? null,
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                ],
            ]);
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

    protected function extractAssetId(?string $command, array $payload): ?string
    {
        if (!$command) {
            return null;
        }
        try {
            $unserialized = @unserialize($command);
            if ($unserialized && property_exists($unserialized, 'assetId')) {
                return (string) $unserialized->assetId;
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        return null;
    }
}
