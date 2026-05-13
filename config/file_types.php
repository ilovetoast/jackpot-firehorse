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
    | Office (`office` type): thumbnails use LibreOffice → PDF → raster. If workers
    | lack `soffice`, FileTypeService::checkRequirements('office') fails, the pipeline
    | marks thumbnails skipped (e.g. office_libreoffice_missing). Install per
    | docs/environments/PRODUCTION_WORKER_SOFTWARE.md — do not add parallel extension
    | allowlists for Office; use FileTypeService::isOfficeDocument() instead.
    |
    | DO NOT add another file with extension lists. DO NOT hardcode MIMEs in
    | services or jobs. Always go through FileTypeService.
    |
    | Top-level keys:
    |   - types               : allowed types, with capabilities/handlers/upload settings
    |   - grid_filter         : optional grouping for the Assets / Executions ?file_type= filter
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
                /*
                 * HEIC decode is delegated to libheif inside ImageMagick. PHP imagick alone is not enough:
                 * {@see FileTypeService::checkRequirements} verifies HEIC/HEIF appears in Imagick::queryFormats().
                 */
                'imagick_heif_decode' => true,
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
                'thumbnail' => true,
                'metadata' => false,
                'preview' => true,
                'ai_analysis' => false,
                'download_only' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateOfficeThumbnail',
            ],

            'requirements' => [
                'php_extensions' => ['imagick'],
                'php_packages' => ['spatie/pdf-to-image'],
                'external_tools' => ['libreoffice'],
            ],

            'errors' => [
                'processing_failed' => 'Unable to generate a preview for this Office document. The file may be corrupted, password-protected, or too large.',
                'conversion_failed' => 'LibreOffice could not convert this document to a preview.',
                'not_implemented' => 'Office document preview is not available on this server (missing worker software).',
            ],

            'frontend_hints' => [
                'can_preview_inline' => true,
                'preview_component' => 'image',
                'show_placeholder' => false,
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

        /*
        | 3D models — one registry key per extension (capabilities differ by format).
        | Upload is always controlled here; DAM_3D env only gates preview/thumbnail/conversion (see config/dam_3d.php).
        */
        'model_glb' => [
            'name' => '3D model (GLB)',
            'description' => 'Binary glTF 2.0 — preferred single-file format for previews and realtime spin when enabled.',

            'mime_types' => [
                'model/gltf-binary',
            ],
            'extensions' => ['glb'],

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
                'realtime_3d_preview' => true,
                'requires_sidecars' => false,
                'preferred_normalized_format' => 'glb',
                'conversion_required' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateModel3dRasterThumbnail',
            ],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process this GLB file.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'model_3d',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'model_gltf' => [
            'name' => '3D model (glTF)',
            'description' => 'glTF JSON; previews require resolvable .bin and texture sidecars or packaging.',

            'mime_types' => [
                'model/gltf+json',
                'application/json',
            ],
            'extensions' => ['gltf'],

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
                'realtime_3d_preview' => true,
                'requires_sidecars' => true,
                'preferred_normalized_format' => 'glb',
                'conversion_required' => false,
            ],

            'handlers' => [],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process this glTF file.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'model_3d',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'model_obj' => [
            'name' => '3D model (OBJ)',
            'description' => 'Wavefront OBJ; basic/conditional previews depend on companion MTL/textures.',

            'mime_types' => [
                'model/obj',
            ],
            'extensions' => ['obj'],

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
                'realtime_3d_preview' => true,
                'requires_sidecars' => false,
                'preferred_normalized_format' => 'glb',
                'conversion_required' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateModel3dRasterThumbnail',
            ],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process this OBJ file.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'model_3d',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'model_stl' => [
            'name' => '3D model (STL)',
            'description' => 'Stereolithography mesh; basic shaded preview when pipeline is enabled.',

            'mime_types' => [
                'model/stl',
                'application/sla',
                'application/vnd.ms-pki.stl',
            ],
            'extensions' => ['stl'],

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
                'realtime_3d_preview' => true,
                'requires_sidecars' => false,
                'preferred_normalized_format' => 'glb',
                'conversion_required' => false,
            ],

            'handlers' => [
                'thumbnail' => 'generateModel3dRasterThumbnail',
            ],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process this STL file.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'model_3d',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'model_fbx' => [
            'name' => '3D model (FBX)',
            'description' => 'Autodesk FBX — archive/exchange; raster previews require conversion to GLB when enabled.',

            'mime_types' => [
                'application/vnd.autodesk.fbx',
            ],
            'extensions' => ['fbx'],

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
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => false,
                'realtime_3d_preview' => false,
                'requires_sidecars' => false,
                'preferred_normalized_format' => 'glb',
                'conversion_required' => true,
            ],

            'handlers' => [
                'thumbnail' => 'generateModel3dRasterThumbnail',
            ],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process this FBX file.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'model_3d',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],

        'model_blend' => [
            'name' => '3D model (Blender)',
            'description' => 'Blender native — source/archive; previews require conversion to GLB when enabled.',

            'mime_types' => [
                'application/x-blender',
            ],
            'extensions' => ['blend'],

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
                'preview' => false,
                'ai_analysis' => false,
                'download_only' => false,
                'realtime_3d_preview' => false,
                'requires_sidecars' => false,
                'preferred_normalized_format' => 'glb',
                'conversion_required' => true,
            ],

            'handlers' => [
                'thumbnail' => 'generateModel3dRasterThumbnail',
            ],

            'requirements' => [],

            'errors' => [
                'processing_failed' => 'Unable to process this Blender file.',
            ],

            'frontend_hints' => [
                'can_preview_inline' => false,
                'preview_component' => 'model_3d',
                'show_placeholder' => true,
                'disable_upload_reason' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Grid file-type filter (Assets + Executions)
    |--------------------------------------------------------------------------
    |
    | Single place to group registered `types` keys for the library grid
    | ?file_type= filter. Each key under `type_group` must exist in `types`.
    | `type_order` controls ordering within a group (lower first). Omit a
    | type to fall back to alphabetical by display name after ordered keys.
    |
    */
    'grid_filter' => [
        'groups' => [
            ['key' => 'images', 'label' => 'Images', 'order' => 10],
            ['key' => 'documents', 'label' => 'Documents', 'order' => 20],
            ['key' => 'design', 'label' => 'Design', 'order' => 30],
            ['key' => 'video_audio', 'label' => 'Video & audio', 'order' => 40],
            ['key' => '3d', 'label' => '3D models', 'order' => 45],
            ['key' => 'other', 'label' => 'Other', 'order' => 999],
        ],
        'type_group' => [
            'image' => 'images',
            'tiff' => 'images',
            'cr2' => 'images',
            'avif' => 'images',
            'heic' => 'images',
            'svg' => 'images',
            'pdf' => 'documents',
            'office' => 'documents',
            'psd' => 'design',
            'ai' => 'design',
            'video' => 'video_audio',
            'audio' => 'video_audio',
            'model_glb' => '3d',
            'model_gltf' => '3d',
            'model_obj' => '3d',
            'model_stl' => '3d',
            'model_fbx' => '3d',
            'model_blend' => '3d',
        ],
        'type_order' => [
            'image' => 10,
            'svg' => 15,
            'tiff' => 20,
            'avif' => 30,
            'heic' => 40,
            'cr2' => 50,
            'pdf' => 10,
            'office' => 20,
            'psd' => 10,
            'ai' => 20,
            'video' => 10,
            'audio' => 20,
            'model_glb' => 10,
            'model_gltf' => 20,
            'model_obj' => 30,
            'model_stl' => 40,
            'model_fbx' => 50,
            'model_blend' => 60,
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
    | Non-authoritative mirror of types where capabilities.thumbnail === true.
    | Runtime code must use FileTypeService::getThumbnailCapabilityExtensions() /
    | getThumbnailCapabilityMimeTypes() (exposed to the UI as dam_file_types).
    | This list is for human grep / docs only — edit the `types` entry first.
    |
    */
    'supported_thumbnail_extensions' => [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'cr2', 'avif', 'heic', 'heif',
        'pdf', 'psd', 'psb', 'ai', 'eps', 'svg',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
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
