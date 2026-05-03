/**
 * useThumbnailSmartPoll Hook - Step 3: Smart Polling for Preview → Final Thumbnails
 * 
 * Grid-scoped smart polling that automatically updates preview thumbnails to final thumbnails.
 * 
 * RULES:
 * - Poll ONLY for assets currently rendered in the grid
 * - Poll ONLY for assets that:
 *   - have previewThumbnailUrl
 *   - do NOT have finalThumbnailUrl
 *   - are NOT unsupported format
 *   - have no thumbnail_error
 * - Poll stops automatically when no assets qualify
 * - Uses exponential backoff between polls: 3s → 4s → 5s → 10s → 20s → stop
 * - First batch request runs immediately; short early gaps so the grid catches “ready” soon after the job finishes
 * - Max duration ~1–2 minutes of follow-up polling
 * 
 * WHY THIS EXISTS:
 * - Preview thumbnails provide immediate visual feedback during processing
 * - Final thumbnails are higher quality and versioned
 * - Smart polling automatically swaps preview → final when ready
 * - No manual refresh needed
 * 
 * @param {Object} options
 * @param {Array} options.assets - Assets currently rendered in grid
 * @param {Function} options.onAssetUpdate - Callback when asset is updated (receives updated asset)
 * @param {number|null} options.selectedCategoryId - Current category ID (stops polling on change)
 * @param {boolean} [options.isPaused] - When true, polling timers are cleared (e.g. Add Asset modal actively uploading)
 * @returns {void} - Hook manages polling internally
 */
import { useEffect, useRef } from 'react'
import { mergeAsset } from '../utils/assetUtils'
import { assetThumbnailPollEligible } from '../utils/assetCardVisualState'

// Gaps between batch polls (first poll is immediate). 3–5s early cadence after refresh while previews generate.
const POLL_SCHEDULE = [3000, 4000, 5000, 10000, 20000] // milliseconds
const MAX_POLL_ATTEMPTS = POLL_SCHEDULE.length

/**
 * HARD STABILIZATION: Thumbnail polling is disabled to prevent flashing and visual instability.
 * 
 * NOTE: Thumbnails intentionally do NOT live-update on the grid.
 * Stability > real-time updates.
 * 
 * Live thumbnail upgrades can be reintroduced later via explicit user action (refresh / reopen page).
 */
export function useThumbnailSmartPoll({ assets, onAssetUpdate, selectedCategoryId = null, isPaused = false }) {
    // Re-enabled: Grid polling for fade-in thumbnails (same as drawer)
    // Async updates only - no view refreshes

    // Initialize refs
    const assetsRef = useRef(assets)
    const onAssetUpdateRef = useRef(onAssetUpdate)
    const isActiveRef = useRef(false)
    const pollAttemptRef = useRef(0)
    const prevCategoryIdRef = useRef(selectedCategoryId)
    const timeoutIdRef = useRef(null)

    // Keep refs in sync
    useEffect(() => {
        assetsRef.current = assets
    }, [assets])
    
    useEffect(() => {
        onAssetUpdateRef.current = onAssetUpdate
    }, [onAssetUpdate])

    // Derive poll targets: assets that are pending/processing, support thumbnails, and don't have final yet
    // Do NOT depend on preview existence - poll for all eligible assets
    const getPollTargets = () => {
        const currentAssets = assetsRef.current || []
        
        return currentAssets.filter((asset) => assetThumbnailPollEligible(asset))
    }

    // Perform a single poll
    const performPoll = async () => {
        const pollTargets = getPollTargets()
        
        // Stop if no targets
        if (pollTargets.length === 0) {
            isActiveRef.current = false
            pollAttemptRef.current = 0
            return
        }
        
        // Check if category changed
        if (prevCategoryIdRef.current !== selectedCategoryId) {
            isActiveRef.current = false
            pollAttemptRef.current = 0
            prevCategoryIdRef.current = selectedCategoryId
            return
        }
        
        // Check if we've exceeded max attempts
        if (pollAttemptRef.current >= MAX_POLL_ATTEMPTS) {
            isActiveRef.current = false
            pollAttemptRef.current = 0
            return
        }
        
        try {
            const assetIds = pollTargets.map(asset => asset.id)
            
            const response = await window.axios.get('/app/assets/thumbnail-status/batch', {
                params: {
                    asset_ids: assetIds.join(','),
                },
            })
            
            const { assets: updatedAssets = [] } = response.data
            
            // Process updates
            let hasUpdates = false
            updatedAssets.forEach(updatedAsset => {
                const assetId = updatedAsset.asset_id
                const currentAsset = assetsRef.current.find(a => a.id === assetId)
                
                if (!currentAsset) return
                
                // Check if version changed (final thumbnail became available)
                const currentVersion = currentAsset.thumbnail_version
                const newVersion = updatedAsset.thumbnail_version
                const versionChanged = currentVersion !== newVersion
                
                // Check if final thumbnail is now available
                const finalNowAvailable = !!updatedAsset.final_thumbnail_url && !currentAsset.final_thumbnail_url
                
                // Check if preview thumbnail is now available
                const previewNowAvailable = !!updatedAsset.preview_thumbnail_url && !currentAsset.preview_thumbnail_url
                
                // Check if asset failed (status changed to failed OR error exists)
                const statusFailed = updatedAsset.thumbnail_status === 'failed'
                const hasError = !!updatedAsset.thumbnail_error
                const isFailed = statusFailed || hasError
                
                // Check if any thumbnail-related field changed
                const thumbnailStatusChanged = updatedAsset.thumbnail_status !== currentAsset.thumbnail_status
                const previewUrlChanged = updatedAsset.preview_thumbnail_url !== currentAsset.preview_thumbnail_url
                const finalUrlChanged = updatedAsset.final_thumbnail_url !== currentAsset.final_thumbnail_url
                const errorChanged = updatedAsset.thumbnail_error !== currentAsset.thumbnail_error
                const modeUrlsChanged =
                    JSON.stringify(updatedAsset.thumbnail_mode_urls ?? null) !==
                    JSON.stringify(currentAsset.thumbnail_mode_urls ?? null)
                const modesMetaChanged =
                    JSON.stringify(updatedAsset.thumbnail_modes_meta ?? null) !==
                    JSON.stringify(currentAsset.thumbnail_modes_meta ?? null)
                const modesStatusChanged =
                    JSON.stringify(updatedAsset.thumbnail_modes_status ?? null) !==
                    JSON.stringify(currentAsset.thumbnail_modes_status ?? null)

                // Only update if something actually changed (prevent unnecessary re-renders)
                if (
                    versionChanged ||
                    finalNowAvailable ||
                    previewNowAvailable ||
                    isFailed ||
                    thumbnailStatusChanged ||
                    previewUrlChanged ||
                    finalUrlChanged ||
                    errorChanged ||
                    modeUrlsChanged ||
                    modesMetaChanged ||
                    modesStatusChanged
                ) {
                    hasUpdates = true
                    
                    // Map updatedAsset to match currentAsset structure
                    const mappedUpdatedAsset = {
                        ...updatedAsset,
                        id: updatedAsset.asset_id || updatedAsset.id,
                        preview_thumbnail_url: updatedAsset.preview_thumbnail_url,
                        final_thumbnail_url: updatedAsset.final_thumbnail_url,
                        thumbnail_status: updatedAsset.thumbnail_status,
                        thumbnail_version: updatedAsset.thumbnail_version,
                        thumbnail_error: updatedAsset.thumbnail_error,
                    }
                    
                    // Use mergeAsset() to ensure thumbnail fields are authoritative from updatedAsset
                    const updatedAssetData = mergeAsset(currentAsset, mappedUpdatedAsset)
                    
                    // If asset failed, stop polling for it (remove from poll targets)
                    if (isFailed) {
                        // Asset failed - will stop polling (removed from poll targets)
                    }
                    
                    // Notify parent component
                    if (onAssetUpdateRef.current) {
                        onAssetUpdateRef.current(updatedAssetData)
                    }
                }
            })
            
            if (hasUpdates) {
                // Updates applied - continue polling for remaining assets
            }
            
            // Schedule next poll with exponential backoff
            pollAttemptRef.current += 1
            
            // Re-check poll targets after update (some may have been removed)
            const remainingTargets = getPollTargets()
            
            if (remainingTargets.length === 0) {
                isActiveRef.current = false
                pollAttemptRef.current = 0
                return
            }
            
            if (pollAttemptRef.current < MAX_POLL_ATTEMPTS) {
                const nextDelay = POLL_SCHEDULE[pollAttemptRef.current - 1]
                timeoutIdRef.current = setTimeout(() => {
                    performPoll()
                }, nextDelay)
            } else {
                isActiveRef.current = false
                pollAttemptRef.current = 0
            }
        } catch (error) {
            console.error('[useThumbnailSmartPoll] Poll error', {
                error: error.message,
                response: error.response?.data,
            })
            
            // On error, stop polling (don't retry forever)
            isActiveRef.current = false
            pollAttemptRef.current = 0
        }
    }

    // Main polling effect
    useEffect(() => {
        if (isPaused) {
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            isActiveRef.current = false
            pollAttemptRef.current = 0
            return undefined
        }

        // Reset on category change
        if (prevCategoryIdRef.current !== selectedCategoryId) {
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            isActiveRef.current = false
            pollAttemptRef.current = 0
            prevCategoryIdRef.current = selectedCategoryId
        }
        
        const pollTargets = getPollTargets()
        
        // Start polling if we have targets and aren't already polling
        if (pollTargets.length > 0 && !isActiveRef.current) {
            isActiveRef.current = true
            pollAttemptRef.current = 0
            
            // Start first poll immediately
            performPoll()
        } else if (pollTargets.length === 0 && isActiveRef.current) {
            // Stop if no targets remain
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            isActiveRef.current = false
            pollAttemptRef.current = 0
        }
        
        // Cleanup on unmount or dependency change
        return () => {
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            isActiveRef.current = false
            pollAttemptRef.current = 0
        }
    }, [assets, selectedCategoryId, isPaused]) // Note: onAssetUpdate is stable via useCallback in parent
}
