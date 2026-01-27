/**
 * useThumbnailPolling Hook
 * 
 * Safe, targeted polling for assets waiting for thumbnail generation.
 * 
 * Rules:
 * - Only polls assets that need thumbnails (not completed, not error, no thumbnail_url)
 * - Stops polling immediately when asset completes or errors
 * - Never touches completed or errored assets
 * - Uses mergeAsset to safely update state
 * - Cleans up timers properly
 * 
 * @param {Array} assets - Current assets array
 * @param {Function} onThumbnailUpdate - Callback when thumbnail status updates (receives updated asset)
 * @returns {void} - Hook manages state internally via callback
 */
import { useEffect, useRef } from 'react'
import { mergeAsset, warnIfOverwritingCompletedThumbnail } from '../utils/assetUtils'

export function useThumbnailPolling(assets, onThumbnailUpdate) {
    const intervalIdsRef = useRef(new Map()) // Map<assetId, intervalId>
    const assetsRef = useRef(assets)
    const onThumbnailUpdateRef = useRef(onThumbnailUpdate)

    // Keep refs in sync
    useEffect(() => {
        assetsRef.current = assets
    }, [assets])

    useEffect(() => {
        onThumbnailUpdateRef.current = onThumbnailUpdate
    }, [onThumbnailUpdate])

    useEffect(() => {
        // Qualification filter: Only poll assets that need thumbnails
        const assetsNeedingThumbnails = assetsRef.current.filter(asset => {
            if (!asset || !asset.id) return false

            const thumbnailStatus = asset.thumbnail_status?.value || asset.thumbnail_status || 'pending'
            const hasThumbnail = !!(asset.thumbnail_url || asset.preview_url || asset.preview_thumbnail_url || asset.final_thumbnail_url)
            const isPending = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'

            // Don't poll if status is pending AND no thumbnails exist (prevents 404 loops)
            // This prevents polling for assets that just had files replaced and thumbnails
            // haven't been generated yet (would cause thousands of 404 errors)
            if (isPending && !hasThumbnail) {
                return false // Skip polling for pending assets without any thumbnails
            }

            // Qualify only if:
            // 1. Not completed
            // 2. Not error
            // 3. No thumbnail URL exists
            return (
                thumbnailStatus !== 'completed' &&
                thumbnailStatus !== 'error' &&
                thumbnailStatus !== 'failed' &&
                !hasThumbnail
            )
        })

        // If no assets need polling, do nothing
        if (assetsNeedingThumbnails.length === 0) {
            // Clean up any existing intervals for assets that no longer need polling
            intervalIdsRef.current.forEach((intervalId, assetId) => {
                clearInterval(intervalId)
                intervalIdsRef.current.delete(assetId)
            })
            return
        }

        // Get current asset IDs that need polling
        const assetIdsNeedingPolling = new Set(assetsNeedingThumbnails.map(a => a.id))

        // Clean up intervals for assets that no longer need polling
        intervalIdsRef.current.forEach((intervalId, assetId) => {
            if (!assetIdsNeedingPolling.has(assetId)) {
                clearInterval(intervalId)
                intervalIdsRef.current.delete(assetId)
            }
        })

        // Set up polling for each qualifying asset
        assetsNeedingThumbnails.forEach(asset => {
            const assetId = asset.id

            // Skip if already polling this asset
            if (intervalIdsRef.current.has(assetId)) {
                return
            }

            // Set up polling interval for this asset
            const intervalId = setInterval(async () => {
                try {
                    // Use ref to get latest assets (avoid stale closure)
                    const currentAssets = assetsRef.current
                    const currentAsset = currentAssets.find(a => a.id === assetId)
                    if (!currentAsset) {
                        // Asset no longer exists, stop polling
                        clearInterval(intervalId)
                        intervalIdsRef.current.delete(assetId)
                        return
                    }

                    const currentThumbnailStatus = currentAsset.thumbnail_status?.value || currentAsset.thumbnail_status || 'pending'
                    const currentHasThumbnail = !!(currentAsset.thumbnail_url || currentAsset.preview_url)

                    // Stop polling if asset is now completed, errored, or has thumbnail
                    if (
                        currentThumbnailStatus === 'completed' ||
                        currentThumbnailStatus === 'error' ||
                        currentThumbnailStatus === 'failed' ||
                        currentHasThumbnail
                    ) {
                        clearInterval(intervalId)
                        intervalIdsRef.current.delete(assetId)
                        return
                    }

                    // Fetch latest status
                    const response = await window.axios.get(`/app/assets/${assetId}/processing-status`)
                    const { thumbnail_status, thumbnail_url, thumbnails_generated_at } = response.data

                    // Stop polling if status is now 'completed' or 'error'
                    if (
                        thumbnail_status === 'completed' ||
                        thumbnail_status === 'error' ||
                        thumbnail_status === 'failed'
                    ) {
                        clearInterval(intervalId)
                        intervalIdsRef.current.delete(assetId)
                    }

                    // Get latest asset again (may have changed during async operation)
                    const latestAsset = assetsRef.current.find(a => a.id === assetId)
                    if (!latestAsset) {
                        // Asset removed during async operation, skip update
                        return
                    }

                    // Update asset via mergeAsset to safely handle state updates
                    const updatedAsset = {
                        ...latestAsset,
                        thumbnail_status: thumbnail_status,
                        thumbnail_url: thumbnail_url || latestAsset.thumbnail_url, // Preserve existing if response URL is falsy
                        metadata: {
                            ...latestAsset.metadata,
                            ...(thumbnails_generated_at ? { thumbnails_generated_at } : {}),
                        },
                    }

                    // Dev warning if attempting to overwrite completed thumbnail
                    warnIfOverwritingCompletedThumbnail(latestAsset, updatedAsset, 'thumbnail-polling')

                    // Use mergeAsset to safely merge (protects completed thumbnails, allows first hydration)
                    const mergedAsset = mergeAsset(latestAsset, updatedAsset)

                    // Notify parent component of update
                    if (onThumbnailUpdateRef.current) {
                        onThumbnailUpdateRef.current(mergedAsset)
                    }
                } catch (error) {
                    // Silently fail - don't spam console with errors
                    // Asset may have been deleted or endpoint may be temporarily unavailable
                    console.debug('[useThumbnailPolling] Polling error for asset', assetId, error)

                    // On error, check if we should stop polling (e.g., 404 = asset deleted)
                    if (error.response?.status === 404) {
                        clearInterval(intervalId)
                        intervalIdsRef.current.delete(assetId)
                    }
                }
            }, 3000) // 3 second interval (conservative)

            intervalIdsRef.current.set(assetId, intervalId)
        })

        // Cleanup: clear all intervals on unmount or when assets change
        return () => {
            intervalIdsRef.current.forEach((intervalId) => {
                clearInterval(intervalId)
            })
            intervalIdsRef.current.clear()
        }
    }, [assets]) // Only depend on assets, not onThumbnailUpdate (handled via ref)
}
