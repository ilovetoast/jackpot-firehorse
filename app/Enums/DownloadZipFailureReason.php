<?php

namespace App\Enums;

/**
 * Download ZIP build failure reason.
 *
 * Used for failure classification, agent trigger, and escalation decisions.
 */
enum DownloadZipFailureReason: string
{
    case TIMEOUT = 'timeout';
    case DISK_FULL = 'disk_full';
    case S3_READ_ERROR = 's3_read_error';
    case PERMISSION_ERROR = 'permission_error';
    case UNKNOWN = 'unknown';
}
