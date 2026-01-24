<?php

return [
    /*
    |--------------------------------------------------------------------------
    | File Type Registry
    |--------------------------------------------------------------------------
    |
    | Central registry for all supported file types in the DAM system.
    | This is the SINGLE SOURCE OF TRUTH for file type support.
    | All services must consult this configuration via FileTypeService.
    |
    | Each file type defines:
    |   - Detection: MIME types and extensions
    |   - Capabilities: What operations are supported
    |   - Handlers: Method names for processing
    |   - Requirements: PHP extensions, packages, external tools
    |   - Errors: Type-specific error messages
    |   - Frontend hints: UI behavior hints (read-only)
    |
    */

    'types' => [
        'image' => [
            'name' => 'Image',
            'description' => 'Standard image formats (JPEG, PNG, GIF, WebP)',
            
            // Detection criteria
            'mime_types' => [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/gif',
                'image/webp',
            ],
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            
            // Processing capabilities
            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
            ],
            
            // Handler configuration (method names in services)
            'handlers' => [
                'thumbnail' => 'generateImageThumbnail',
                'metadata' => 'extractImageMetadata',
            ],
            
            // Requirements
            'requirements' => [
                'php_extensions' => ['gd'],
            ],
            
            // Error messages
            'errors' => [
                'processing_failed' => 'Unable to process image. The file format may not be supported.',
                'corrupted' => 'Unable to read image file. The file may be corrupted.',
                'resize_failed' => 'Unable to resize image. Please try again.',
            ],
            
            // Frontend hints (read-only, for UI consumption)
            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'image',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
        ],

        'tiff' => [
            'name' => 'TIFF',
            'description' => 'TIFF image format (requires Imagick)',
            
            'mime_types' => ['image/tiff', 'image/tif'],
            'extensions' => ['tiff', 'tif'],
            
            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
            ],
            
            'handlers' => [
                'thumbnail' => 'generateTiffThumbnail',
                'metadata' => 'extractTiffMetadata',
            ],
            
            'requirements' => [
                'php_extensions' => ['imagick'],
            ],
            
            'errors' => [
                'processing_failed' => 'TIFF file processing requires Imagick PHP extension.',
                'corrupted' => 'Downloaded file is not a valid TIFF image.',
                'invalid_dimensions' => 'TIFF file has invalid dimensions.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'image',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
        ],

        'avif' => [
            'name' => 'AVIF',
            'description' => 'AVIF image format (requires Imagick)',
            
            'mime_types' => ['image/avif'],
            'extensions' => ['avif'],
            
            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
            ],
            
            'handlers' => [
                'thumbnail' => 'generateAvifThumbnail',
                'metadata' => 'extractAvifMetadata',
            ],
            
            'requirements' => [
                'php_extensions' => ['imagick'],
            ],
            
            'errors' => [
                'processing_failed' => 'AVIF file processing requires Imagick PHP extension.',
                'corrupted' => 'Downloaded file is not a valid AVIF image.',
                'invalid_dimensions' => 'AVIF file has invalid dimensions.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'image',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
        ],

        'pdf' => [
            'name' => 'PDF',
            'description' => 'PDF documents (first page thumbnail)',
            
            'mime_types' => ['application/pdf'],
            'extensions' => ['pdf'],
            
            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => false, // PDFs may not support AI analysis yet
                'download_only' => false,
            ],
            
            'handlers' => [
                'thumbnail' => 'generatePdfThumbnail',
                'metadata' => 'extractPdfMetadata',
            ],
            
            'requirements' => [
                'php_extensions' => ['imagick'],
                'php_packages' => ['spatie/pdf-to-image'],
            ],
            
            'errors' => [
                'processing_failed' => 'PDF processing error. Please try again or contact support if the issue persists.',
                'file_not_found' => 'The PDF file could not be found or accessed.',
                'invalid_format' => 'The PDF file appears to be corrupted or invalid.',
                'generation_failed' => 'Unable to generate preview from PDF. The file may be corrupted or too large.',
                'size_exceeded' => 'PDF file size exceeds maximum allowed size. Large PDFs may cause memory exhaustion.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'pdf',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
            
            // Type-specific configuration
            'config' => [
                'max_size_bytes' => 150 * 1024 * 1024, // 150MB
                'max_page' => 1,
                'timeout_seconds' => 60,
            ],
        ],

        'psd' => [
            'name' => 'Photoshop',
            'description' => 'Adobe Photoshop files (PSD/PSB)',
            
            'mime_types' => ['image/vnd.adobe.photoshop'],
            'extensions' => ['psd', 'psb'],
            
            'capabilities' => [
                'thumbnail' => false, // @todo Implement
                'metadata' => false,
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => true, // Store but don't process
            ],
            
            'handlers' => [
                'thumbnail' => 'generatePsdThumbnail',
            ],
            
            'requirements' => [
                'php_extensions' => ['imagick'],
            ],
            
            'errors' => [
                'not_implemented' => 'PSD thumbnail generation is not yet implemented.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'placeholder',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'ai' => [
            'name' => 'Illustrator',
            'description' => 'Adobe Illustrator files',
            
            'mime_types' => ['application/postscript'],
            'extensions' => ['ai'],
            
            'capabilities' => [
                'thumbnail' => false, // @todo Implement
                'metadata' => false,
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => true,
            ],
            
            'handlers' => [
                'thumbnail' => 'generateAiThumbnail',
            ],
            
            'requirements' => [
                'php_extensions' => ['imagick'],
            ],
            
            'errors' => [
                'not_implemented' => 'AI thumbnail generation is not yet implemented.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'placeholder',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'office' => [
            'name' => 'Office Documents',
            'description' => 'Microsoft Office files (Word, Excel, PowerPoint)',
            
            'mime_types' => [
                'application/msword',
                'application/vnd.ms-excel',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            'extensions' => ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'],
            
            'capabilities' => [
                'thumbnail' => false, // @todo Implement
                'metadata' => false,
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => true,
            ],
            
            'handlers' => [
                'thumbnail' => 'generateOfficeThumbnail',
            ],
            
            'requirements' => [
                'external_tools' => ['libreoffice'], // Future requirement
            ],
            
            'errors' => [
                'not_implemented' => 'Office document thumbnail generation is not yet implemented.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'placeholder',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'video' => [
            'name' => 'Video',
            'description' => 'Video files (MP4, MOV, AVI, etc.)',
            
            'mime_types' => [
                'video/mp4',
                'video/quicktime',
                'video/x-msvideo',
                'video/x-matroska',
                'video/webm',
            ],
            'extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm'],
            
            'capabilities' => [
                'thumbnail' => false, // @todo Implement
                'metadata' => false,
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => true,
            ],
            
            'handlers' => [
                'thumbnail' => 'generateVideoThumbnail',
            ],
            
            'requirements' => [
                'external_tools' => ['ffmpeg'], // Future requirement
            ],
            
            'errors' => [
                'not_implemented' => 'Video thumbnail generation is not yet implemented.',
            ],
            
            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'video',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Unsupported Types
    |--------------------------------------------------------------------------
    |
    | File types that are explicitly NOT supported, with skip reasons.
    | Used for UI messaging and error handling.
    |
    */

    'unsupported' => [
        'bmp' => [
            'mime_types' => ['image/bmp'],
            'extensions' => ['bmp'],
            'skip_reason' => 'unsupported_format:bmp',
            'message' => 'BMP format is not supported. GD library has limited BMP support.',
            'disable_upload_reason' => 'BMP format is not supported for thumbnail generation.',
        ],
        'svg' => [
            'mime_types' => ['image/svg+xml'],
            'extensions' => ['svg'],
            'skip_reason' => 'unsupported_format:svg',
            'message' => 'SVG format is not supported. GD library does not support SVG.',
            'disable_upload_reason' => 'SVG format is not supported for thumbnail generation.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Error Messages
    |--------------------------------------------------------------------------
    |
    | Error messages that apply across all file types or are generic.
    | Type-specific errors are defined in each type's 'errors' array.
    |
    */

    'global_errors' => [
        'storage_failed' => 'Unable to save thumbnail. Please try again.',
        'storage_config_error' => 'Unable to save thumbnail. Please check storage configuration.',
        'timeout' => 'Thumbnail generation timed out. The file may be too large or complex.',
        'execution_timeout' => 'Thumbnail generation took too long. The file may be too large.',
        'generic' => 'An error occurred during thumbnail generation. Please try again or contact support if the issue persists.',
        'unknown_type' => 'File type is not recognized or supported.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Pattern Mappings
    |--------------------------------------------------------------------------
    |
    | Regex patterns for matching technical error messages to user-friendly messages.
    | Used by sanitizeErrorMessage() to convert technical errors to user-facing messages.
    |
    */

    'error_patterns' => [
        // PDF-related errors
        'Call to undefined method.*setPage' => 'global_errors.generic',
        'Call to undefined method.*selectPage' => 'global_errors.generic',
        'PDF file does not exist' => 'pdf.errors.file_not_found',
        'Invalid PDF format' => 'pdf.errors.invalid_format',
        'PDF thumbnail generation failed' => 'pdf.errors.generation_failed',
        
        // Image processing errors
        'getimagesize.*failed' => 'image.errors.corrupted',
        'imagecreatefrom.*failed' => 'image.errors.processing_failed',
        'imagecopyresampled.*failed' => 'image.errors.resize_failed',
        
        // Storage errors
        'S3.*error' => 'global_errors.storage_failed',
        'Storage.*failed' => 'global_errors.storage_config_error',
        
        // Timeout errors
        'timeout' => 'global_errors.timeout',
        'Maximum execution time' => 'global_errors.execution_timeout',
        
        // Generic technical errors
        'Error:' => 'global_errors.generic',
        'Exception:' => 'global_errors.generic',
        'Fatal error' => 'global_errors.generic',
    ],

    /*
    |--------------------------------------------------------------------------
    | MIME Type to Extension Mapping
    |--------------------------------------------------------------------------
    |
    | Standard MIME type to file extension mapping for thumbnail output.
    | Used when determining output file extensions.
    |
    */

    'mime_to_extension' => [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/tiff' => 'tiff',
        'image/tif' => 'tiff',
        'image/avif' => 'avif',
    ],
];
