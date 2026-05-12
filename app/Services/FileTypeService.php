<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
     * @return string|null File type key (e.g., 'image', 'pdf', 'tiff') or null if unknown
     */
    public function detectFileType(?string $mimeType = null, ?string $extension = null): ?string
    {
        $types = config('file_types.types', []);
        $mimeType = $mimeType ? strtolower($mimeType) : null;
        $extension = $extension ? strtolower($extension) : null;

        // MIME and extension from FileInspectionService / version; no extension-based MIME inference
        foreach ($types as $typeKey => $typeConfig) {
            if ($mimeType && in_array($mimeType, $typeConfig['mime_types'] ?? [])) {
                return $typeKey;
            }
            if ($extension && in_array($extension, $typeConfig['extensions'] ?? [])) {
                return $typeKey;
            }
        }

        return null;
    }

    /**
     * Detect file type from Asset model.
     */
    public function detectFileTypeFromAsset(Asset $asset): ?string
    {
        $mimeType = $asset->mime_type ?? null;
        $extension = pathinfo($asset->original_filename ?? '', PATHINFO_EXTENSION);

        return $this->detectFileType($mimeType, $extension);
    }

    /**
     * Check if file is supported (has a registered type).
     */
    public function isSupported(?string $mimeType = null, ?string $extension = null): bool
    {
        return $this->detectFileType($mimeType, $extension) !== null;
    }

    /**
     * True when MIME and/or extension resolves to the given registry key under `file_types.types`.
     *
     * Prefer this (or {@see isOfficeDocument}) over hardcoding Office extension lists in jobs,
     * controllers, and maintenance commands — the registry in config/file_types.php stays the
     * single source of truth for which extensions and MIMEs belong to each type.
     */
    public function matchesRegistryType(?string $mimeType, ?string $extension, string $registryTypeKey): bool
    {
        return $this->detectFileType($mimeType, $extension) === $registryTypeKey;
    }

    /**
     * Word / Excel / PowerPoint (legacy and OpenXML) as registered under the `office` type.
     */
    public function isOfficeDocument(?string $mimeType = null, ?string $extension = null): bool
    {
        return $this->matchesRegistryType($mimeType, $extension, 'office');
    }

    /**
     * Check if file type supports a specific capability.
     *
     * @param  string  $fileType  File type key (e.g., 'image', 'pdf')
     * @param  string  $capability  Capability name (e.g., 'thumbnail', 'metadata', 'preview', 'ai_analysis')
     */
    public function supportsCapability(string $fileType, string $capability): bool
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['capabilities'][$capability] ?? false;
    }

    /**
     * Check if file type requirements are met.
     *
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
                if (! extension_loaded($ext)) {
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
                if ($className && ! class_exists($className)) {
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
                    if (! $ffmpegPath) {
                        $missing[] = 'External tool: FFmpeg (required for video processing)';
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
                    $lo = app(\App\Services\Office\LibreOfficeDocumentPreviewService::class);
                    $binary = $lo->findBinary();
                    if ($binary === null) {
                        $missing[] = 'External tool: LibreOffice (soffice) — required for Office document previews/thumbnails';
                        \Illuminate\Support\Facades\Log::warning('[FileTypeService] LibreOffice not found during requirements check', [
                            'file_type' => $fileType,
                            'configured_binary' => config('assets.thumbnail.office.soffice_binary'),
                        ]);
                    } else {
                        \Illuminate\Support\Facades\Log::debug('[FileTypeService] LibreOffice found during requirements check', [
                            'file_type' => $fileType,
                            'soffice_path' => $binary,
                        ]);
                    }
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
     * @param  string  $operation  Operation name (e.g., 'thumbnail', 'metadata')
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
     * @param  string  $errorKey  Error key (e.g., 'processing_failed', 'corrupted')
     * @return string|null Error message or null if not found
     */
    public function getErrorMessage(string $fileType, string $errorKey): ?string
    {
        $typeConfig = config("file_types.types.{$fileType}", []);

        return $typeConfig['errors'][$errorKey] ?? null;
    }

    /**
     * Get global error message.
     */
    public function getGlobalErrorMessage(string $errorKey): ?string
    {
        return config("file_types.global_errors.{$errorKey}");
    }

    /**
     * Get frontend hints for a file type.
     *
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
                if ($returnCode === 0 && ! empty($output[0]) && file_exists($output[0])) {
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
     * @param  string|null  $fileType  Optional file type for type-specific errors
     * @return string User-friendly error message
     */
    public function sanitizeErrorMessage(string $errorMessage, ?string $fileType = null): string
    {
        $patterns = config('file_types.error_patterns', []);

        // Check for specific error patterns
        foreach ($patterns as $pattern => $messageKey) {
            if (preg_match('/'.$pattern.'/i', $errorMessage)) {
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
     * @return array|null File type configuration or null if not found
     */
    public function getFileTypeConfig(string $fileType): ?array
    {
        return config("file_types.types.{$fileType}");
    }

    /**
     * Get capabilities for a file type.
     *
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
     * @param  Asset|string|null  $assetOrFileType  Asset model or file type string
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

        if (! $fileType) {
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

    /**
     * Unique lowercase MIME types for registry entries with thumbnail capability.
     *
     * @return list<string>
     */
    public function getThumbnailCapabilityMimeTypes(): array
    {
        $seen = [];
        foreach (config('file_types.types', []) as $typeConfig) {
            if (! ($typeConfig['capabilities']['thumbnail'] ?? false)) {
                continue;
            }
            foreach ($typeConfig['mime_types'] ?? [] as $m) {
                $seen[strtolower((string) $m)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Unique lowercase extensions for registry entries with thumbnail capability.
     *
     * @return list<string>
     */
    public function getThumbnailCapabilityExtensions(): array
    {
        $seen = [];
        foreach (config('file_types.types', []) as $typeConfig) {
            if (! ($typeConfig['capabilities']['thumbnail'] ?? false)) {
                continue;
            }
            foreach ($typeConfig['extensions'] ?? [] as $e) {
                $seen[strtolower((string) $e)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * All MIME types from the registry (DAM-supported uploads).
     *
     * @return list<string>
     */
    public function getAllRegisteredMimeTypes(): array
    {
        $seen = [];
        foreach (config('file_types.types', []) as $typeConfig) {
            foreach ($typeConfig['mime_types'] ?? [] as $m) {
                $seen[strtolower((string) $m)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * All extensions from the registry (DAM-supported uploads).
     *
     * @return list<string>
     */
    public function getAllRegisteredExtensions(): array
    {
        $seen = [];
        foreach (config('file_types.types', []) as $typeConfig) {
            foreach ($typeConfig['extensions'] ?? [] as $e) {
                $seen[strtolower((string) $e)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * HTML `accept` attribute value for file inputs (MIME list + dotted extensions).
     *
     * @param  list<string>  $mimeTypes
     * @param  list<string>  $extensions
     */
    public function buildHtmlAcceptAttribute(array $mimeTypes, array $extensions): string
    {
        $parts = [];
        foreach (array_keys(array_flip(array_map('strtolower', $mimeTypes))) as $m) {
            $parts[] = $m;
        }
        foreach (array_keys(array_flip(array_map('strtolower', $extensions))) as $e) {
            $e = ltrim((string) $e, '.');
            if ($e !== '') {
                $parts[] = '.'.$e;
            }
        }

        return implode(',', $parts);
    }

    /*
    |--------------------------------------------------------------------------
    | Upload Allowlist Decision API (single source of truth)
    |--------------------------------------------------------------------------
    |
    | Every upload gate (preflight, initiate-batch, finalize content-sniff)
    | calls isUploadAllowed() and acts on the same decision.
    |
    | Decision codes returned:
    |   ok                       - allowed
    |   blocked_executable       - matched config/file_types.blocked.executable
    |   blocked_server_script    - matched config/file_types.blocked.server_script
    |   blocked_archive          - matched config/file_types.blocked.archive
    |   blocked_web              - matched config/file_types.blocked.web
    |   coming_soon              - registered type, but upload.status === 'coming_soon'
    |   unsupported_type         - not in registry at all
    |   type_disabled            - registered type, but upload.enabled === false
    |
    */

    /**
     * Single decision for "can this MIME/extension pair be uploaded?".
     *
     * @return array{
     *     allowed: bool,
     *     code: string,
     *     message: string|null,
     *     log_severity: string,
     *     file_type: string|null,
     *     blocked_group: string|null
     * }
     */
    public function isUploadAllowed(?string $mimeType = null, ?string $extension = null): array
    {
        $mime = $mimeType !== null && $mimeType !== '' ? strtolower(trim($mimeType)) : null;
        $ext = $extension !== null && $extension !== '' ? strtolower(ltrim(trim($extension), '.')) : null;

        // 1. Hard security block — always wins, even if also in `types`.
        $blocked = $this->isExplicitlyBlocked($mime, $ext);
        if ($blocked !== null) {
            return [
                'allowed' => false,
                'code' => 'blocked_'.$blocked['code_suffix'],
                'message' => $blocked['message'],
                'log_severity' => $blocked['log_severity'],
                'file_type' => null,
                'blocked_group' => $blocked['group'],
            ];
        }

        // 2. Must be in the registry (allowlist).
        $fileType = $this->detectFileType($mime, $ext);
        if ($fileType === null) {
            return [
                'allowed' => false,
                'code' => 'unsupported_type',
                'message' => 'This file type is not supported for upload.',
                'log_severity' => 'info',
                'file_type' => null,
                'blocked_group' => null,
            ];
        }

        $typeConfig = config("file_types.types.{$fileType}", []);
        $upload = $typeConfig['upload'] ?? [];
        $status = (string) ($upload['status'] ?? 'enabled');
        $enabled = (bool) ($upload['enabled'] ?? true);

        // 3. "Coming soon" — registered but not yet processable; reject with friendly message.
        if ($status === 'coming_soon' || $enabled === false && $status === 'coming_soon') {
            $msg = (string) ($upload['disabled_message'] ?? sprintf(
                '%s upload support is coming soon. Please try again later.',
                $typeConfig['name'] ?? ucfirst($fileType)
            ));

            return [
                'allowed' => false,
                'code' => 'coming_soon',
                'message' => $msg,
                'log_severity' => 'info',
                'file_type' => $fileType,
                'blocked_group' => null,
            ];
        }

        // 4. Hard-disabled.
        if (! $enabled) {
            $msg = (string) ($upload['disabled_message'] ?? sprintf(
                'Uploads of %s files are currently disabled.',
                $typeConfig['name'] ?? ucfirst($fileType)
            ));

            return [
                'allowed' => false,
                'code' => 'type_disabled',
                'message' => $msg,
                'log_severity' => 'info',
                'file_type' => $fileType,
                'blocked_group' => null,
            ];
        }

        return [
            'allowed' => true,
            'code' => 'ok',
            'message' => null,
            'log_severity' => 'info',
            'file_type' => $fileType,
            'blocked_group' => null,
        ];
    }

    /**
     * Inspect config/file_types.blocked groups; returns the matching group + metadata
     * if the MIME or extension is on a blocked list, null otherwise.
     *
     * @return array{group: string, code_suffix: string, message: string, log_severity: string}|null
     */
    public function isExplicitlyBlocked(?string $mimeType = null, ?string $extension = null): ?array
    {
        $blocked = config('file_types.blocked', []);
        $mime = $mimeType !== null && $mimeType !== '' ? strtolower(trim($mimeType)) : null;
        $ext = $extension !== null && $extension !== '' ? strtolower(ltrim(trim($extension), '.')) : null;

        if ($mime === null && $ext === null) {
            return null;
        }

        foreach ($blocked as $group => $groupConfig) {
            $exts = array_map('strtolower', $groupConfig['extensions'] ?? []);
            $mimes = array_map('strtolower', $groupConfig['mime_types'] ?? []);

            $hit = ($ext !== null && in_array($ext, $exts, true))
                || ($mime !== null && in_array($mime, $mimes, true));

            if ($hit) {
                return [
                    'group' => (string) $group,
                    'code_suffix' => (string) ($groupConfig['code_suffix'] ?? $group),
                    'message' => (string) ($groupConfig['message'] ?? 'This file type cannot be uploaded for security reasons.'),
                    'log_severity' => (string) ($groupConfig['log_severity'] ?? 'warning'),
                ];
            }
        }

        return null;
    }

    /**
     * MIME values to match in SQL for a registered type: declared MIMEs plus
     * sniff alias keys and values (stored mime may be non-canonical).
     *
     * @return list<string>
     */
    public function getMimeTypeMatchSetForRegisteredType(string $typeKey): array
    {
        $cfg = config("file_types.types.{$typeKey}", []);
        if ($cfg === []) {
            return [];
        }
        $mimes = array_map('strtolower', array_map('strval', $cfg['mime_types'] ?? []));
        $aliases = $cfg['upload']['sniff_mime_aliases'] ?? [];
        $extra = [];
        if (is_array($aliases)) {
            foreach ($aliases as $from => $to) {
                $extra[] = strtolower((string) $from);
                $extra[] = strtolower((string) $to);
            }
        }

        return array_values(array_unique(array_merge($mimes, $extra)));
    }

    /**
     * @return list<string>
     */
    public function getExtensionMatchSetForRegisteredType(string $typeKey): array
    {
        $cfg = config("file_types.types.{$typeKey}", []);
        $exts = array_map('strtolower', array_map('strval', $cfg['extensions'] ?? []));

        return array_values(array_unique($exts));
    }

    /**
     * Narrow an assets query to rows whose mime_type or original_filename
     * extension matches the registry definition for {@see $typeKey}.
     * Uses portable extension matching (ends with .ext) for SQLite + MySQL.
     *
     * @param  Builder<\App\Models\Asset>  $query
     */
    public function applyGridFileTypeFilterToAssetQuery(Builder $query, string $typeKey): bool
    {
        $typeKey = strtolower(trim($typeKey));
        if ($typeKey === '' || ! array_key_exists($typeKey, config('file_types.types', []))) {
            return false;
        }

        $table = $query->getModel()->getTable();
        $mimeCol = "{$table}.mime_type";
        $fnCol = "{$table}.original_filename";
        $mimes = $this->getMimeTypeMatchSetForRegisteredType($typeKey);
        $exts = $this->getExtensionMatchSetForRegisteredType($typeKey);
        if ($mimes === [] && $exts === []) {
            return false;
        }

        $query->where(function ($outer) use ($mimes, $exts, $mimeCol, $fnCol) {
            if ($mimes !== []) {
                $outer->whereIn(DB::raw("LOWER(COALESCE({$mimeCol}, ''))"), $mimes);
            }
            if ($exts !== []) {
                $outer->orWhere(function ($q) use ($exts, $fnCol) {
                    foreach ($exts as $ext) {
                        $e = strtolower((string) $ext);
                        if ($e === '') {
                            continue;
                        }
                        $q->orWhereRaw("LOWER(COALESCE({$fnCol}, '')) LIKE ?", ['%.'.$e]);
                    }
                });
            }
        });

        return true;
    }

    /**
     * Grouped options for the Assets / Executions grid ?file_type= dropdown.
     *
     * @return array{grouped: list<array{group_key: string, group_label: string, group_order: int, types: list<array{key: string, label: string}>}>}
     */
    public function buildGridFileTypeFilterOptionsPayload(): array
    {
        $registry = (array) config('file_types.types', []);
        $grid = (array) config('file_types.grid_filter', []);
        $groupRows = (array) ($grid['groups'] ?? []);
        $typeGroup = (array) ($grid['type_group'] ?? []);
        $typeOrder = (array) ($grid['type_order'] ?? []);

        $groupMeta = [];
        foreach ($groupRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $gk = (string) ($row['key'] ?? '');
            if ($gk === '') {
                continue;
            }
            $groupMeta[$gk] = [
                'label' => (string) ($row['label'] ?? $gk),
                'order' => (int) ($row['order'] ?? 500),
            ];
        }

        $typesFlat = [];
        foreach ($registry as $key => $typeConfig) {
            if (! is_string($key) || $key === '' || ! is_array($typeConfig)) {
                continue;
            }
            $gk = (string) ($typeGroup[$key] ?? 'other');
            if (! isset($groupMeta[$gk])) {
                $groupMeta[$gk] = [
                    'label' => ucfirst(str_replace('_', ' ', $gk)),
                    'order' => 500,
                ];
            }
            $typesFlat[] = [
                'key' => $key,
                'label' => (string) ($typeConfig['name'] ?? ucfirst($key)),
                'group_key' => $gk,
                'group_order' => $groupMeta[$gk]['order'],
                'type_order' => (int) ($typeOrder[$key] ?? 500),
            ];
        }

        usort($typesFlat, function (array $a, array $b): int {
            if ($a['group_order'] !== $b['group_order']) {
                return $a['group_order'] <=> $b['group_order'];
            }
            if ($a['type_order'] !== $b['type_order']) {
                return $a['type_order'] <=> $b['type_order'];
            }

            return strcasecmp($a['label'], $b['label']);
        });

        $byGroup = [];
        foreach ($typesFlat as $row) {
            $gk = $row['group_key'];
            if (! isset($byGroup[$gk])) {
                $byGroup[$gk] = [
                    'group_key' => $gk,
                    'group_label' => $groupMeta[$gk]['label'],
                    'group_order' => $groupMeta[$gk]['order'],
                    'types' => [],
                ];
            }
            $byGroup[$gk]['types'][] = [
                'key' => $row['key'],
                'label' => $row['label'],
            ];
        }

        $grouped = array_values($byGroup);
        usort($grouped, fn (array $a, array $b) => ($a['group_order'] ?? 500) <=> ($b['group_order'] ?? 500));

        return ['grouped' => $grouped];
    }

    /**
     * Build the payload that Inertia ships to the frontend as `dam_file_types`.
     * Exposes ONLY allowlist information (extensions + MIMEs) and the blocked
     * extension list for client-side error messaging — never the registry's
     * private internals.
     *
     * @return array{
     *   thumbnail_mime_types: list<string>,
     *   thumbnail_extensions: list<string>,
     *   upload_mime_types: list<string>,
     *   upload_extensions: list<string>,
     *   upload_accept: string,
     *   thumbnail_accept: string,
     *   blocked_extensions: list<string>,
     *   blocked_mime_types: list<string>,
     *   blocked_groups: array<string, array{extensions: list<string>, mime_types: list<string>, message: string, code_suffix: string}>,
     *   coming_soon: array<string, array{name: string, message: string, extensions: list<string>}>,
     *   grid_file_type_filter_options: array{grouped: list<array<string, mixed>>},
     *   registry_reference: array{canonical_config: string, worker_preview_doc: string},
     * }
     */
    public function getUploadRegistryForFrontend(): array
    {
        $thumbMimes = $this->getThumbnailCapabilityMimeTypes();
        $thumbExts = $this->getThumbnailCapabilityExtensions();
        $uploadMimes = $this->getEnabledUploadMimeTypes();
        $uploadExts = $this->getEnabledUploadExtensions();

        $blocked = config('file_types.blocked', []);
        $blockedExts = [];
        $blockedMimes = [];
        $blockedGroups = [];
        foreach ($blocked as $group => $cfg) {
            $exts = array_values(array_unique(array_map('strtolower', $cfg['extensions'] ?? [])));
            $mimes = array_values(array_unique(array_map('strtolower', $cfg['mime_types'] ?? [])));
            $blockedExts = array_merge($blockedExts, $exts);
            $blockedMimes = array_merge($blockedMimes, $mimes);
            $blockedGroups[(string) $group] = [
                'extensions' => $exts,
                'mime_types' => $mimes,
                'message' => (string) ($cfg['message'] ?? ''),
                'code_suffix' => (string) ($cfg['code_suffix'] ?? $group),
            ];
        }

        $comingSoon = [];
        $typesForHelp = [];
        foreach (config('file_types.types', []) as $key => $typeConfig) {
            $upload = $typeConfig['upload'] ?? [];
            $status = (string) ($upload['status'] ?? 'enabled');
            $enabled = (bool) ($upload['enabled'] ?? true);

            $extensions = array_values(array_unique(array_map('strtolower', $typeConfig['extensions'] ?? [])));
            sort($extensions);

            if ($status === 'coming_soon') {
                $comingSoon[(string) $key] = [
                    'name' => (string) ($typeConfig['name'] ?? $key),
                    'message' => (string) ($upload['disabled_message'] ?? 'This file type is coming soon.'),
                    'extensions' => $extensions,
                ];
            }

            // Help-panel-friendly summary: per-type record with everything a user
            // needs to know about whether they can upload it. Sorted by name for
            // a stable display order in HelpSupportedFileTypes.
            $maxBytes = $upload['max_size_bytes'] ?? null;
            $typesForHelp[] = [
                'key' => (string) $key,
                'name' => (string) ($typeConfig['name'] ?? ucfirst((string) $key)),
                'description' => (string) ($typeConfig['description'] ?? ''),
                'extensions' => $extensions,
                'status' => $status,
                'enabled' => $enabled,
                'disabled_message' => $upload['disabled_message'] ?? null,
                'max_size_bytes' => is_numeric($maxBytes) ? (int) $maxBytes : null,
                'capabilities' => [
                    'preview' => (bool) ($typeConfig['capabilities']['preview'] ?? false),
                    'thumbnail' => (bool) ($typeConfig['capabilities']['thumbnail'] ?? false),
                    'ai_analysis' => (bool) ($typeConfig['capabilities']['ai_analysis'] ?? false),
                    'download_only' => (bool) ($typeConfig['capabilities']['download_only'] ?? false),
                    'web_playback_derivative' => (bool) ($typeConfig['capabilities']['web_playback_derivative'] ?? false),
                ],
                // Optional per-format notes (currently audio-only). When present
                // the help panel renders one short line per codec so users can
                // see which formats are converted vs streamed natively.
                'codec_details' => is_array($typeConfig['codec_details'] ?? null)
                    ? $typeConfig['codec_details']
                    : null,
            ];
        }

        usort($typesForHelp, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'thumbnail_mime_types' => $thumbMimes,
            'thumbnail_extensions' => $thumbExts,
            'upload_mime_types' => $uploadMimes,
            'upload_extensions' => $uploadExts,
            'upload_accept' => $this->buildHtmlAcceptAttribute($uploadMimes, $uploadExts),
            'thumbnail_accept' => $this->buildHtmlAcceptAttribute($thumbMimes, $thumbExts),
            'blocked_extensions' => array_values(array_unique($blockedExts)),
            'blocked_mime_types' => array_values(array_unique($blockedMimes)),
            'blocked_groups' => $blockedGroups,
            'coming_soon' => $comingSoon,
            'types_for_help' => $typesForHelp,
            'grid_file_type_filter_options' => $this->buildGridFileTypeFilterOptionsPayload(),
            // Single source for preview/thumbnail policy + worker deps (paths for operators / UI).
            'registry_reference' => [
                'canonical_config' => 'config/file_types.php',
                'worker_preview_doc' => 'docs/environments/PRODUCTION_WORKER_SOFTWARE.md#office-worker-libreoffice',
            ],
        ];
    }

    /**
     * MIME types from registry where upload.enabled === true AND status === 'enabled'.
     *
     * @return list<string>
     */
    public function getEnabledUploadMimeTypes(): array
    {
        $seen = [];
        foreach (config('file_types.types', []) as $typeConfig) {
            $upload = $typeConfig['upload'] ?? [];
            if (($upload['enabled'] ?? true) !== true) {
                continue;
            }
            if (($upload['status'] ?? 'enabled') !== 'enabled') {
                continue;
            }
            foreach ($typeConfig['mime_types'] ?? [] as $m) {
                $seen[strtolower((string) $m)] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * Extensions from registry where upload.enabled === true AND status === 'enabled'.
     *
     * @return list<string>
     */
    public function getEnabledUploadExtensions(): array
    {
        $seen = [];
        foreach (config('file_types.types', []) as $typeConfig) {
            $upload = $typeConfig['upload'] ?? [];
            if (($upload['enabled'] ?? true) !== true) {
                continue;
            }
            if (($upload['status'] ?? 'enabled') !== 'enabled') {
                continue;
            }
            foreach ($typeConfig['extensions'] ?? [] as $e) {
                $seen[strtolower((string) $e)] = true;
            }
        }

        return array_keys($seen);
    }

    /*
    |--------------------------------------------------------------------------
    | Filename hardening
    |--------------------------------------------------------------------------
    */

    /**
     * Sanitize a user-supplied filename:
     *   - Unicode NFC normalization (matches what most filesystems store).
     *   - Strip control chars (\x00-\x1F, \x7F) including NUL byte (path-traversal vector).
     *   - Strip directory separators and traversal segments.
     *   - Reject Windows reserved basenames (CON, PRN, NUL, COM1-9, LPT1-9, AUX).
     *   - Cap visible length to 200 chars (DB column is 255; leaves room for suffixes).
     *
     * Returns the sanitized name, or empty string if the input was rejected.
     */
    public function sanitizeFilename(string $name): string
    {
        if ($name === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $name = $normalized;
            }
        }

        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = str_replace(['\\', '/'], '', $name);
        $name = preg_replace('/\.\.+/', '.', $name) ?? '';
        $name = trim($name, " \t.");

        if ($name === '') {
            return '';
        }

        $base = pathinfo($name, PATHINFO_FILENAME);
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i', (string) $base)) {
            return '';
        }

        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $maxStem = max(1, 200 - (strlen($ext) > 0 ? strlen($ext) + 1 : 0));
            $name = mb_substr($stem, 0, $maxStem).($ext !== '' ? '.'.$ext : '');
        }

        return $name;
    }

    /**
     * Detect double-extension attacks: any non-final segment of the filename
     * that resolves to a `blocked` extension. e.g. "evil.php.jpg" -> 'php' hit.
     *
     * @return array{group: string, hit_extension: string, message: string}|null
     */
    public function detectDoubleExtensionAttack(string $name): ?array
    {
        if ($name === '' || strpos($name, '.') === false) {
            return null;
        }

        $parts = explode('.', strtolower($name));
        if (count($parts) < 3) {
            return null;
        }

        $segments = array_slice($parts, 1, -1);

        $blocked = config('file_types.blocked', []);
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            foreach ($blocked as $group => $cfg) {
                $exts = array_map('strtolower', $cfg['extensions'] ?? []);
                if (in_array($segment, $exts, true)) {
                    return [
                        'group' => (string) $group,
                        'hit_extension' => $segment,
                        'message' => sprintf(
                            'Filename contains a disallowed extension (.%s). Rename the file and try again.',
                            $segment
                        ),
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Map a "real" content-sniffed MIME (from libmagic/finfo) to its canonical
     * registry MIME via `upload.sniff_mime_aliases`. Returns the canonical MIME
     * if an alias matches, or the original mime unchanged.
     */
    public function canonicalizeSniffedMime(?string $sniffedMime, ?string $extension = null): ?string
    {
        if ($sniffedMime === null || $sniffedMime === '') {
            return $sniffedMime;
        }
        $sniffedMime = strtolower(trim($sniffedMime));

        foreach (config('file_types.types', []) as $typeConfig) {
            $aliases = $typeConfig['upload']['sniff_mime_aliases'] ?? [];
            if (isset($aliases[$sniffedMime])) {
                return strtolower((string) $aliases[$sniffedMime]);
            }
        }

        return $sniffedMime;
    }

    /**
     * Returns true if a registered type requires SVG-style sanitization at finalize.
     */
    public function requiresSanitization(string $fileType): bool
    {
        return (bool) config("file_types.types.{$fileType}.upload.requires_sanitization", false);
    }
}
