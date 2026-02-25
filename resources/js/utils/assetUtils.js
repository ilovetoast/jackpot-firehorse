/**
 * Asset utility functions
 *
 * Helper functions for asset-related operations and guards.
 */

/**
 * Resolves category ID from an asset object.
 * Backend may send category as category: { id, name } or metadata.category_id; not always top-level category_id.
 * Single source of truth for drawer, filters, and any component that needs asset category.
 *
 * @param {Object} asset - Asset object from API (may have category, metadata.category_id, or category_id)
 * @returns {number|null} - Category ID or null
 */
export function getAssetCategoryId(asset) {
    if (!asset) return null
    return asset.category?.id ?? asset.metadata?.category_id ?? asset.category_id ?? null
}

/**
 * Determines if a preview/thumbnail URL can be mutated for an asset.
 * 
 * Single source of truth for the rule: completed thumbnails must never be replaced.
 * 
 * @param {Object} asset - Asset object
 * @returns {boolean} - true if preview can be mutated, false if it's completed and must be protected
 */
export function canMutatePreview(asset) {
    if (!asset) return false
    
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status || 'pending'
    return thumbnailStatus !== 'completed'
}

/**
 * Merges incoming asset data into previous asset state with field-level protection.
 * 
 * CRITICAL: Thumbnail fields are ALWAYS authoritative from incoming asset.
 * These fields must NEVER be preserved from prevAsset:
 * - preview_thumbnail_url
 * - final_thumbnail_url
 * - thumbnail_status
 * - thumbnail_version
 * - thumbnail_error
 * 
 * This ensures polling updates immediately replace UI state without flashes.
 * 
 * @param {Object} prev - Previous asset state
 * @param {Object} incoming - Incoming asset update
 * @returns {Object} - Merged asset state
 */
export function mergeAsset(prev, incoming) {
    if (!prev) return incoming
    if (!incoming) return prev
    
    // HARD STABILIZATION: Stop re-merging identical assets
    // Prevents useless object replacement → prevents re-renders
    if (
        prev.preview_thumbnail_url === incoming.preview_thumbnail_url &&
        prev.final_thumbnail_url === incoming.final_thumbnail_url &&
        prev.thumbnail_version === incoming.thumbnail_version &&
        prev.pdf_page_count === incoming.pdf_page_count &&
        prev.first_page_url === incoming.first_page_url &&
        prev.pdf_page_api_endpoint === incoming.pdf_page_api_endpoint
    ) {
        return prev
    }
    
    // CRITICAL: Thumbnail fields MUST ALWAYS come from incoming asset
    // Never preserve stale thumbnail state from prevAsset
    // This ensures polling updates immediately replace UI state
    return {
        ...prev, // Preserve all previous fields
        ...incoming, // Overwrite with incoming data
        // Explicitly ensure thumbnail fields come from incoming (authoritative)
        preview_thumbnail_url: incoming.preview_thumbnail_url ?? null,
        final_thumbnail_url: incoming.final_thumbnail_url ?? null,
        thumbnail_status: incoming.thumbnail_status ?? prev.thumbnail_status,
        thumbnail_version: incoming.thumbnail_version ?? null,
        thumbnail_error: incoming.thumbnail_error ?? null,
        is_pdf: incoming.is_pdf ?? prev.is_pdf ?? false,
        pdf_page_count: incoming.pdf_page_count ?? prev.pdf_page_count ?? null,
        first_page_url: incoming.first_page_url ?? prev.first_page_url ?? null,
        pdf_page_api_endpoint: incoming.pdf_page_api_endpoint ?? prev.pdf_page_api_endpoint ?? null,
    }
}

/**
 * Development-only warning when attempting to overwrite a completed thumbnail.
 * 
 * @param {Object} asset - Current asset state
 * @param {Object} incoming - Incoming update that might overwrite thumbnail
 * @param {string} context - Context for debugging (e.g., 'processing-status', 'refresh')
 */
export function warnIfOverwritingCompletedThumbnail(asset, incoming, context = 'unknown') {
    if (process.env.NODE_ENV !== 'development') {
        return // Only warn in development
    }
    
    if (!asset || !incoming) return
    
    const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status || 'pending'
    const isCompleted = thumbnailStatus === 'completed'
    
    if (isCompleted && incoming.thumbnail_url && incoming.thumbnail_url !== asset.thumbnail_url) {
        console.warn(
            `[AssetUtils] Attempted to overwrite completed thumbnail`,
            {
                assetId: asset.id,
                context,
                currentThumbnailUrl: asset.thumbnail_url,
                incomingThumbnailUrl: incoming.thumbnail_url,
                thumbnailStatus,
            }
        )
    }
}