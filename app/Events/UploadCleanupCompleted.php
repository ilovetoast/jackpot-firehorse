<?php

namespace App\Events;

use App\Models\UploadSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UploadCleanupCompleted Event
 *
 * Domain event emitted when cleanup is successfully completed for an upload session.
 * Provides audit trail for successful cleanup operations.
 */
class UploadCleanupCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param UploadSession $uploadSession The upload session that was cleaned up
     * @param string $reason Reason for cleanup (expired, terminal, orphaned)
     * @param string $bucket S3 bucket name
     * @param string $objectKeyPrefix S3 object key prefix that was cleaned up
     * @param bool $multipartAborted Whether multipart upload was aborted
     */
    public function __construct(
        public UploadSession $uploadSession,
        public string $reason,
        public string $bucket,
        public string $objectKeyPrefix,
        public bool $multipartAborted = false
    ) {
    }
}
