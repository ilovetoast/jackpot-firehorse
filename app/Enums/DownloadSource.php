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
}
