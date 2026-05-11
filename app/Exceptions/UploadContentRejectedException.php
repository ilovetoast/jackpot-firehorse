<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by UploadCompletionService when a file's actual content (sniffed by
 * FileInspectionService at finalize time) is not in the allowlist registry.
 *
 * The temp S3 object has been deleted and the UploadSession marked failed by
 * the time this is thrown, so the controller's only job is to surface a 422
 * with the structured error code and message.
 */
class UploadContentRejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?string $blockedGroup = null,
        public readonly ?string $detectedMime = null,
        public readonly ?string $declaredMime = null,
        public readonly ?string $extension = null,
    ) {
        parent::__construct($message);
    }
}
