<?php

namespace App\Enums;

/**
 * ZIP file generation status enum.
 *
 * Tracks the state of ZIP file generation for download groups.
 * Separate from download status - a download can be READY without a ZIP.
 */
enum ZipStatus: string
{
    /**
     * No ZIP file has been generated or requested.
     * Download group exists but ZIP generation has not been triggered.
     */
    case NONE = 'none';

    /**
     * ZIP file is currently being built.
     * ZIP generation job is in progress.
     */
    case BUILDING = 'building';

    /**
     * ZIP file has been generated and is ready for download.
     * ZIP exists in storage and can be served.
     */
    case READY = 'ready';

    /**
     * ZIP file has been invalidated.
     * Asset list changed (living downloads) and ZIP must be regenerated.
     * Old ZIP should be deleted when new one is ready.
     */
    case INVALIDATED = 'invalidated';

    /**
     * ZIP file generation failed.
     * ZIP generation encountered an error and cannot be used.
     */
    case FAILED = 'failed';
}
