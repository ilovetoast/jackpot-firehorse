<?php

namespace App\Enums;

/**
 * Upload method/type.
 *
 * Defines how a file is being uploaded to the system.
 * Different upload types may require different handling strategies.
 */
enum UploadType: string
{
    /**
     * Direct upload from browser/client.
     * Single request upload of the entire file.
     * Suitable for smaller files.
     */
    case DIRECT = 'direct';

    /**
     * Chunked/multipart upload.
     * File is split into chunks and uploaded in parts.
     * Required for large files to avoid timeouts.
     * Parts are assembled after all chunks are uploaded.
     */
    case CHUNKED = 'chunked';

    /**
     * Import from URL.
     * File is downloaded from an external URL.
     * Download happens server-side after URL is provided.
     * May have different validation and processing requirements.
     */
    case URL = 'url';
}
