<?php

namespace App\Enums;

/**
 * Asset lifecycle status enum.
 *
 * Tracks the full lifecycle of an asset from upload through processing to availability.
 * Assets progress through these states asynchronously via queue jobs.
 *
 * Status flow:
 * INITIATED → UPLOADING → UPLOADED → PROCESSING → THUMBNAIL_GENERATED → AI_TAGGED → COMPLETED
 * Any stage can transition to FAILED on error.
 */
enum AssetStatus: string
{
    /**
     * Upload session has been initiated but file upload has not started.
     * Initial state when UploadSession is created.
     */
    case INITIATED = 'initiated';

    /**
     * File is currently being uploaded to S3.
     * Asset record may not exist yet (upload happens before asset creation).
     */
    case UPLOADING = 'uploading';

    /**
     * File has been successfully uploaded to S3 and asset record created.
     * Upload verification completed. Ready for processing pipeline.
     */
    case UPLOADED = 'uploaded';

    /**
     * Asset is currently being processed (metadata extraction, etc.).
     * Initial processing stage after upload completion.
     */
    case PROCESSING = 'processing';

    /**
     * Thumbnails have been generated successfully.
     * Asset has completed thumbnail generation step.
     */
    case THUMBNAIL_GENERATED = 'thumbnail_generated';

    /**
     * AI tagging has been completed successfully.
     * Asset has been analyzed and tagged by AI system.
     */
    case AI_TAGGED = 'ai_tagged';

    /**
     * Asset has been fully processed and is ready for use.
     * All processing jobs have completed successfully.
     * Assets in this state are available to users via signed URLs.
     */
    case COMPLETED = 'completed';

    /**
     * Processing failed and the asset cannot be made available.
     * Asset remains in storage but is not accessible.
     * May be retried or require manual intervention.
     */
    case FAILED = 'failed';
}
