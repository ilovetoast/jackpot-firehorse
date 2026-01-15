<?php

namespace App\Enums;

/**
 * Download access mode enum.
 *
 * Controls who can access the download group and its ZIP file.
 */
enum DownloadAccessMode: string
{
    /**
     * Public access - anyone with the link can access.
     * Used for public press-kit pages and shareable downloads.
     */
    case PUBLIC = 'public';

    /**
     * Team access - only team members of the tenant can access.
     * Default for internal downloads.
     */
    case TEAM = 'team';

    /**
     * Restricted access - only specific users can access.
     * More granular permission control for sensitive downloads.
     */
    case RESTRICTED = 'restricted';
}
