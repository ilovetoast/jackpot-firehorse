<?php

namespace App\Enums;

/**
 * Download source enum.
 *
 * Tracks where the download was initiated from for analytics and UI context.
 */
enum DownloadSource: string
{
    /**
     * Download initiated from asset grid view.
     */
    case GRID = 'grid';

    /**
     * Download initiated from asset drawer/detail view.
     */
    case DRAWER = 'drawer';

    /**
     * Download initiated from a collection.
     */
    case COLLECTION = 'collection';

    /**
     * Download initiated from a public press-kit page.
     */
    case PUBLIC = 'public';

    /**
     * Download initiated from admin interface.
     */
    case ADMIN = 'admin';

    /**
     * Download initiated from a public collection page (D6). Collection-scoped, no brand access.
     */
    case PUBLIC_COLLECTION = 'public_collection';

    /**
     * Single-asset download from drawer (UX-R2). No ZIP; direct file stream. Tracked for audit.
     */
    case SINGLE_ASSET = 'single_asset';
}
