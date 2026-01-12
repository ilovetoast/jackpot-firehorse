/**
 * Asset utility functions
 * 
 * Helper functions for asset-related operations and guards.
 */

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
 * Field-level immutability (not object immutability):
 * - Allows title, filename, metadata updates even for completed assets
 * - Protects only thumbnail-related fields (thumbnail_url, preview_url, thumbnails)
 * - Allows first successful thumbnail hydration (when prev had no thumbnail, incoming has one)
 * 
 * @param {Object} prev - Previous asset state
 * @param {Object} incoming - Incoming asset update
 * @returns {Object} - Merged asset state
 */
export function mergeAsset(prev, incoming) {
    if (!prev) return incoming
    if (!incoming) return prev
    
    const thumbnailStatus = prev.thumbnail_status?.value || prev.thumbnail_status || 'pending'
    const isCompleted = thumbnailStatus === 'completed'
    const hadThumbnail = !!(prev.thumbnail_url || prev.preview_url)
    const incomingHasThumbnail = !!(incoming.thumbnail_url || incoming.preview_url)
    
    // Allow first successful thumbnail hydration
    if (!hadThumbnail && incomingHasThumbnail) {
        return incoming
    }
    
    // Protect thumbnail fields after completion, but allow other field updates
    if (isCompleted) {
        return {
            ...incoming, // Allow title, filename, metadata, and other field updates
            thumbnail_url: prev.thumbnail_url, // Protect thumbnail URL
            preview_url: prev.preview_url, // Protect preview URL (if it exists)
            // Note: thumbnails field not in current schema, but protect if it exists
            ...(prev.thumbnails ? { thumbnails: prev.thumbnails } : {}),
        }
    }
    
    // Default behavior: use incoming data
    return incoming
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