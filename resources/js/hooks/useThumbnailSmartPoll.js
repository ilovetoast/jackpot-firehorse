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
 * - Uses exponential backoff: 10s → 15s → 30s → 60s → 60s → stop
 * - Max duration ~3-4 minutes
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
 * @returns {void} - Hook manages polling internally
 */
import { useEffect, useRef } from 'react'
import { getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'
import { mergeAsset } from '../utils/assetUtils'

// Exponential backoff schedule: 10s → 15s → 30s → 60s → 60s → stop
const POLL_SCHEDULE = [10000, 15000, 30000, 60000, 60000] // milliseconds
const MAX_POLL_ATTEMPTS = POLL_SCHEDULE.length

/**
 * HARD STABILIZATION: Thumbnail polling is disabled to prevent flashing and visual instability.
 * 
 * NOTE: Thumbnails intentionally do NOT live-update on the grid.
 * Stability > real-time updates.
 * 
 * Live thumbnail upgrades can be reintroduced later via explicit user action (refresh / reopen page).
 */
export function useThumbnailSmartPoll({ assets, onAssetUpdate, selectedCategoryId = null }) {
    // HARD STABILIZATION: Disable polling entirely
    // No polling. No background updates. No thrashing.
    return

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
        
        return currentAssets.filter(asset => {
            if (!asset || !asset.id) return false
            
            // Must NOT have final thumbnail URL (if it exists, no need to poll)
            if (asset.final_thumbnail_url) return false
            
            // Must NOT be unsupported format
            const { state } = getThumbnailState(asset)
            if (state === 'NOT_SUPPORTED') return false
            
            // Must NOT have thumbnail error (failed assets don't poll)
            if (asset.thumbnail_error) return false
            
            // Must NOT be failed status (failed assets don't poll)
            const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status
            if (thumbnailStatus === 'failed') return false
            
            // Must NOT be skipped status (skipped assets don't poll)
            if (thumbnailStatus === 'skipped') return false
            
            // Must be pending OR processing (or null status for legacy)
            const isPendingOrProcessing = thumbnailStatus === 'pending' || thumbnailStatus === 'processing' || !thumbnailStatus
            if (!isPendingOrProcessing) return false
            
            // Must support thumbnails (expected to have thumbnails)
            const thumbnailExpected = supportsThumbnail(asset?.mime_type, asset?.file_extension)
            if (!thumbnailExpected) return false
            
            // Poll for all assets that meet the above criteria
            // Do NOT depend on preview_thumbnail_url existence
            return true
        })
    }

    // Perform a single poll
    const performPoll = async () => {
        const pollTargets = getPollTargets()
        
        // Stop if no targets
        if (pollTargets.length === 0) {
            console.log('[useThumbnailSmartPoll] Stopping - no poll targets')
            isActiveRef.current = false
            pollAttemptRef.current = 0
            return
        }
        
        // Check if category changed
        if (prevCategoryIdRef.current !== selectedCategoryId) {
            console.log('[useThumbnailSmartPoll] Stopping - category changed', {
                previous: prevCategoryIdRef.current,
                current: selectedCategoryId,
            })
            isActiveRef.current = false
            pollAttemptRef.current = 0
            prevCategoryIdRef.current = selectedCategoryId
            return
        }
        
        // Check if we've exceeded max attempts
        if (pollAttemptRef.current >= MAX_POLL_ATTEMPTS) {
            console.log('[useThumbnailSmartPoll] Stopping - max attempts reached', {
                attempts: pollAttemptRef.current,
                max: MAX_POLL_ATTEMPTS,
            })
            isActiveRef.current = false
            pollAttemptRef.current = 0
            return
        }
        
        try {
            const assetIds = pollTargets.map(asset => asset.id)
            
            console.log('[useThumbnailSmartPoll] Polling', {
                attempt: pollAttemptRef.current + 1,
                assetCount: assetIds.length,
                assetIds: assetIds.slice(0, 5), // Log first 5 for debugging
            })
            
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
                
                // Only update if something actually changed (prevent unnecessary re-renders)
                if (versionChanged || finalNowAvailable || previewNowAvailable || isFailed || 
                    thumbnailStatusChanged || previewUrlChanged || finalUrlChanged || errorChanged) {
                    console.log('[useThumbnailSmartPoll] Status change detected', {
                        assetId,
                        currentVersion,
                        newVersion,
                        finalNowAvailable,
                        previewNowAvailable,
                        isFailed,
                        statusFailed,
                        hasError,
                        thumbnailStatusChanged,
                        previewUrlChanged,
                        finalUrlChanged,
                    })
                    
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
                        console.log('[useThumbnailSmartPoll] Asset failed, will stop polling', {
                            assetId,
                            error: updatedAsset.thumbnail_error,
                        })
                    }
                    
                    // Notify parent component
                    if (onAssetUpdateRef.current) {
                        onAssetUpdateRef.current(updatedAssetData)
                    }
                }
            })
            
            if (hasUpdates) {
                console.log('[useThumbnailSmartPoll] Updates applied, continuing to poll for remaining assets')
            }
            
            // Schedule next poll with exponential backoff
            pollAttemptRef.current += 1
            
            // Re-check poll targets after update (some may have been removed)
            const remainingTargets = getPollTargets()
            
            if (remainingTargets.length === 0) {
                console.log('[useThumbnailSmartPoll] Stopping - no remaining targets after update')
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
                console.log('[useThumbnailSmartPoll] Stopping - max attempts reached')
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
            console.log('[useThumbnailSmartPoll] Starting', {
                targetCount: pollTargets.length,
                assetIds: pollTargets.map(a => a.id).slice(0, 5),
            })
            
            isActiveRef.current = true
            pollAttemptRef.current = 0
            
            // Start first poll immediately
            performPoll()
        } else if (pollTargets.length === 0 && isActiveRef.current) {
            // Stop if no targets remain
            console.log('[useThumbnailSmartPoll] Stopping - no poll targets')
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
    }, [assets, selectedCategoryId]) // Note: onAssetUpdate is stable via useCallback in parent
}
