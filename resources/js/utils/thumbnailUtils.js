/**
 * Phase 3.0C: Shared thumbnail utilities
 * 
 * Provides shared logic for thumbnail state management, file type detection,
 * and thumbnail-supported file type allowlist.
 * 
 * This is UI-only, read-only logic. No backend changes, no polling.
 */

/**
 * File types that support thumbnail generation.
 * 
 * Step 5: ONLY formats that GD library can actually process.
 * GD library supports: JPEG, PNG, WEBP, GIF
 * 
 * Excluded formats (require Imagick or other tools):
 * - TIFF: GD library does not support TIFF
 * - BMP: GD library has limited BMP support, not reliable
 * - SVG: GD library does not support SVG
 * - AVIF: Backend pipeline does not support it yet
 * 
 * These formats will be marked as SKIPPED with appropriate skip reasons.
 */
export const THUMBNAIL_SUPPORTED_TYPES = [
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/gif',
    'image/webp',
    // TIFF excluded: GD library does not support TIFF (requires Imagick)
    // 'image/tiff',
    // 'image/tif',
    // BMP excluded: GD library has limited BMP support, not reliable
    // 'image/bmp',
    // SVG excluded: GD library does not support SVG (requires Imagick or other tools)
    // 'image/svg+xml',
    // AVIF excluded: backend pipeline does not support it yet
    // 'image/avif',
    // HEIC/HEIF excluded: backend pipeline may not support these yet
    // 'image/heic',
    // 'image/heif',
]

/**
 * File extensions that support thumbnail generation.
 * Used as fallback when mime_type is not available.
 * 
 * Step 5: ONLY formats that GD library can actually process.
 * GD library supports: jpg, jpeg, png, gif, webp
 */
export const THUMBNAIL_SUPPORTED_EXTENSIONS = [
    'jpg',
    'jpeg',
    'png',
    'gif',
    'webp',
    // TIFF excluded: GD library does not support TIFF
    // 'tiff',
    // 'tif',
    // BMP excluded: GD library has limited BMP support
    // 'bmp',
    // SVG excluded: GD library does not support SVG
    // 'svg',
    // AVIF excluded: backend pipeline does not support it yet
    // 'avif',
    // HEIC/HEIF excluded: backend pipeline may not support these yet
    // 'heic',
    // 'heif',
]

/**
 * Check if a file type supports thumbnail generation.
 * 
 * @param {string} mimeType - MIME type (e.g., 'image/jpeg')
 * @param {string} fileExtension - File extension (e.g., 'jpg')
 * @returns {boolean} True if thumbnail generation is supported
 */
export function supportsThumbnail(mimeType, fileExtension) {
    if (mimeType) {
        return THUMBNAIL_SUPPORTED_TYPES.includes(mimeType.toLowerCase())
    }
    
    if (fileExtension) {
        return THUMBNAIL_SUPPORTED_EXTENSIONS.includes(fileExtension.toLowerCase())
    }
    
    return false
}

/**
 * Phase 3.1: Derive stable thumbnail version signal from asset props
 * 
 * WHY THUMBNAIL VERSION EXISTS:
 * Memoized grid rows need a stable signal to detect when thumbnail availability changes
 * after background reconciliation. Without this, memoization prevents re-renders even
 * when thumbnails become available, leaving stale "pending" states.
 * 
 * This version is derived from server props that change when thumbnails are generated:
 * - thumbnail_url (primary signal - changes when thumbnail becomes available)
 * - thumbnail_status (changes when processing completes/fails)
 * - updated_at (fallback - changes on any asset update)
 * 
 * @param {Object} asset - Asset object
 * @returns {string} Stable version string that changes when thumbnail state changes
 */
export function getThumbnailVersion(asset) {
    if (!asset) return '0'
    
    // Primary: thumbnail_url (most reliable signal)
    const thumbnailUrl = asset.thumbnail_url || ''
    
    // Secondary: thumbnail_status (changes when processing completes)
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status || ''
    
    // Tertiary: updated_at (fallback - changes on any update)
    const updatedAt = asset.updated_at || asset.created_at || ''
    
    // Combine into stable version string
    // This will change when any thumbnail-related field changes
    return `${thumbnailUrl}|${thumbnailStatus}|${updatedAt}`
}

/**
 * Phase 3.1E: Thumbnail state machine with strict state contracts
 * 
 * Four explicit UI states:
 * 
 * A) NOT_SUPPORTED (by file type)
 *    - Determined by extension/mime only
 *    - Never render <img>
 *    - Never poll
 *    - Always show FileTypeIcon
 *    - User message: "Preview not supported for this file type."
 * 
 * B) PENDING / PROCESSING
 *    - thumbnail_status !== 'completed'
 *    - Never render <img>
 *    - Show FileTypeIcon + optional processing indicator
 *    - Smart poll checks backend for availability
 * 
 * C) FAILED
 *    - thumbnail_status === 'failed'
 *    - Never render <img>
 *    - Show FileTypeIcon
 *    - User message: "Preview failed to generate."
 * 
 * D) AVAILABLE
 *    - thumbnail_status === 'completed' AND thumbnail_url exists
 *    - Render <img>
 *    - Fade-in ONLY if smart poll detected transition from non-available → available
 *    - Never fade on initial render or re-render
 * 
 * WHY THESE RULES EXIST:
 * - Prevents misleading UI (green blocks, cached placeholders)
 * - Ensures thumbnails only appear when verified
 * - Smart poll authority: only polling/reconciliation may promote to AVAILABLE
 * - UI must never assume thumbnail availability
 * 
 * @param {Object} asset - Asset object with thumbnail_url, thumbnail_status, mime_type, file_extension
 * @param {number} retryCount - Number of retry attempts (for UI-only retry logic)
 * @returns {Object} { state, thumbnailUrl, canRetry }
 */
export function getThumbnailState(asset, retryCount = 0) {
    const mimeType = asset?.mime_type || asset?.file?.type
    const fileExtension = asset?.file_extension || 
                         asset?.original_filename?.split('.').pop()?.toLowerCase() ||
                         asset?.file?.name?.split('.').pop()?.toLowerCase()
    
    // Phase 3.1E: State A) NOT_SUPPORTED - determined by extension/mime only
    // AVIF is an image format but not currently supported by the thumbnail pipeline.
    // Treat as non-thumbnail file until backend support is added.
    // This prevents the UI from expecting a thumbnail that will never be generated.
    if (fileExtension === 'avif' || mimeType === 'image/avif') {
        return {
            state: 'NOT_SUPPORTED',
            thumbnailUrl: null,
            previewThumbnailUrl: null,
            finalThumbnailUrl: null,
            canRetry: false,
        }
    }
    
    // Check if file type supports thumbnails
    if (!supportsThumbnail(mimeType, fileExtension)) {
        return {
            state: 'NOT_SUPPORTED',
            thumbnailUrl: null,
            previewThumbnailUrl: null,
            finalThumbnailUrl: null,
            canRetry: false,
        }
    }
    
    // Check thumbnail status from backend
    const thumbnailStatus = asset?.thumbnail_status?.value || asset?.thumbnail_status
    
    // ============================================================================
    // ABSOLUTE PRIORITY: REAL THUMBNAILS ALWAYS WIN OVER STATE
    // ============================================================================
    // Priority order: final_thumbnail_url > preview_thumbnail_url > icon
    // 
    // WHY FINAL ALWAYS WINS:
    // - Final thumbnails are permanent, full-quality, and include version for cache busting
    // - Once final exists, preview is no longer needed
    // - Final URL changes when version changes, ensuring browser refetches
    // 
    // WHY PREVIEW EXISTS:
    // - Preview thumbnails are temporary, low-quality thumbnails shown during processing
    // - They provide immediate visual feedback while final thumbnail is being generated
    // - Preview and final URLs are distinct, preventing cache confusion
    // 
    // WHY ICONS ARE LAST RESORT:
    // - Icons only show when no thumbnail exists (unsupported format, failed, or not ready)
    // - Never show icons if preview or final exists - thumbnails always win
    // ============================================================================
    
    // Priority 1: Final thumbnail (permanent, full-quality, versioned)
    if (asset?.final_thumbnail_url) {
        return {
            state: 'AVAILABLE',
            thumbnailUrl: asset.final_thumbnail_url,
            previewThumbnailUrl: null, // Final exists, no need for preview
            finalThumbnailUrl: asset.final_thumbnail_url,
            canRetry: false,
        }
    }
    
    // Priority 2: Preview thumbnail (temporary, low-quality)
    if (asset?.preview_thumbnail_url) {
        return {
            state: 'PENDING', // Still processing, but preview available
            thumbnailUrl: asset.preview_thumbnail_url,
            previewThumbnailUrl: asset.preview_thumbnail_url,
            finalThumbnailUrl: null,
            canRetry: false,
        }
    }
    
    // Legacy support: fallback to thumbnail_url if new fields not available
    if (asset?.thumbnail_url) {
        // Assume it's final if thumbnail_status is completed
        const isFinal = thumbnailStatus === 'completed'
        return {
            state: 'AVAILABLE',
            thumbnailUrl: asset.thumbnail_url,
            previewThumbnailUrl: isFinal ? null : asset.thumbnail_url,
            finalThumbnailUrl: isFinal ? asset.thumbnail_url : null,
            canRetry: false,
        }
    }
    
    // ============================================================================
    // STATE CONTRACTS (only apply when thumbnail_url does NOT exist)
    // ============================================================================
    
    // Phase 3.1E: State C) FAILED - thumbnail_status === 'failed'
    // Only applies when no thumbnail URLs exist
    // Never render <img>, show FileTypeIcon with error message
    if (thumbnailStatus === 'failed') {
        // Allow retry if retryCount < 2 (UI-only, max 2 retries)
        return {
            state: 'FAILED',
            thumbnailUrl: null,
            previewThumbnailUrl: null,
            finalThumbnailUrl: null,
            canRetry: retryCount < 2,
        }
    }
    
    // Phase 3.1E: State D) SKIPPED - thumbnail_status === 'skipped'
    // Only applies when no thumbnail URLs exist
    // Never render <img>, show FileTypeIcon only
    // This means thumbnail generation was never attempted (unsupported file type)
    if (thumbnailStatus === 'skipped') {
        return {
            state: 'SKIPPED',
            thumbnailUrl: null,
            previewThumbnailUrl: null,
            finalThumbnailUrl: null,
            canRetry: false,
        }
    }
    
    // Phase 3.1E: State B) PENDING / PROCESSING - thumbnail_status !== 'completed'
    // Only applies when no thumbnail URLs exist
    // Never render <img>, show FileTypeIcon + processing indicator
    // Smart poll will check backend for availability
    return {
        state: 'PENDING',
        thumbnailUrl: null,
        previewThumbnailUrl: null,
        finalThumbnailUrl: null,
        canRetry: false,
    }
}
