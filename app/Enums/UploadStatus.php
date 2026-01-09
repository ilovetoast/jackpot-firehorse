<?php

namespace App\Enums;

/**
 * Upload process status.
 *
 * Tracks the state of file uploads from initiation through completion.
 * Uploads are separate from assets - an upload must complete before an asset record is created.
 */
enum UploadStatus: string
{
    /**
     * Upload has been initiated but not yet started.
     * Initial state when upload request is received.
     * Upload session or pre-signed URL may have been created.
     */
    case INITIATING = 'initiating';

    /**
     * Upload is currently in progress.
     * File data is being transferred to storage.
     * For chunked uploads, multiple chunks may be uploading concurrently.
     */
    case UPLOADING = 'uploading';

    /**
     * Upload has completed successfully.
     * All file data has been transferred to storage.
     * Upload record is ready for asset creation and processing queue.
     */
    case COMPLETED = 'completed';

    /**
     * Upload failed due to an error.
     * Upload cannot be resumed or retried automatically.
     * Requires user action to retry or investigate.
     *
     * NOTE: Currently, expired/abandoned sessions are also marked as FAILED.
     * Future consideration: If UX needs to distinguish between failure types,
     * an EXPIRED status could be added as a distinct terminal status.
     * For now, the failure_reason field provides sufficient differentiation.
     */
    case FAILED = 'failed';

    /**
     * Upload was cancelled by the user or system.
     * Partial upload data may remain in storage and should be cleaned up.
     * No asset record should be created from cancelled uploads.
     */
    case CANCELLED = 'cancelled';
}
