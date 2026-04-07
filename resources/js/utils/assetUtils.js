/**
 * Asset utility functions
 *
 * Helper functions for asset-related operations and guards.
 */

import { mergeThumbnailModeUrlsDrawerSync } from './thumbnailModes'

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
 * Parse 1–5 quality_rating from grid/API asset metadata (root or governed fields bag).
 *
 * @param {Object|null} asset
 * @returns {number|null} Integer 1–5, or null if unset / invalid
 */
export function parseAssetQualityRating(asset) {
    if (!asset?.metadata || typeof asset.metadata !== 'object') return null
    const raw = asset.metadata.quality_rating ?? asset.metadata.fields?.quality_rating
    if (raw == null || raw === '') return null
    const n = typeof raw === 'number' ? raw : parseInt(String(raw), 10)
    if (!Number.isFinite(n) || n < 1 || n > 5) return null
    return n
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
 * Thumbnail fields: when `incoming` includes them (polling, processing), those values win.
 * Partial updates (e.g. only `brand_intelligence`) omit thumbnail keys — then prev is kept.
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
    // Never short-circuit when Brand Intelligence (or other non-thumbnail) fields update.
    // Never short-circuit when multi-mode thumbnail maps change only (drawer poll → grid must get presentation URLs).
    const thumbModeUrlsUnchanged =
        incoming.thumbnail_mode_urls === undefined ||
        JSON.stringify(prev.thumbnail_mode_urls ?? null) ===
            JSON.stringify(incoming.thumbnail_mode_urls ?? null)
    const thumbModesStatusUnchanged =
        incoming.thumbnail_modes_status === undefined ||
        JSON.stringify(prev.thumbnail_modes_status ?? null) ===
            JSON.stringify(incoming.thumbnail_modes_status ?? null)
    const thumbModesMetaUnchanged =
        incoming.thumbnail_modes_meta === undefined ||
        JSON.stringify(prev.thumbnail_modes_meta ?? null) ===
            JSON.stringify(incoming.thumbnail_modes_meta ?? null)

    if (
        incoming.brand_intelligence === undefined &&
        prev.preview_thumbnail_url === incoming.preview_thumbnail_url &&
        prev.final_thumbnail_url === incoming.final_thumbnail_url &&
        prev.thumbnail_version === incoming.thumbnail_version &&
        prev.pdf_page_count === incoming.pdf_page_count &&
        prev.first_page_url === incoming.first_page_url &&
        prev.pdf_page_api_endpoint === incoming.pdf_page_api_endpoint &&
        thumbModeUrlsUnchanged &&
        thumbModesStatusUnchanged &&
        thumbModesMetaUnchanged
    ) {
        return prev
    }
    
    const prevModesStatus =
        prev.thumbnail_modes_status && typeof prev.thumbnail_modes_status === 'object'
            ? prev.thumbnail_modes_status
            : {}
    const nextModesStatus =
        incoming.thumbnail_modes_status && typeof incoming.thumbnail_modes_status === 'object'
            ? incoming.thumbnail_modes_status
            : {}
    const prevModesMeta =
        prev.thumbnail_modes_meta && typeof prev.thumbnail_modes_meta === 'object'
            ? prev.thumbnail_modes_meta
            : {}
    const nextModesMeta =
        incoming.thumbnail_modes_meta && typeof incoming.thumbnail_modes_meta === 'object'
            ? incoming.thumbnail_modes_meta
            : {}

    // Thumbnail fields: incoming wins when the update includes them (polling, reprocess).
    // Partial updates (e.g. only brand_intelligence) omit these keys — must keep prev, not null out.
    return {
        ...prev, // Preserve all previous fields
        ...incoming, // Overwrite with incoming data (e.g. brand_intelligence)
        preview_thumbnail_url: incoming.preview_thumbnail_url ?? prev.preview_thumbnail_url ?? null,
        final_thumbnail_url: incoming.final_thumbnail_url ?? prev.final_thumbnail_url ?? null,
        thumbnail_status: incoming.thumbnail_status ?? prev.thumbnail_status,
        thumbnail_version: incoming.thumbnail_version ?? prev.thumbnail_version ?? null,
        thumbnail_error: incoming.thumbnail_error ?? prev.thumbnail_error ?? null,
        is_pdf: incoming.is_pdf ?? prev.is_pdf ?? false,
        pdf_page_count: incoming.pdf_page_count ?? prev.pdf_page_count ?? null,
        first_page_url: incoming.first_page_url ?? prev.first_page_url ?? null,
        pdf_page_api_endpoint: incoming.pdf_page_api_endpoint ?? prev.pdf_page_api_endpoint ?? null,
        thumbnail_mode_urls:
            mergeThumbnailModeUrlsDrawerSync(incoming.thumbnail_mode_urls, prev.thumbnail_mode_urls) ??
            prev.thumbnail_mode_urls,
        thumbnail_modes_status: { ...prevModesStatus, ...nextModesStatus },
        thumbnail_modes_meta: { ...prevModesMeta, ...nextModesMeta },
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