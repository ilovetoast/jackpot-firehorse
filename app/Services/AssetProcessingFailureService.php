<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Events\AssetProcessingFailed;
use App\Models\Asset;
use App\Models\AssetEvent;
use Illuminate\Support\Facades\Log;

class AssetProcessingFailureService
{
    /**
     * Maximum number of retry attempts allowed.
     * Never retry forever - enforce a hard limit.
     */
    protected const MAX_RETRY_ATTEMPTS = 3;

    /**
     * Record a processing failure centrally.
     *
     * @param Asset $asset
     * @param string $jobClass
     * @param \Throwable $exception
     * @param int $attempts
     * @param bool $preserveVisibility If true, do NOT set status=FAILED (asset stays visible in grid).
     *                                 Use for thumbnail failures - asset remains usable; user can retry or download original.
     *                                 Default false for other failures (ExtractMetadata, Promote, etc.) which may warrant hiding.
     * @return void
     */
    public function recordFailure(
        Asset $asset,
        string $jobClass,
        \Throwable $exception,
        int $attempts,
        bool $preserveVisibility = false
    ): void {
        // Determine if failure is retryable
        $isRetryable = $this->isRetryableFailure($exception, $attempts);

        // Generate human-readable failure reason
        $failureReason = $this->generateFailureReason($exception, $jobClass);

        // Mark asset as failed - UNLESS preserveVisibility (thumbnail failures must never hide the asset)
        if (! $preserveVisibility) {
            $asset->update([
                'status' => AssetStatus::FAILED,
            ]);
        }

        // Update asset metadata with failure information
        $currentMetadata = $asset->metadata ?? [];
        $currentMetadata['processing_failed'] = true;
        $currentMetadata['failure_reason'] = $failureReason;
        $currentMetadata['failed_job'] = $jobClass;
        $currentMetadata['failure_attempts'] = $attempts;
        $currentMetadata['failure_is_retryable'] = $isRetryable;
        $currentMetadata['failed_at'] = now()->toIso8601String();

        $asset->update([
            'metadata' => $currentMetadata,
        ]);

        // Emit failure event (never hide failures)
        AssetEvent::create([
            'tenant_id' => $asset->tenant_id,
            'brand_id' => $asset->brand_id,
            'asset_id' => $asset->id,
            'user_id' => null, // System event
            'event_type' => 'asset.processing.failed',
            'metadata' => [
                'job' => $jobClass,
                'failure_reason' => $failureReason,
                'error' => $exception->getMessage(),
                'is_retryable' => $isRetryable,
                'attempts' => $attempts,
                'exception_class' => get_class($exception),
            ],
            'created_at' => now(),
        ]);

        // Log failure (never hide failures - comprehensive logging)
        Log::error('Asset processing failed', [
            'asset_id' => $asset->id,
            'asset_original_filename' => $asset->original_filename,
            'job_class' => $jobClass,
            'failure_reason' => $failureReason,
            'error_message' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'is_retryable' => $isRetryable,
            'attempts' => $attempts,
            'max_attempts' => self::MAX_RETRY_ATTEMPTS,
            'trace' => $exception->getTraceAsString(),
        ]);

        // Emit domain event for listeners (never hide failures)
        event(new AssetProcessingFailed(
            $asset,
            $jobClass,
            $failureReason,
            $isRetryable,
            $attempts
        ));
    }

    /**
     * Determine if a failure is retryable.
     *
     * @param \Throwable $exception
     * @param int $attempts
     * @return bool
     */
    protected function isRetryableFailure(\Throwable $exception, int $attempts): bool
    {
        // Never retry forever - enforce maximum attempts
        if ($attempts >= self::MAX_RETRY_ATTEMPTS) {
            return false;
        }

        // Non-retryable exceptions
        $nonRetryableExceptions = [
            \InvalidArgumentException::class,
            \TypeError::class,
            \Symfony\Component\HttpKernel\Exception\HttpException::class,
        ];

        $exceptionClass = get_class($exception);

        foreach ($nonRetryableExceptions as $nonRetryableClass) {
            if ($exception instanceof $nonRetryableClass || is_a($exceptionClass, $nonRetryableClass, true)) {
                return false;
            }
        }

        // Check for specific error messages that indicate non-retryable failures
        $nonRetryableMessages = [
            'not found',
            'does not exist',
            'invalid',
            'unauthorized',
            'forbidden',
            'permission denied',
        ];

        $errorMessage = strtolower($exception->getMessage());

        foreach ($nonRetryableMessages as $message) {
            if (str_contains($errorMessage, $message)) {
                return false;
            }
        }

        // Default: assume retryable for transient errors (timeouts, network issues, etc.)
        return true;
    }

    /**
     * Generate human-readable failure reason.
     *
     * @param \Throwable $exception
     * @param string $jobClass
     * @return string
     */
    protected function generateFailureReason(\Throwable $exception, string $jobClass): string
    {
        $exceptionClass = get_class($exception);
        $exceptionMessage = $exception->getMessage();
        $jobName = class_basename($jobClass);

        // Extract human-readable context from exception
        $context = $this->extractContext($exception);

        // Build human-readable message
        $reason = "{$jobName} failed";

        if ($context) {
            $reason .= ": {$context}";
        } elseif ($exceptionMessage) {
            // Clean up exception message for human readability
            $cleanMessage = $this->cleanExceptionMessage($exceptionMessage);
            $reason .= ": {$cleanMessage}";
        }

        // Add exception type if helpful
        if (str_contains($exceptionClass, 'Timeout')) {
            $reason .= " (timeout)";
        } elseif (str_contains($exceptionClass, 'Connection')) {
            $reason .= " (connection error)";
        }

        return $reason;
    }

    /**
     * Extract context from exception for human-readable messages.
     *
     * @param \Throwable $exception
     * @return string|null
     */
    protected function extractContext(\Throwable $exception): ?string
    {
        // Check for common exception types with specific context
        if ($exception instanceof \Aws\S3\Exception\S3Exception) {
            $code = $exception->getAwsErrorCode();
            $message = $exception->getAwsErrorMessage();

            return match ($code) {
                'NoSuchBucket' => 'Storage bucket does not exist',
                'NoSuchKey' => 'File not found in storage',
                'AccessDenied' => 'Access denied to storage',
                'InvalidRequest' => 'Invalid storage request',
                default => $message ?? 'Storage error occurred',
            };
        }

        if ($exception instanceof \PDOException) {
            return 'Database error occurred';
        }

        if ($exception instanceof \Illuminate\Database\QueryException) {
            return 'Database query error';
        }

        return null;
    }

    /**
     * Clean exception message for human readability.
     *
     * @param string $message
     * @return string
     */
    protected function cleanExceptionMessage(string $message): string
    {
        // Remove technical paths and file locations
        $message = preg_replace('/\/([a-zA-Z0-9_\/-]+\.php):\d+/', '[file]', $message);

        // Remove stack trace references
        $message = preg_replace('/Stack trace:.*/s', '', $message);

        // Clean up common technical prefixes
        $message = preg_replace('/^(SQLSTATE|Error|Exception):\s*/i', '', $message);

        // Limit length for readability
        if (strlen($message) > 200) {
            $message = substr($message, 0, 197) . '...';
        }

        return trim($message);
    }

    /**
     * Get maximum retry attempts.
     *
     * @return int
     */
    public static function getMaxRetryAttempts(): int
    {
        return self::MAX_RETRY_ATTEMPTS;
    }
}
