<?php

namespace App\Events;

use App\Models\UploadSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * UploadCleanupAttempted Event
 *
 * Domain event emitted when cleanup is attempted for an upload session.
 * Provides audit trail for cleanup operations.
 */
class UploadCleanupAttempted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param UploadSession $uploadSession The upload session being cleaned up
     * @param string $reason Reason for cleanup (expired, terminal, orphaned)
     * @param string $bucket S3 bucket name
     * @param string $objectKeyPrefix S3 object key prefix being cleaned up
     */
    public function __construct(
        public UploadSession $uploadSession,
        public string $reason,
        public string $bucket,
        public string $objectKeyPrefix
    ) {
    }
}
