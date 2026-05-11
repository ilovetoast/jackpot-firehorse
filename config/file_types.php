<?php

return [
    /*
    |--------------------------------------------------------------------------
    | File Type Registry — SINGLE SOURCE OF TRUTH
    |--------------------------------------------------------------------------
    |
    | This file is the ONE place that decides what can be uploaded, what is
    | explicitly blocked, what is "coming soon", and how each registered type
    | is processed downstream.
    |
    | Both backend (FileTypeService, UploadPreflightService, UploadController,
    | UploadCompletionService, processing jobs) AND frontend (Inertia
    | `dam_file_types` shared prop, damFileTypes.js, UploadAssetDialog) read
    | from this registry via FileTypeService.
    |
    | To add a new type:           add an entry under `types`.
    | To block a new exploit:      add an entry under `blocked`.
    | To temporarily soft-disable: set the type's `upload.status = 'coming_soon'`.
    | To skip thumbnails only:     add to `thumbnail_skip`.
    |
    | DO NOT add another file with extension lists. DO NOT hardcode MIMEs in
    | services or jobs. Always go through FileTypeService.
    |
    | Top-level keys:
    |   - types               : allowed types, with capabilities/handlers/upload settings
    |   - blocked             : security-blocked groups (executables, scripts, archives, ...)
    |   - thumbnail_skip      : registered for thumbnail-skip messaging only (no upload effect)
    |   - supported_thumbnail_extensions : reference list (kept for back-compat)
    |   - global_errors       : generic error messages
    |   - error_patterns      : regex -> message-key mappings for sanitizeErrorMessage()
    |   - mime_to_extension   : output extension mapping for thumbnail writes
    |
    */

    'types' => [
        'image' => [
            'name' => 'Image',
            'description' => 'Standard image formats (JPEG, PNG, GIF, WebP)',

            'mime_types' => [
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/gif',
                'image/webp',
            ],
            'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [
                    'image/pjpeg' => 'image/jpeg',
                    'image/x-png' => 'image/png',
                ],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateImageThumbnail',
                'metadata' => 'extractImageMetadata',
            ],

            'requirements' => [
                'php_extensions' => ['gd'],
            ],

            'errors' => [
                'processing_failed' => 'Unable to process image. The file format may not be supported.',
                'corrupted' => 'Unable to read image file. The file may be corrupted.',
                'resize_failed' => 'Unable to resize image. Please try again.',
            ],

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

            'mime_types' => ['image/tiff', 'image/tif', 'image/x-tiff'],
            'extensions' => ['tiff', 'tif'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [
                    'image/x-tiff' => 'image/tiff',
                    'image/tif' => 'image/tiff',
                ],
            ],

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

        /*
        | Canon RAW (.cr2): decode via Imagick → ImageMagick must be built with a RAW delegate (commonly LibRaw).
        | Server: php-imagick + imagemagick with libraw (e.g. Ubuntu: imagemagick-6.q16 + libraw; verify: `identify your.cr2`).
        */
        'cr2' => [
            'name' => 'Canon RAW (CR2)',
            'description' => 'Canon Camera RAW (.cr2); thumbnails via Imagick / ImageMagick RAW delegate',

            'mime_types' => [
                'image/x-canon-cr2',
            ],
            'extensions' => ['cr2'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateCr2Thumbnail',
                'metadata' => 'extractTiffMetadata',
            ],

            'requirements' => [
                'php_extensions' => ['imagick'],
            ],

            'errors' => [
                'processing_failed' => 'CR2 processing requires Imagick and ImageMagick with RAW (LibRaw) support.',
                'corrupted' => 'Unable to read this CR2 file. It may be corrupted or unsupported by ImageMagick.',
                'invalid_dimensions' => 'CR2 file has invalid dimensions.',
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

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

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

        'heic' => [
            'name' => 'HEIC / HEIF',
            'description' => 'Apple HEIC/HEIF images (requires Imagick with HEIF delegate)',

            'mime_types' => ['image/heic', 'image/heif'],
            'extensions' => ['heic', 'heif'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateHeicThumbnail',
                'metadata' => 'extractAvifMetadata',
            ],

            'requirements' => [
                'php_extensions' => ['imagick'],
            ],

            'errors' => [
                'processing_failed' => 'HEIC processing requires Imagick and ImageMagick built with HEIF/libheif support.',
                'corrupted' => 'Downloaded file is not a valid HEIC/HEIF image.',
                'invalid_dimensions' => 'HEIC file has invalid dimensions.',
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

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => 150 * 1024 * 1024,
                'sniff_mime_aliases' => [
                    'application/x-pdf' => 'application/pdf',
                ],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => false,
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

            'config' => [
                'max_size_bytes' => 150 * 1024 * 1024,
                'max_page' => 1,
                'timeout_seconds' => 60,
            ],
        ],

        'psd' => [
            'name' => 'Photoshop',
            'description' => 'Adobe Photoshop files (PSD/PSB)',

            'mime_types' => ['image/vnd.adobe.photoshop'],
            'extensions' => ['psd', 'psb'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => false,
                'preview' => true,
                'ai_analysis' => false,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generatePsdThumbnail',
            ],

            'requirements' => [
                'php_extensions' => ['imagick'],
            ],

            'errors' => [
                'processing_failed' => 'Unable to process PSD file. The file may be corrupted or require ImageMagick with PSD support.',
                'imagick_not_found' => 'PSD processing requires Imagick PHP extension with ImageMagick.',
                'corrupted' => 'Unable to read PSD file. The file may be corrupted.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'image',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
        ],

        'ai' => [
            'name' => 'Illustrator',
            'description' => 'Adobe Illustrator and Encapsulated PostScript files',

            'mime_types' => [
                'application/postscript',
                'application/vnd.adobe.illustrator',
                'application/illustrator',
            ],
            'extensions' => ['ai', 'eps'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => false,
                'preview' => true,
                'ai_analysis' => false,
                'download_only' => false,
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

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

            'capabilities' => [
                'thumbnail' => false,
                'metadata' => false,
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => true,
            ],

            'handlers' => [
                'thumbnail' => 'generateOfficeThumbnail',
            ],

            'requirements' => [
                'external_tools' => ['libreoffice'],
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

        'svg' => [
            'name' => 'SVG',
            'description' => 'Scalable Vector Graphics (sanitized at finalize)',

            'mime_types' => ['image/svg+xml'],
            'extensions' => ['svg'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => 10 * 1024 * 1024,
                'sniff_mime_aliases' => [
                    'text/xml' => 'image/svg+xml',
                    'application/xml' => 'image/svg+xml',
                    'text/plain' => 'image/svg+xml',
                ],
                // SVGs are sanitized at finalize (strip <script>, event handlers, foreignObject, ...)
                // before bytes are committed to the asset version.
                'requires_sanitization' => true,
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => false,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateSvgThumbnail',
            ],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process SVG file. The file may be corrupted.',
                'corrupted' => 'Unable to read SVG file. The file may be corrupted.',
                'sanitization_failed' => 'Unable to sanitize SVG file. The file may contain unsupported content.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'image',
                'show_placeholder' => false,
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
                'video/x-m4v',
            ],
            'extensions' => ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => false,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateVideoThumbnail',
                'metadata' => 'extractVideoMetadata',
            ],

            'requirements' => [
                'external_tools' => ['ffmpeg'],
            ],

            'errors' => [
                'processing_failed' => 'Unable to process video. The file format may not be supported.',
                'corrupted' => 'Unable to read video file. The file may be corrupted.',
                'ffmpeg_not_found' => 'Video processing requires FFmpeg to be installed.',
                'extraction_failed' => 'Unable to extract video frame. Please try again.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'video',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
        ],

        'audio' => [
            'name' => 'Audio',
            'description' => 'Audio files (MP3, WAV, AAC, M4A, OGG, FLAC). MP3 / AAC / M4A / OGG stream directly in every modern browser. WAV and FLAC (and any source above 5 MB) are auto-converted to a 128 kbps MP3 derivative for fast playback — the original always remains downloadable. AI transcript, mood, and summary run on a Whisper-friendly version, transcoded to mono 32 kbps when the file is over 25 MB.',

            'mime_types' => [
                'audio/mpeg',
                'audio/mp3',
                'audio/wav',
                'audio/x-wav',
                'audio/wave',
                'audio/aac',
                'audio/mp4',
                'audio/x-m4a',
                'audio/m4a',
                'audio/ogg',
                'audio/flac',
                'audio/x-flac',
                'audio/webm',
            ],
            'extensions' => ['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac', 'weba'],

            /**
             * Per-codec notes shown in the help panel + documentation. Keeps
             * the supported-types help dynamic and matches the actual playback
             * pipeline ({@see \App\Services\Audio\AudioPlaybackOptimizationService}).
             */
            'codec_details' => [
                'mp3' => [
                    'browser_playback' => 'native',
                    'ai_ingest' => 'native',
                    'note' => 'Streams directly. No conversion. Original is the playback file unless > 5 MB.',
                ],
                'wav' => [
                    'browser_playback' => 'transcoded',
                    'ai_ingest' => 'native',
                    'note' => 'Uncompressed PCM is huge — auto-converted to 128 kbps MP3 for streaming.',
                ],
                'aac' => [
                    'browser_playback' => 'native',
                    'ai_ingest' => 'native',
                    'note' => 'Streams directly. Original used unless > 5 MB.',
                ],
                'm4a' => [
                    'browser_playback' => 'native',
                    'ai_ingest' => 'native',
                    'note' => 'Streams directly (AAC in MP4 container). Original used unless > 5 MB.',
                ],
                'ogg' => [
                    'browser_playback' => 'native',
                    'ai_ingest' => 'native',
                    'note' => 'Streams in modern browsers; an MP3 derivative is generated for legacy clients when source > 5 MB.',
                ],
                'flac' => [
                    'browser_playback' => 'transcoded',
                    'ai_ingest' => 'native',
                    'note' => 'Lossless original is preserved. A 128 kbps MP3 derivative is generated for fast streaming.',
                ],
            ],

            'upload' => [
                'enabled' => true,
                'status' => 'enabled',
                'disabled_message' => null,
                'max_size_bytes' => null,
                'sniff_mime_aliases' => [
                    'audio/x-mpeg' => 'audio/mpeg',
                    'audio/mp3' => 'audio/mpeg',
                    'audio/x-wav' => 'audio/wav',
                    'audio/wave' => 'audio/wav',
                    'audio/x-flac' => 'audio/flac',
                    'audio/x-m4a' => 'audio/mp4',
                    'audio/m4a' => 'audio/mp4',
                ],
            ],

            'capabilities' => [
                'thumbnail' => true,
                'metadata' => true,
                'preview' => true,
                'ai_analysis' => true,
                'download_only' => false,
                /** Web-playback derivative is generated on demand for non-MP3 / large sources. */
                'web_playback_derivative' => true,
            ],

            'handlers' => [
                'thumbnail' => 'generateAudioWaveform',
                'metadata' => 'extractAudioMetadata',
                'web_playback' => 'generateAudioWebPlayback',
                'ai_analysis' => 'runAudioAiAnalysis',
            ],

            'requirements' => [
                'external_tools' => ['ffmpeg'],
            ],

            'errors' => [
                'processing_failed' => 'Unable to process audio. The file format may not be supported.',
                'corrupted' => 'Unable to read audio file. The file may be corrupted.',
                'ffmpeg_not_found' => 'Audio processing requires FFmpeg to be installed.',
                'waveform_failed' => 'Unable to generate waveform image. Please try again.',
                'transcription_failed' => 'Unable to generate transcript for this audio.',
                'web_playback_failed' => 'Unable to generate the web playback version. The original file is still available for download.',
                'oversized_for_ai' => 'Audio is too long for AI analysis even after compression. Trim to under 3 hours and re-upload to enable transcripts.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'audio',
                'show_placeholder' => false,
                'disable_upload_reason' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blocked Upload Types — Hard Security Block
    |--------------------------------------------------------------------------
    |
    | Files matching these MIME types or extensions are REJECTED at every gate
    | (preflight, initiate-batch, finalize content sniff). They never touch the
    | DAM. Add new exploit formats here only.
    |
    | Each group has:
    |   - extensions     : list of ext (no leading dot, lowercase)
    |   - mime_types     : list of MIME types (lowercase) — checked in addition to ext
    |   - message        : user-facing rejection text
    |   - log_severity   : 'warning' (security-relevant) | 'info' (benign reject)
    |   - code_suffix    : appended to 'blocked_' for the API error code
    |
    */
    'blocked' => [
        'executable' => [
            'extensions' => [
                'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'msi', 'msp', 'dll', 'app', 'reg',
                'ps1', 'deb', 'rpm', 'sh', 'bash', 'zsh', 'fish', 'cpl', 'lnk', 'gadget',
                'hta', 'vbs', 'vbe', 'wsf', 'wsh', 'jar', 'class', 'apk', 'dmg', 'iso',
                'img', 'bin', 'run', 'pkg', 'mpkg', 'workflow', 'action', 'osx',
            ],
            'mime_types' => [
                'application/x-msdownload',
                'application/x-msi',
                'application/x-msdos-program',
                'application/x-sh',
                'application/x-bash',
                'application/x-executable',
                'application/x-mach-binary',
                'application/x-dosexec',
                'application/vnd.microsoft.portable-executable',
                'application/java-archive',
                'application/x-java-archive',
                'application/vnd.android.package-archive',
                'application/x-apple-diskimage',
                'application/x-iso9660-image',
            ],
            'message' => 'Executable files cannot be uploaded for security reasons.',
            'log_severity' => 'warning',
            'code_suffix' => 'executable',
        ],

        'server_script' => [
            'extensions' => [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
                'jsp', 'jspx', 'asp', 'aspx', 'cer', 'asa', 'cgi', 'pl', 'py', 'pyc', 'pyo',
                'rb', 'erb', 'rhtml', 'lua', 'tcl', 'cfm', 'cfml',
                'htaccess', 'htpasswd', 'ini', 'env',
            ],
            'mime_types' => [
                'application/x-httpd-php',
                'application/x-httpd-php-source',
                'application/x-php',
                'text/x-php',
                'application/x-perl',
                'text/x-perl',
                'application/x-python',
                'text/x-python',
                'application/x-ruby',
                'text/x-ruby',
            ],
            'message' => 'Server script files cannot be uploaded for security reasons.',
            'log_severity' => 'warning',
            'code_suffix' => 'server_script',
        ],

        'archive' => [
            'extensions' => ['zip', 'tar', 'gz', 'tgz', 'rar', '7z', 'bz2', 'tbz', 'tbz2', 'xz', 'txz', 'z', 'lz', 'lzh', 'lha', 'cab', 'arj', 'ace', 'iso9660'],
            'mime_types' => [
                'application/zip',
                'application/x-zip-compressed',
                'application/x-zip',
                'multipart/x-zip',
                'application/x-tar',
                'application/x-gtar',
                'application/gzip',
                'application/x-gzip',
                'application/x-rar-compressed',
                'application/vnd.rar',
                'application/x-rar',
                'application/x-7z-compressed',
                'application/x-bzip',
                'application/x-bzip2',
                'application/x-xz',
                'application/x-compressed',
                'application/x-lzh-compressed',
                'application/vnd.ms-cab-compressed',
            ],
            'message' => 'Archive files cannot be uploaded. Please extract and upload the contents individually.',
            'log_severity' => 'info',
            'code_suffix' => 'archive',
        ],

        'web' => [
            // HTML pages and other browser-renderable content uploaded into a tenant
            // workspace are an XSS vector when later served from the same origin.
            'extensions' => ['html', 'htm', 'xhtml', 'mhtml', 'mht', 'shtml', 'xht', 'svgz'],
            'mime_types' => [
                'text/html',
                'application/xhtml+xml',
                'multipart/related',
                'message/rfc822',
            ],
            'message' => 'HTML and web pages cannot be uploaded for security reasons.',
            'log_severity' => 'warning',
            'code_suffix' => 'web',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnail Skip Reasons (NOT an upload block)
    |--------------------------------------------------------------------------
    |
    | These types CAN BE UPLOADED (if they happen to slip through the allowlist
    | via, say, an admin override) but their thumbnails are skipped with a
    | friendly placeholder reason. Distinct from `blocked` (hard security
    | rejection) and from `types` (allowed for upload).
    |
    | Today this list is essentially "decorative" — nothing here also appears
    | in `types`, so in practice these formats are rejected by the allowlist
    | gate. Kept for backwards compatibility with thumbnail pipeline messaging.
    |
    */
    'thumbnail_skip' => [
        'bmp' => [
            'mime_types' => ['image/bmp', 'image/x-bmp', 'image/x-ms-bmp'],
            'extensions' => ['bmp'],
            'skip_reason' => 'unsupported_format:bmp',
            'message' => 'Thumbnail generation is not supported for this file type.',
        ],
        'ico' => [
            'mime_types' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            'extensions' => ['ico'],
            'skip_reason' => 'unsupported_format:ico',
            'message' => 'Thumbnail generation is not supported for this file type.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy alias — kept only for code paths still calling
    | FileTypeService::getUnsupportedReason(). Resolves to `thumbnail_skip`.
    |
    */
    'unsupported' => [
        'bmp' => [
            'mime_types' => ['image/bmp', 'image/x-bmp', 'image/x-ms-bmp'],
            'extensions' => ['bmp'],
            'skip_reason' => 'unsupported_format:bmp',
            'message' => 'Thumbnail generation is not supported for this file type.',
            'disable_upload_reason' => null,
        ],
        'ico' => [
            'mime_types' => ['image/x-icon', 'image/vnd.microsoft.icon'],
            'extensions' => ['ico'],
            'skip_reason' => 'unsupported_format:ico',
            'message' => 'Thumbnail generation is not supported for this file type.',
            'disable_upload_reason' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Thumbnail File Types (Reference)
    |--------------------------------------------------------------------------
    |
    | Extensions that support thumbnail generation. Derived from types with
    | capability thumbnail=true. Used for early skip checks and UI hints.
    |
    */
    'supported_thumbnail_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'cr2', 'avif', 'heic', 'heif',
        'pdf', 'psd', 'psb', 'ai', 'eps', 'svg',
        'mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v',
        'mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac', 'weba',
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Error Messages
    |--------------------------------------------------------------------------
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
    | Error Pattern Mappings (regex -> message-key) for sanitizeErrorMessage()
    |--------------------------------------------------------------------------
    */

    'error_patterns' => [
        'Call to undefined method.*setPage' => 'global_errors.generic',
        'Call to undefined method.*selectPage' => 'global_errors.generic',
        'PDF file does not exist' => 'pdf.errors.file_not_found',
        'Invalid PDF format' => 'pdf.errors.invalid_format',
        'PDF thumbnail generation failed' => 'pdf.errors.generation_failed',

        'getimagesize.*failed' => 'image.errors.corrupted',
        'imagecreatefrom.*failed' => 'image.errors.processing_failed',
        'imagecopyresampled.*failed' => 'image.errors.resize_failed',

        'S3.*error' => 'global_errors.storage_failed',
        'Storage.*failed' => 'global_errors.storage_config_error',

        'timeout' => 'global_errors.timeout',
        'Maximum execution time' => 'global_errors.execution_timeout',

        'Error:' => 'global_errors.generic',
        'Exception:' => 'global_errors.generic',
        'Fatal error' => 'global_errors.generic',
    ],

    /*
    |--------------------------------------------------------------------------
    | MIME Type to Extension Mapping (for thumbnail output writes)
    |--------------------------------------------------------------------------
    */

    'mime_to_extension' => [
        'image/svg+xml' => 'svg',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/tiff' => 'tiff',
        'image/tif' => 'tiff',
        'image/x-canon-cr2' => 'cr2',
        'image/avif' => 'avif',
    ],
];
