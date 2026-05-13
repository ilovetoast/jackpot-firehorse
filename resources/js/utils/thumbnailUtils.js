/**
 * Phase 3.0C: Shared thumbnail utilities
 *
 * Thumbnail-capable MIME/extension lists come from the Laravel registry
 * (`dam_file_types` shared props → {@see ../utils/damFileTypes.js}), so the UI
 * stays aligned with config/file_types.php.
 */

import { getThumbnailExtensions, getThumbnailMimeTypes } from './damFileTypes.js'
import { isRegistryModel3dAsset } from './resolveAsset3dPreviewImage.js'
import {
    getThumbnailUrl as getThumbnailUrlFromResolve,
    getThumbnailUrlModeOnly,
} from './thumbnailUrlResolve.js'

export { getThumbnailUrlFromResolve as getThumbnailUrl, getThumbnailUrlModeOnly }

/**
 * Video types the DAM generates poster/preview for ({@see config/file_types.php} `video`).
 * Browsers often report `file.type === ''` or non-standard MIME for MP4/MOV; extension
 * or `video/*` must still count as supported so upload UI and grid state stay accurate.
 */
const VIDEO_EXTENSIONS_FALLBACK = new Set(['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v'])

/**
 * Check if a file type supports thumbnail generation.
 *
 * @param {string} mimeType - MIME type (e.g., 'image/jpeg')
 * @param {string} fileExtension - File extension (e.g., 'jpg')
 * @returns {boolean} True if thumbnail generation is supported
 */
export function supportsThumbnail(mimeType, fileExtension) {
    const mimes = getThumbnailMimeTypes()
    const exts = getThumbnailExtensions()
    const m = mimeType ? String(mimeType).toLowerCase() : ''
    const e = fileExtension ? String(fileExtension).toLowerCase().replace(/^\./, '') : ''
    if (m && mimes.includes(m)) {
        return true
    }
    if (e && exts.includes(e)) {
        return true
    }
    if (m.startsWith('video/')) {
        return true
    }
    if (e && VIDEO_EXTENSIONS_FALLBACK.has(e)) {
        return true
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
    
    // Primary: thumbnail URLs (most reliable signal)
    // Include all thumbnail URL fields so version changes when any becomes available
    const thumbnailUrl = asset.thumbnail_url || ''
    const finalThumbnailUrl = asset.final_thumbnail_url || ''
    const previewThumbnailUrl = asset.preview_thumbnail_url || ''
    const preview3dPosterUrl = asset.preview_3d_poster_url || ''
    const preview3dViewerUrl = asset.preview_3d_viewer_url || ''
    const preview3dRevision = asset.preview_3d_revision || ''

    // Secondary: thumbnail_status (changes when processing completes)
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status || ''
    
    // Tertiary: updated_at (fallback - changes on any update)
    const updatedAt = asset.updated_at || asset.created_at || ''

    const modeUrls = asset.thumbnail_mode_urls
    const modeUrlsKey =
        modeUrls && typeof modeUrls === 'object' ? JSON.stringify(modeUrls) : ''
    const modesStatus = asset.thumbnail_modes_status || asset.metadata?.thumbnail_modes_status
    const modesStatusKey =
        modesStatus && typeof modesStatus === 'object' ? JSON.stringify(modesStatus) : ''
    const modesMeta = asset.thumbnail_modes_meta || asset.metadata?.thumbnail_modes_meta
    const modeMetaCacheKey =
        modesMeta?.preferred?.cache_key ||
        modesMeta?.original?.cache_key ||
        ''
    
    // Combine into stable version string
    // This will change when any thumbnail-related field changes
    return `${thumbnailUrl}|${finalThumbnailUrl}|${previewThumbnailUrl}|${preview3dPosterUrl}|${preview3dViewerUrl}|${preview3dRevision}|${thumbnailStatus}|${updatedAt}|${modeUrlsKey}|${modesStatusKey}|${modeMetaCacheKey}`
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
 * @param {import('./damFileTypes.js').DamFileTypesPayload|null|undefined} [damFileTypes] Inertia `dam_file_types` for `model_*` detection (optional).
 * @returns {Object} { state, thumbnailUrl, canRetry }
 */
export function getThumbnailState(asset, retryCount = 0, damFileTypes) {
    const mimeType = asset?.mime_type || asset?.file?.type
    const fileExtension = asset?.file_extension || 
                         asset?.original_filename?.split('.').pop()?.toLowerCase() ||
                         asset?.file?.name?.split('.').pop()?.toLowerCase()

    const poster3d =
        typeof asset?.preview_3d_poster_url === 'string' ? asset.preview_3d_poster_url.trim() : ''
    const model3dWithPoster = isRegistryModel3dAsset(asset, damFileTypes) && poster3d.length > 0

    // Phase 3.1E: State A) NOT_SUPPORTED — unless a registry 3D poster URL exists (Phase 5A).
    if (!supportsThumbnail(mimeType, fileExtension) && !model3dWithPoster) {
        return {
            state: 'NOT_SUPPORTED',
            thumbnailUrl: null,
            previewThumbnailUrl: null,
            finalThumbnailUrl: null,
            canRetry: false,
        }
    }

    if (model3dWithPoster) {
        return {
            state: 'AVAILABLE',
            thumbnailUrl: poster3d,
            previewThumbnailUrl: null,
            finalThumbnailUrl: poster3d,
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
    // Backend sends final_thumbnail_url only when thumbnails exist (completed or metadata-resilient)
    // Use it whenever provided - don't require status===completed (resilient to thumbnail status timing; see docs/MEDIA_PIPELINE.md)
    if (asset?.final_thumbnail_url) {
        return {
            state: 'AVAILABLE',
            thumbnailUrl: asset.final_thumbnail_url,
            previewThumbnailUrl: null, // Final exists, no need for preview
            finalThumbnailUrl: asset.final_thumbnail_url,
            canRetry: false,
        }
    }
    
    // Priority 2: Preview thumbnail (temporary, low-quality / LQIP)
    // Use whenever the API exposes a URL. Even if thumbnail_status is failed, a tiny blur may
    // have been persisted early (partial pipeline); grid should still prefer it over icon for images.
    if (asset?.preview_thumbnail_url && thumbnailStatus !== 'skipped') {
        return {
            state: 'PENDING', // Still processing, but preview available
            thumbnailUrl: asset.preview_thumbnail_url,
            previewThumbnailUrl: asset.preview_thumbnail_url,
            finalThumbnailUrl: null,
            canRetry: false,
        }
    }
    
    // Legacy support: fallback to thumbnail_url if new fields not available
    // CRITICAL: Only use if status is completed (prevents loading stale URLs after file replacement)
    if (asset?.thumbnail_url && thumbnailStatus === 'completed') {
        return {
            state: 'AVAILABLE',
            thumbnailUrl: asset.thumbnail_url,
            previewThumbnailUrl: null,
            finalThumbnailUrl: asset.thumbnail_url,
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
