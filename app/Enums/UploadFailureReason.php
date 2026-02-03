<?php

namespace App\Enums;

/**
 * Phase U-1: Upload failure reason classification.
 *
 * Used for failure tracking, agent trigger, and escalation decisions.
 */
enum UploadFailureReason: string
{
    case PRESIGN_FAILED = 'presign_failed';
    case MULTIPART_INIT_FAILED = 'multipart_init_failed';
    case TRANSFER_FAILED = 'transfer_failed';
    case FINALIZE_FAILED = 'finalize_failed';
    case METADATA_FAILED = 'metadata_failed';
    case THUMBNAIL_FAILED = 'thumbnail_failed';
    case AI_FAILED = 'ai_failed';
    case PERMISSION_ERROR = 'permission_error';
    case UNKNOWN = 'unknown';
}
