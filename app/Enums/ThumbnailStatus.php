<?php

namespace App\Enums;

/**
 * Thumbnail generation status enum.
 *
 * Tracks the state of thumbnail generation for an asset.
 * Thumbnails can be generated independently of the main asset processing pipeline.
 */
enum ThumbnailStatus: string
{
    /**
     * Thumbnails have not been generated yet.
     * Initial state for assets that haven't had thumbnail generation attempted.
     */
    case PENDING = 'pending';

    /**
     * Thumbnails are currently being generated.
     * Set when GenerateThumbnailsJob starts processing.
     */
    case PROCESSING = 'processing';

    /**
     * Thumbnails have been generated successfully.
     * All configured thumbnail styles have been created and uploaded to S3.
     */
    case COMPLETED = 'completed';

    /**
     * Thumbnail generation failed.
     * Error details are stored in thumbnail_error field.
     * Asset remains usable even if thumbnails fail.
     */
    case FAILED = 'failed';

    /**
     * Thumbnail generation was skipped because the file type is not supported.
     * No job was dispatched, no work was attempted.
     * This is different from FAILED - skipped means the work never happened.
     */
    case SKIPPED = 'skipped';
}
