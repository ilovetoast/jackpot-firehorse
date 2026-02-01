<?php

namespace App\Enums;

/**
 * Download access mode enum.
 *
 * Phase D2: Controls who can access the download group and its ZIP file.
 * Access scopes: public, brand, company, users.
 */
enum DownloadAccessMode: string
{
    /**
     * Public access - anyone with the link can access.
     */
    case PUBLIC = 'public';

    /**
     * Brand access - only brand members can access (Pro+).
     */
    case BRAND = 'brand';

    /**
     * Company access - only tenant/company users can access (Pro+).
     * TEAM is alias for backward compatibility.
     */
    case COMPANY = 'company';
    case TEAM = 'team'; // Alias: maps to company

    /**
     * Users access - only specific users in download_user pivot (Enterprise only).
     * RESTRICTED is alias for backward compatibility.
     */
    case USERS = 'users';
    case RESTRICTED = 'restricted'; // Alias: maps to users
}
