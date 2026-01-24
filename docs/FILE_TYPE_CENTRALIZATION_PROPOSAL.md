# File Type Centralization Proposal

## Overview

This proposal centralizes file type definitions, processing switches, and error messages into a single, scalable configuration system. This design enables enterprise-level DAM progression by making it easy to add new file types without refactoring multiple services.

## Current State Analysis

### Scattered File Type Logic

File type handling is currently distributed across multiple locations:

1. **File Type Detection** (`ThumbnailGenerationService::detectFileType()`)
   - Lines 1741-1792: MIME type and extension matching
   - Hard-coded switch logic

2. **Thumbnail Support Check** (`UploadCompletionService::supportsThumbnailGeneration()`)
   - Lines 1137-1198: Duplicate MIME type/extension arrays
   - Different logic than detection method

3. **Skip Reason Determination** (`UploadCompletionService::determineSkipReason()`)
   - Lines 1246-1270: Another set of MIME type/extension checks
   - Error code generation

4. **Thumbnail Generation Switch** (`ThumbnailGenerationService::generateThumbnail()`)
   - Lines 749-781: Switch statement routing to handlers
   - Hard-coded case statements

5. **Error Messages** (`ThumbnailGenerationService::sanitizeErrorMessage()`)
   - Lines 65-117: Error pattern matching and user-friendly messages
   - Scattered throughout codebase

6. **MIME Type Mapping** (`ThumbnailGenerationService::getMimeTypeForExtension()`)
   - Lines 1869-1878: Extension to MIME type mapping

### Problems

- **Duplication**: Same MIME types/extensions defined in 3+ places
- **Inconsistency**: Different services may have different support lists
- **Maintenance**: Adding a new type requires changes in 5+ locations
- **No Single Source of Truth**: Can't easily query "what types are supported?"
- **Error Messages Scattered**: User-facing messages hard to maintain

## Proposed Solution

### 1. Centralized File Type Registry

Create a new config file: `config/file_types.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | File Type Registry
    |--------------------------------------------------------------------------
    |
    | Central registry for all supported file types in the DAM system.
    | Each file type defines:
    |   - MIME types and extensions
    |   - Processing capabilities (thumbnail generation, metadata extraction, etc.)
    |   - Handler methods
    |   - Error messages
    |   - Requirements (extensions, packages)
    |
    | This is the SINGLE SOURCE OF TRUTH for file type support.
    | All services should reference this configuration.
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
                'thumbnail_generation' => true,
                'metadata_extraction' => true,
                'color_analysis' => true,
            ],
            
            // Handler configuration
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
        ],

        'tiff' => [
            'name' => 'TIFF',
            'description' => 'TIFF image format (requires Imagick)',
            
            'mime_types' => ['image/tiff', 'image/tif'],
            'extensions' => ['tiff', 'tif'],
            
            'capabilities' => [
                'thumbnail_generation' => true,
                'metadata_extraction' => true,
                'color_analysis' => true,
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
        ],

        'avif' => [
            'name' => 'AVIF',
            'description' => 'AVIF image format (requires Imagick)',
            
            'mime_types' => ['image/avif'],
            'extensions' => ['avif'],
            
            'capabilities' => [
                'thumbnail_generation' => true,
                'metadata_extraction' => true,
                'color_analysis' => true,
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
        ],

        'pdf' => [
            'name' => 'PDF',
            'description' => 'PDF documents (first page thumbnail)',
            
            'mime_types' => ['application/pdf'],
            'extensions' => ['pdf'],
            
            'capabilities' => [
                'thumbnail_generation' => true,
                'metadata_extraction' => true,
                'color_analysis' => false,
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
                'thumbnail_generation' => false, // @todo Implement
                'metadata_extraction' => false,
                'color_analysis' => false,
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
        ],

        'ai' => [
            'name' => 'Illustrator',
            'description' => 'Adobe Illustrator files',
            
            'mime_types' => ['application/postscript'],
            'extensions' => ['ai'],
            
            'capabilities' => [
                'thumbnail_generation' => false, // @todo Implement
                'metadata_extraction' => false,
                'color_analysis' => false,
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
                'thumbnail_generation' => false, // @todo Implement
                'metadata_extraction' => false,
                'color_analysis' => false,
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
                'thumbnail_generation' => false, // @todo Implement
                'metadata_extraction' => false,
                'color_analysis' => false,
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
        ],
        'svg' => [
            'mime_types' => ['image/svg+xml'],
            'extensions' => ['svg'],
            'skip_reason' => 'unsupported_format:svg',
            'message' => 'SVG format is not supported. GD library does not support SVG.',
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
```

### 2. File Type Service

Create a new service: `app/Services/FileTypeService.php`

```php
<?php

namespace App\Services;

/**
 * File Type Service
 *
 * Central service for file type detection, capability checking, and handler routing.
 * Provides a single API for all file type operations across the DAM system.
 *
 * This service reads from config/file_types.php, which is the single source of truth.
 */
class FileTypeService
{
    /**
     * Detect file type from MIME type and/or extension.
     *
     * @param string|null $mimeType
     * @param string|null $extension
     * @return string|null File type key (e.g., 'image', 'pdf', 'tiff') or null if unknown
     */
    public function detectFileType(?string $mimeType = null, ?string $extension = null): ?string
    {
        $types = config('file_types.types', []);
        $mimeType = $mimeType ? strtolower($mimeType) : null;
        $extension = $extension ? strtolower($extension) : null;

        foreach ($types as $typeKey => $typeConfig) {
            // Check MIME type
            if ($mimeType && in_array($mimeType, $typeConfig['mime_types'] ?? [])) {
                return $typeKey;
            }

            // Check extension
            if ($extension && in_array($extension, $typeConfig['extensions'] ?? [])) {
                return $typeKey;
            }
        }

        return null;
    }

    /**
     * Detect file type from Asset model.
     *
     * @param \App\Models\Asset $asset
     * @return string|null
     */
    public function detectFileTypeFromAsset($asset): ?string
    {
        $mimeType = $asset->mime_type ?? null;
        $extension = pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION);

        return $this->detectFileType($mimeType, $extension);
    }

    /**
     * Check if file type supports a specific capability.
     *
     * @param string $fileType File type key (e.g., 'image', 'pdf')
     * @param string $capability Capability name (e.g., 'thumbnail_generation', 'metadata_extraction')
     * @return bool
     */
    public function supportsCapability(string $fileType, string $capability): bool
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['capabilities'][$capability] ?? false;
    }

    /**
     * Check if file type requirements are met.
     *
     * @param string $fileType
     * @return array ['met' => bool, 'missing' => array]
     */
    public function checkRequirements(string $fileType): array
    {
        $typeConfig = config("file_types.types.{$fileType}", []);
        $requirements = $typeConfig['requirements'] ?? [];
        $missing = [];

        // Check PHP extensions
        if (isset($requirements['php_extensions'])) {
            foreach ($requirements['php_extensions'] as $ext) {
                if (!extension_loaded($ext)) {
                    $missing[] = "PHP extension: {$ext}";
                }
            }
        }

        // Check PHP packages (classes)
        if (isset($requirements['php_packages'])) {
            foreach ($requirements['php_packages'] as $package) {
                $classMap = [
                    'spatie/pdf-to-image' => \Spatie\PdfToImage\Pdf::class,
                ];
                
                $className = $classMap[$package] ?? null;
                if ($className && !class_exists($className)) {
                    $missing[] = "PHP package: {$package}";
                }
            }
        }

        // Check external tools (future - for FFmpeg, LibreOffice, etc.)
        if (isset($requirements['external_tools'])) {
            // @todo Implement external tool checking
        }

        return [
            'met' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Get handler method name for a file type and operation.
     *
     * @param string $fileType
     * @param string $operation Operation name (e.g., 'thumbnail', 'metadata')
     * @return string|null Handler method name or null if not supported
     */
    public function getHandler(string $fileType, string $operation): ?string
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['handlers'][$operation] ?? null;
    }

    /**
     * Get error message for a file type and error key.
     *
     * @param string $fileType
     * @param string $errorKey Error key (e.g., 'processing_failed', 'corrupted')
     * @return string|null Error message or null if not found
     */
    public function getErrorMessage(string $fileType, string $errorKey): ?string
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['errors'][$errorKey] ?? null;
    }

    /**
     * Get global error message.
     *
     * @param string $errorKey
     * @return string|null
     */
    public function getGlobalErrorMessage(string $errorKey): ?string
    {
        return config("file_types.global_errors.{$errorKey}");
    }

    /**
     * Sanitize technical error message to user-friendly message.
     *
     * @param string $errorMessage
     * @param string|null $fileType Optional file type for type-specific errors
     * @return string User-friendly error message
     */
    public function sanitizeErrorMessage(string $errorMessage, ?string $fileType = null): string
    {
        $patterns = config('file_types.error_patterns', []);

        // Check for specific error patterns
        foreach ($patterns as $pattern => $messageKey) {
            if (preg_match('/' . $pattern . '/i', $errorMessage)) {
                // Resolve message key (e.g., 'pdf.errors.file_not_found' or 'global_errors.generic')
                $parts = explode('.', $messageKey);
                
                if ($parts[0] === 'global_errors') {
                    return $this->getGlobalErrorMessage($parts[1]) ?? $this->getGlobalErrorMessage('generic');
                } else {
                    // Type-specific error (e.g., 'pdf.errors.file_not_found')
                    $type = $parts[0];
                    $errorKey = $parts[1];
                    return $this->getErrorMessage($type, $errorKey) ?? $this->getGlobalErrorMessage('generic');
                }
            }
        }

        // If error contains class names or technical paths, provide generic message
        if (preg_match('/(\\\\[A-Z][a-zA-Z0-9\\\\]+|::|->|at\s+\/.*\.php)/', $errorMessage)) {
            return $this->getGlobalErrorMessage('generic');
        }

        // For other errors, try to extract a meaningful message
        $cleaned = preg_replace('/^(Error|Exception|Fatal error):\s*/i', '', $errorMessage);

        // If the cleaned message is still too technical, use generic message
        if (strlen($cleaned) > 200 || preg_match('/[{}()\[\]\\\]/', $cleaned)) {
            return $this->getGlobalErrorMessage('generic');
        }

        return $cleaned;
    }

    /**
     * Check if file type is explicitly unsupported.
     *
     * @param string|null $mimeType
     * @param string|null $extension
     * @return array|null ['skip_reason' => string, 'message' => string] or null if not unsupported
     */
    public function getUnsupportedInfo(?string $mimeType = null, ?string $extension = null): ?array
    {
        $unsupported = config('file_types.unsupported', []);
        $mimeType = $mimeType ? strtolower($mimeType) : null;
        $extension = $extension ? strtolower($extension) : null;

        foreach ($unsupported as $typeConfig) {
            if ($mimeType && in_array($mimeType, $typeConfig['mime_types'] ?? [])) {
                return [
                    'skip_reason' => $typeConfig['skip_reason'],
                    'message' => $typeConfig['message'],
                ];
            }

            if ($extension && in_array($extension, $typeConfig['extensions'] ?? [])) {
                return [
                    'skip_reason' => $typeConfig['skip_reason'],
                    'message' => $typeConfig['message'],
                ];
            }
        }

        return null;
    }

    /**
     * Get all supported file types for a capability.
     *
     * @param string $capability
     * @return array Array of file type keys
     */
    public function getSupportedTypesForCapability(string $capability): array
    {
        $types = config('file_types.types', []);
        $supported = [];

        foreach ($types as $typeKey => $typeConfig) {
            if ($typeConfig['capabilities'][$capability] ?? false) {
                $supported[] = $typeKey;
            }
        }

        return $supported;
    }

    /**
     * Get MIME type for extension.
     *
     * @param string $extension
     * @return string|null MIME type or null if not found
     */
    public function getMimeTypeForExtension(string $extension): ?string
    {
        $mapping = config('file_types.mime_to_extension', []);
        $extension = strtolower($extension);

        // Reverse lookup: find MIME type for extension
        foreach ($mapping as $mimeType => $ext) {
            if ($ext === $extension) {
                return $mimeType;
            }
        }

        return null;
    }

    /**
     * Get file type configuration.
     *
     * @param string $fileType
     * @return array|null File type configuration or null if not found
     */
    public function getFileTypeConfig(string $fileType): ?array
    {
        return config("file_types.types.{$fileType}");
    }
}
```

### 3. Refactored Service Methods

#### ThumbnailGenerationService Updates

```php
// Replace detectFileType() method
protected function detectFileType(Asset $asset): string
{
    $fileTypeService = app(FileTypeService::class);
    $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
    
    return $fileType ?? 'unknown';
}

// Replace generateThumbnail() switch statement
protected function generateThumbnail(
    Asset $asset,
    string $sourcePath,
    string $styleName,
    array $styleConfig,
    string $fileType,
    bool $forceImageMagick = false
): ?string {
    if ($forceImageMagick || $fileType === 'imagick_override') {
        return $this->generateImageMagickThumbnail($sourcePath, $styleConfig, $asset);
    }
    
    $fileTypeService = app(FileTypeService::class);
    $handler = $fileTypeService->getHandler($fileType, 'thumbnail');
    
    if (!$handler || !method_exists($this, $handler)) {
        Log::info('Thumbnail generation not supported for file type', [
            'asset_id' => $asset->id,
            'file_type' => $fileType,
            'mime_type' => $asset->mime_type,
        ]);
        return null;
    }
    
    return $this->$handler($sourcePath, $styleConfig);
}

// Replace sanitizeErrorMessage() method
protected function sanitizeErrorMessage(string $errorMessage): string
{
    $fileTypeService = app(FileTypeService::class);
    // Optionally pass file type if available in context
    return $fileTypeService->sanitizeErrorMessage($errorMessage);
}

// Replace getMimeTypeForExtension() method
protected function getMimeTypeForExtension(string $extension): string
{
    $fileTypeService = app(FileTypeService::class);
    return $fileTypeService->getMimeTypeForExtension($extension) ?? 'image/jpeg';
}
```

#### UploadCompletionService Updates

```php
// Replace supportsThumbnailGeneration() method
protected function supportsThumbnailGeneration(Asset $asset): bool
{
    $fileTypeService = app(FileTypeService::class);
    $fileType = $fileTypeService->detectFileTypeFromAsset($asset);
    
    if (!$fileType) {
        return false;
    }
    
    // Check if requirements are met
    $requirements = $fileTypeService->checkRequirements($fileType);
    if (!$requirements['met']) {
        return false;
    }
    
    return $fileTypeService->supportsCapability($fileType, 'thumbnail_generation');
}

// Replace determineSkipReason() method
protected function determineSkipReason(string $mimeType, string $extension): string
{
    $fileTypeService = app(FileTypeService::class);
    
    // Check if explicitly unsupported
    $unsupported = $fileTypeService->getUnsupportedInfo($mimeType, $extension);
    if ($unsupported) {
        return $unsupported['skip_reason'];
    }
    
    // Check if type is detected but doesn't support thumbnails
    $fileType = $fileTypeService->detectFileType($mimeType, $extension);
    if ($fileType && !$fileTypeService->supportsCapability($fileType, 'thumbnail_generation')) {
        return "unsupported_format:{$fileType}";
    }
    
    return 'unsupported_file_type';
}
```

## Benefits

### 1. Single Source of Truth
- All file type definitions in one config file
- No duplication across services
- Easy to query "what types are supported?"

### 2. Easy Extension
- Adding a new file type: add one entry to config
- No need to modify multiple services
- Handler methods remain in services (no refactoring needed)

### 3. Enterprise Scalability
- Clear capability model (thumbnail_generation, metadata_extraction, etc.)
- Requirement checking (extensions, packages, tools)
- Type-specific configuration (PDF max size, timeout, etc.)
- Easy to add new capabilities without touching existing code

### 4. Maintainability
- Centralized error messages
- Pattern-based error sanitization
- Consistent API across all services

### 5. Testing
- Easy to mock file type configurations
- Can test capability checks independently
- Clear separation of concerns

## Migration Path

1. **Phase 1**: Create config file and FileTypeService (no breaking changes)
2. **Phase 2**: Update ThumbnailGenerationService to use FileTypeService
3. **Phase 3**: Update UploadCompletionService to use FileTypeService
4. **Phase 4**: Update any other services that check file types
5. **Phase 5**: Remove old hard-coded logic

## Future Enhancements

1. **Dynamic File Type Registration**: Allow plugins/modules to register new types
2. **Capability-Based Routing**: Route operations based on capabilities, not file types
3. **File Type Metadata**: Store file type info in database for analytics
4. **Plan-Based File Types**: Restrict certain file types to specific plans
5. **Validation Rules**: Define validation rules per file type (max size, dimensions, etc.)

## Notes

- **Support Types Hard-Coded**: As requested, support types remain hard-coded in config
- **Handler Methods**: Handler methods stay in their respective services (no refactoring)
- **Backward Compatible**: Can be implemented incrementally without breaking existing functionality
- **Performance**: Config is cached by Laravel, minimal performance impact
