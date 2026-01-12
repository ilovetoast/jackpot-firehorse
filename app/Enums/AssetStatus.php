<?php

namespace App\Enums;

/**
 * Asset visibility status enum.
 *
 * Tracks asset visibility in the system. This enum represents VISIBILITY only,
 * not processing state. Processing state is tracked separately via:
 * - thumbnail_status (ThumbnailStatus enum)
 * - metadata flags (processing_started, metadata_extracted, ai_tagging_completed, etc.)
 * - activity events
 * - pipeline_completed_at timestamp (optional)
 *
 * Status values:
 * - VISIBLE: Asset is visible in grid/dashboard (default for uploaded assets)
 * - HIDDEN: Asset is hidden from normal views (archived, manually hidden, etc.)
 * - FAILED: Asset processing failed (visibility remains controlled separately)
 */
enum AssetStatus: string
{
    /**
     * Asset is visible in the asset grid and dashboard.
     * Default state for uploaded assets.
     * Processing state (thumbnails, AI tagging, etc.) is tracked separately.
     */
    case VISIBLE = 'visible';

    /**
     * Asset is hidden from normal views.
     * May be archived, manually hidden, or temporarily unavailable.
     * Can be made visible again later.
     */
    case HIDDEN = 'hidden';

    /**
     * Asset processing failed.
     * Asset remains in storage but processing encountered errors.
     * Visibility is controlled separately (can be VISIBLE or HIDDEN).
     * May be retried or require manual intervention.
     */
    case FAILED = 'failed';
}
