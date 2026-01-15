<?php

namespace App\Enums;

/**
 * Download Group status enum.
 *
 * Tracks the lifecycle state of a download group (snapshot or living).
 * Status represents the overall download state, separate from ZIP generation state.
 */
enum DownloadStatus: string
{
    /**
     * Download group has been created but is not yet ready.
     * Initial state when download is requested.
     */
    case PENDING = 'pending';

    /**
     * Download group is ready for use.
     * All assets are available and ZIP generation may be triggered.
     */
    case READY = 'ready';

    /**
     * Download group has been invalidated.
     * Asset list changed (living downloads) or user action invalidated it.
     * ZIP must be regenerated if present.
     */
    case INVALIDATED = 'invalidated';

    /**
     * Download group creation or processing failed.
     * Cannot be used and may require cleanup.
     */
    case FAILED = 'failed';
}
