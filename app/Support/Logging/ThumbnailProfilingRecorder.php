<?php

namespace App\Support\Logging;

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Log;

/**
 * Structured timing for {@see \App\Services\ThumbnailGenerationService} and pipeline jobs.
 * Grep: [thumbnail_profiling]
 *
 * When {@see config('assets.thumbnail_profiling.enabled')} is false, all methods no-op quickly.
 */
final class ThumbnailProfilingRecorder
{
    /** @var array<string, mixed>|null */
    protected static ?array $jobContext = null;

    public static function enabled(): bool
    {
        return (bool) config('assets.thumbnail_profiling.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function setJobContext(array $context): void
    {
        if (! self::enabled()) {
            return;
        }
        self::$jobContext = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public static function consumeJobContext(): array
    {
        $c = self::$jobContext ?? [];
        self::$jobContext = null;

        return $c;
    }

    /**
     * Best-effort queue wait from Laravel job payload (Redis/database drivers).
     */
    public static function resolveQueueWaitMs(?QueueJobContract $job): ?int
    {
        if ($job === null || ! method_exists($job, 'payload')) {
            return null;
        }
        try {
            $payload = $job->payload();
            if (! is_array($payload)) {
                return null;
            }
            $pushedAt = $payload['pushedAt'] ?? null;
            if ($pushedAt === null) {
                return null;
            }
            $pushedMs = is_numeric($pushedAt) ? (float) $pushedAt : null;
            if ($pushedMs === null) {
                return null;
            }
            $nowMs = microtime(true) * 1000.0;

            return (int) max(0, $nowMs - $pushedMs);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function log(array $row): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            Log::info('[thumbnail_profiling]', self::sanitizeRow($row));
        } catch (\Throwable) {
        }
    }

    /**
     * Pipeline job boundary (ordering / contention). phase: started|finished|skipped
     *
     * @param  array<string, mixed>  $extra
     */
    public static function logPipelineJob(
        string $jobClass,
        string $assetId,
        ?string $versionId,
        string $phase,
        ?QueueJobContract $job = null,
        array $extra = []
    ): void {
        if (! self::enabled()) {
            return;
        }
        $row = array_merge([
            'kind' => 'pipeline_job',
            'job_class' => $jobClass,
            'asset_id' => $assetId,
            'asset_version_id' => $versionId,
            'phase' => $phase,
            'ts' => now()->toIso8601String(),
            'queue_wait_ms' => self::resolveQueueWaitMs($job),
        ], self::sanitizeRow($extra));

        if ($job !== null) {
            try {
                $row['queue_job_id'] = $job->getJobId();
            } catch (\Throwable) {
            }
            try {
                $row['queue'] = $job->queue ?? null;
            } catch (\Throwable) {
            }
        }

        self::log($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected static function sanitizeRow(array $row): array
    {
        foreach (['path', 'file_path', 'storage_path', 's3_key', 'url', 'signed_url', 'temp_path'] as $k) {
            if (array_key_exists($k, $row)) {
                unset($row[$k]);
            }
        }

        return $row;
    }
}
