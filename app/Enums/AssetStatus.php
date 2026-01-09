<?php

namespace App\Enums;

/**
 * Asset lifecycle status enum.
 *
 * Tracks the full lifecycle of an asset from upload through processing to availability.
 * Assets progress through these states asynchronously via queue jobs.
 */
enum AssetStatus: string
{
    /**
     * Asset has been uploaded but processing has not yet started.
     * Initial state after successful upload completion.
     * Assets in this state are queued for processing.
     */
    case PENDING = 'pending';

    /**
     * Asset is currently being processed (transcoding, thumbnail generation, metadata extraction, etc.).
     * Processing occurs asynchronously via queue jobs.
     * Assets should not be accessible to users in this state.
     */
    case PROCESSING = 'processing';

    /**
     * Asset has been fully processed and is ready for use.
     * All processing jobs have completed successfully.
     * Assets in this state are available to users via signed URLs.
     */
    case READY = 'ready';

    /**
     * Processing failed and the asset cannot be made available.
     * Asset remains in storage but is not accessible.
     * May be retried or require manual intervention.
     */
    case FAILED = 'failed';

    /**
     * Asset has been archived (soft deleted or moved to archive).
     * Asset is not shown in normal listings but may be recoverable.
     * Original file remains in storage.
     */
    case ARCHIVED = 'archived';

    /**
     * Asset has been marked for deletion.
     * Asset will be permanently deleted from storage asynchronously.
     * This is the final state before physical file removal.
     */
    case DELETED = 'deleted';
}
