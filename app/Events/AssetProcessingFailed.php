<?php

namespace App\Events;

use App\Models\Asset;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AssetProcessingFailed Event
 *
 * Domain event emitted when asset processing fails permanently.
 * This event is fired when a processing job fails after all retry attempts are exhausted.
 *
 * Future Phase: Listeners can subscribe to this event for notifications,
 * alerting, or manual intervention workflows.
 */
class AssetProcessingFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param Asset $asset The asset that failed processing
     * @param string $jobClass The job class that failed
     * @param string $failureReason Human-readable failure reason
     * @param bool $isRetryable Whether the failure is retryable
     * @param int $attempts Number of attempts made
     */
    public function __construct(
        public Asset $asset,
        public string $jobClass,
        public string $failureReason,
        public bool $isRetryable,
        public int $attempts
    ) {
    }
}
