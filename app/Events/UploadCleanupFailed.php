<?php

namespace App\Events;

use App\Models\UploadSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UploadCleanupFailed Event
 *
 * Domain event emitted when cleanup fails for an upload session.
 * Non-blocking - cleanup failures do not prevent system operation.
 * Provides audit trail for failed cleanup attempts.
 */
class UploadCleanupFailed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param UploadSession|null $uploadSession The upload session that failed cleanup (null for orphaned)
     * @param string $reason Reason for cleanup attempt (expired, terminal, orphaned)
     * @param string $bucket S3 bucket name
     * @param string $objectKeyPrefix S3 object key prefix that failed cleanup
     * @param string $error Error message describing the failure
     */
    public function __construct(
        public ?UploadSession $uploadSession,
        public string $reason,
        public string $bucket,
        public string $objectKeyPrefix,
        public string $error
    ) {
    }
}
