<?php

namespace App\Services;

use App\Models\Asset;

/**
 * File Type Service
 *
 * Central service for file type detection, capability checking, and handler routing.
 * Provides a single API for all file type operations across the DAM system.
 *
 * This service reads from config/file_types.php, which is the single source of truth.
 * All file type decisions must route through this service.
 *
 * Design Principle: "File type support is a capability decision, not a conditional."
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
     * @param Asset $asset
     * @return string|null
     */
    public function detectFileTypeFromAsset(Asset $asset): ?string
    {
        $mimeType = $asset->mime_type ?? null;
        $extension = pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION);

        return $this->detectFileType($mimeType, $extension);
    }

    /**
     * Check if file is supported (has a registered type).
     *
     * @param string|null $mimeType
     * @param string|null $extension
     * @return bool
     */
    public function isSupported(?string $mimeType = null, ?string $extension = null): bool
    {
        return $this->detectFileType($mimeType, $extension) !== null;
    }

    /**
     * Check if file type supports a specific capability.
     *
     * @param string $fileType File type key (e.g., 'image', 'pdf')
     * @param string $capability Capability name (e.g., 'thumbnail', 'metadata', 'preview', 'ai_analysis')
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
            $classMap = [
                'spatie/pdf-to-image' => \Spatie\PdfToImage\Pdf::class,
            ];
            
            foreach ($requirements['php_packages'] as $package) {
                $className = $classMap[$package] ?? null;
                if ($className && !class_exists($className)) {
                    $missing[] = "PHP package: {$package}";
                }
            }
        }

        // Check external tools (FFmpeg, LibreOffice, etc.)
        if (isset($requirements['external_tools'])) {
            foreach ($requirements['external_tools'] as $tool) {
                if ($tool === 'ffmpeg') {
                    // Check if FFmpeg is available
                    $ffmpegPath = $this->findFFmpegPath();
                    if (!$ffmpegPath) {
                        $missing[] = "External tool: FFmpeg (required for video processing)";
                        \Illuminate\Support\Facades\Log::warning('[FileTypeService] FFmpeg not found during requirements check', [
                            'file_type' => $fileType,
                            'checked_paths' => ['ffmpeg (PATH)', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'],
                        ]);
                    } else {
                        \Illuminate\Support\Facades\Log::debug('[FileTypeService] FFmpeg found during requirements check', [
                            'file_type' => $fileType,
                            'ffmpeg_path' => $ffmpegPath,
                        ]);
                    }
                } elseif ($tool === 'libreoffice') {
                    // @todo Implement LibreOffice checking when needed
                    // For now, LibreOffice is not checked (future requirement)
                }
            }
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
     * Get frontend hints for a file type.
     *
     * @param string $fileType
     * @return array Frontend hints (can_preview_inline, preview_component, show_placeholder, disable_upload_reason)
     */
    public function getFrontendHints(string $fileType): array
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['frontend_hints'] ?? [
            'can_preview_inline' => false,
            'preview_component' => 'placeholder',
            'show_placeholder' => true,
            'disable_upload_reason' => null,
        ];
    }

    /**
     * Find FFmpeg executable path.
     * 
     * @return string|null Path to FFmpeg executable or null if not found
     */
    protected function findFFmpegPath(): ?string
    {
        // Common FFmpeg paths
        $possiblePaths = [
            'ffmpeg', // In PATH
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg', // macOS Homebrew
        ];

        foreach ($possiblePaths as $path) {
            // Check if command exists and is executable
            if ($path === 'ffmpeg') {
                // Check if ffmpeg is in PATH
                $output = [];
                $returnCode = 0;
                exec('which ffmpeg 2>&1', $output, $returnCode);
                if ($returnCode === 0 && !empty($output[0]) && file_exists($output[0])) {
                    return $output[0];
                }
            } elseif (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get unsupported reason for a file type.
     *
     * @param string|null $mimeType
     * @param string|null $extension
     * @return array|null ['skip_reason' => string, 'message' => string, 'disable_upload_reason' => string|null] or null if not unsupported
     */
    public function getUnsupportedReason(?string $mimeType = null, ?string $extension = null): ?array
    {
        $unsupported = config('file_types.unsupported', []);
        $mimeType = $mimeType ? strtolower($mimeType) : null;
        $extension = $extension ? strtolower($extension) : null;

        foreach ($unsupported as $typeConfig) {
            if ($mimeType && in_array($mimeType, $typeConfig['mime_types'] ?? [])) {
                return [
                    'skip_reason' => $typeConfig['skip_reason'],
                    'message' => $typeConfig['message'],
                    'disable_upload_reason' => $typeConfig['disable_upload_reason'] ?? null,
                ];
            }

            if ($extension && in_array($extension, $typeConfig['extensions'] ?? [])) {
                return [
                    'skip_reason' => $typeConfig['skip_reason'],
                    'message' => $typeConfig['message'],
                    'disable_upload_reason' => $typeConfig['disable_upload_reason'] ?? null,
                ];
            }
        }

        return null;
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

    /**
     * Get capabilities for a file type.
     *
     * @param string $fileType
     * @return array Capabilities array
     */
    public function getCapabilities(string $fileType): array
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['capabilities'] ?? [];
    }

    /**
     * Get file type info for frontend consumption.
     * Returns all relevant information for UI rendering.
     *
     * @param Asset|string|null $assetOrFileType Asset model or file type string
     * @return array File type info with capabilities and frontend hints
     */
    public function getFileTypeInfo($assetOrFileType): array
    {
        // Determine file type
        if ($assetOrFileType instanceof Asset) {
            $fileType = $this->detectFileTypeFromAsset($assetOrFileType);
        } else {
            $fileType = $assetOrFileType;
        }

        if (!$fileType) {
            return [
                'file_type' => null,
                'capabilities' => [],
                'unsupported_reason' => null,
                'frontend_hints' => [
                    'can_preview_inline' => false,
                    'preview_component' => 'placeholder',
                    'show_placeholder' => true,
                    'disable_upload_reason' => null,
                ],
            ];
        }

        $capabilities = $this->getCapabilities($fileType);
        $frontendHints = $this->getFrontendHints($fileType);

        // Check if explicitly unsupported
        $unsupported = null;
        if ($assetOrFileType instanceof Asset) {
            $mimeType = $assetOrFileType->mime_type ?? null;
            $extension = pathinfo($assetOrFileType->original_filename ?? '', PATHINFO_EXTENSION);
            $unsupported = $this->getUnsupportedReason($mimeType, $extension);
        }

        return [
            'file_type' => $fileType,
            'capabilities' => $capabilities,
            'unsupported_reason' => $unsupported ? $unsupported['skip_reason'] : null,
            'frontend_hints' => $frontendHints,
        ];
    }
}
