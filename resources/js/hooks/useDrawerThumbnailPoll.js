/**
 * useDrawerThumbnailPoll Hook
 * 
 * Drawer-scoped live polling for a single asset's thumbnail status.
 * 
 * STRICT SEPARATION: This hook is ONLY for drawer context.
 * It must NEVER mutate grid asset state.
 * 
 * When the drawer opens:
 * - Poll thumbnail status for the active asset only
 * - If preview_thumbnail_url exists, show it immediately
 * - If final_thumbnail_url becomes available, swap preview → final cleanly
 * 
 * Polling stops immediately when:
 * - Drawer closes (asset becomes null)
 * - Asset changes (asset.id changes)
 * - Final thumbnail is confirmed (final_thumbnail_url exists and status is 'completed')
 * - Thumbnail error exists (status is 'failed' or 'skipped')
 * 
 * @param {Object} options
 * @param {Object|null} options.asset - Asset object (null when drawer is closed)
 * @param {Function} options.onAssetUpdate - Callback when asset thumbnail updates (receives updated asset)
 * @returns {Object} - { drawerAsset: updated asset for drawer display }
 */
import { useEffect, useRef, useState } from 'react'
import { getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'

// Poll schedule: 2s → 3s → 5s → 10s → 15s → stop
const POLL_SCHEDULE = [2000, 3000, 5000, 10000, 15000] // milliseconds
const MAX_POLL_ATTEMPTS = POLL_SCHEDULE.length

export function useDrawerThumbnailPoll({ asset, onAssetUpdate }) {
    const timeoutIdRef = useRef(null)
    const pollAttemptRef = useRef(0)
    const assetIdRef = useRef(asset?.id)
    const assetRef = useRef(asset)
    const onAssetUpdateRef = useRef(onAssetUpdate)
    const [drawerAsset, setDrawerAsset] = useState(asset)

    // Update refs when props change
    useEffect(() => {
        assetRef.current = asset
        onAssetUpdateRef.current = onAssetUpdate
    }, [asset, onAssetUpdate])

    // Update drawer asset when prop changes (sync grid updates to drawer)
    // CRITICAL: Grid owns asset state - prop is source of truth
    // Sync when asset prop changes (not just ID) to reflect grid state updates
    // This ensures drawer displays latest asset data from grid (thumbnail updates, lifecycle changes, etc.)
    useEffect(() => {
        if (asset) {
            setDrawerAsset(prevDrawerAsset => {
                // If asset ID changed, use new asset
                if (!prevDrawerAsset || prevDrawerAsset.id !== asset.id) {
                    return asset
                }
                // Same asset ID - grid state (prop) is source of truth
                // Use prop values, but allow polling to add thumbnail URLs if missing
                // Grid state updates (from handleThumbnailUpdate/handleLifecycleUpdate) take precedence
                return {
                    ...asset, // Grid state is source of truth
                    // Only use polling updates if grid doesn't have them yet
                    final_thumbnail_url: asset.final_thumbnail_url || prevDrawerAsset?.final_thumbnail_url,
                    preview_thumbnail_url: asset.preview_thumbnail_url || prevDrawerAsset?.preview_thumbnail_url,
                    thumbnail_version: asset.thumbnail_version || prevDrawerAsset?.thumbnail_version,
                }
            })
            assetIdRef.current = asset.id
        } else {
            // Drawer closed - clear state
            setDrawerAsset(null)
            assetIdRef.current = null
        }
    }, [asset])

    // Clear polling when asset changes or drawer closes
    useEffect(() => {
        if (!asset || !asset.id) {
            // Drawer closed - stop polling
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            pollAttemptRef.current = 0
            return
        }

        // Asset changed - reset polling
        if (assetIdRef.current !== asset.id) {
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            pollAttemptRef.current = 0
            assetIdRef.current = asset.id
        }
    }, [asset?.id])

    // Perform single asset thumbnail status check
    const performPoll = async () => {
        const currentAsset = assetRef.current
        if (!currentAsset || !currentAsset.id) {
            return
        }

        // Check if we should stop polling
        const thumbnailStatus = currentAsset.thumbnail_status?.value || currentAsset.thumbnail_status
        const hasFinal = !!currentAsset.final_thumbnail_url
        const hasPreview = !!currentAsset.preview_thumbnail_url
        const isCompleted = thumbnailStatus === 'completed'
        const isFailed = thumbnailStatus === 'failed'
        const isSkipped = thumbnailStatus === 'skipped'
        const hasError = !!currentAsset.thumbnail_error
        const isPending = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'

        // Stop conditions
        if (isCompleted && hasFinal) {
            // Final thumbnail confirmed - stop polling
            return
        }

        if (isFailed || isSkipped || hasError) {
            // Terminal error state - stop polling
            return
        }

        // Stop polling if status is pending but no preview or final URL exists
        // This prevents infinite polling for assets that just had files replaced
        // and thumbnails haven't been generated yet
        if (isPending && !hasPreview && !hasFinal) {
            // Only poll if we haven't exceeded max attempts (allows initial check)
            // After first few attempts, stop if no thumbnails are available
            if (pollAttemptRef.current >= 2) {
                // Stop polling after 2 attempts if no thumbnails are available
                // This prevents infinite loops for replaced files waiting for thumbnail generation
                return
            }
        }

        // Check if asset supports thumbnails
        const { state } = getThumbnailState(currentAsset, 0)
        if (state === 'NOT_SUPPORTED') {
            // Unsupported format - stop polling
            return
        }

        // Poll backend for this single asset
        try {
            const response = await window.axios.get('/app/assets/thumbnail-status/batch', {
                params: {
                    asset_ids: [currentAsset.id].join(','),
                },
            })

            // Response format: { assets: [...] }
            // Each asset has asset_id, not id
            const assets = response.data?.assets || []
            if (assets.length > 0) {
                const updatedAssetData = assets[0]
                
                // Verify this is the correct asset (safety check)
                if (updatedAssetData.asset_id !== currentAsset.id) {
                    console.warn('[useDrawerThumbnailPoll] Asset ID mismatch', {
                        expected: currentAsset.id,
                        received: updatedAssetData.asset_id,
                    })
                    return
                }

                // Check if anything meaningful changed
                const versionChanged = updatedAssetData.thumbnail_version !== currentAsset.thumbnail_version
                const finalNowAvailable = !!updatedAssetData.final_thumbnail_url && !currentAsset.final_thumbnail_url
                const previewNowAvailable = !!updatedAssetData.preview_thumbnail_url && !currentAsset.preview_thumbnail_url
                const statusChanged = updatedAssetData.thumbnail_status !== thumbnailStatus
                const errorChanged = updatedAssetData.thumbnail_error !== currentAsset.thumbnail_error

                if (versionChanged || finalNowAvailable || previewNowAvailable || statusChanged || errorChanged) {
                    // Merge updated data into current asset
                    // Note: updatedAssetData has asset_id, but we keep id from currentAsset
                    const updatedAsset = {
                        ...currentAsset,
                        preview_thumbnail_url: updatedAssetData.preview_thumbnail_url ?? currentAsset.preview_thumbnail_url,
                        final_thumbnail_url: updatedAssetData.final_thumbnail_url ?? currentAsset.final_thumbnail_url,
                        thumbnail_status: updatedAssetData.thumbnail_status ?? currentAsset.thumbnail_status,
                        thumbnail_version: updatedAssetData.thumbnail_version ?? currentAsset.thumbnail_version,
                        thumbnail_error: updatedAssetData.thumbnail_error ?? currentAsset.thumbnail_error,
                    }

                    // Update ref for next poll
                    assetRef.current = updatedAsset

                    // Update drawer-local state
                    setDrawerAsset(updatedAsset)

                    // Notify parent (drawer) of update
                    if (onAssetUpdateRef.current) {
                        onAssetUpdateRef.current(updatedAsset)
                    }
                }
            }
        } catch (error) {
            console.error('[useDrawerThumbnailPoll] Poll error:', error)
            // Stop polling on error
            return
        }

        // Schedule next poll if we haven't exceeded max attempts
        if (pollAttemptRef.current < MAX_POLL_ATTEMPTS) {
            const delay = POLL_SCHEDULE[pollAttemptRef.current] || POLL_SCHEDULE[POLL_SCHEDULE.length - 1]
            pollAttemptRef.current += 1

            timeoutIdRef.current = setTimeout(() => {
                performPoll()
            }, delay)
        }
    }

    // Start polling when drawer opens with an asset
    useEffect(() => {
        const currentAsset = assetRef.current
        if (!currentAsset || !currentAsset.id) {
            return
        }

        // Reset polling state for new asset
        pollAttemptRef.current = 0

        // Check if we should even start polling
        const thumbnailStatus = currentAsset.thumbnail_status?.value || currentAsset.thumbnail_status
        const hasFinal = !!currentAsset.final_thumbnail_url
        const hasPreview = !!currentAsset.preview_thumbnail_url
        const isCompleted = thumbnailStatus === 'completed'
        const isFailed = thumbnailStatus === 'failed'
        const isSkipped = thumbnailStatus === 'skipped'
        const hasError = !!currentAsset.thumbnail_error
        const isPending = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'

        // Don't poll if already completed with final
        if (isCompleted && hasFinal) {
            return
        }

        // Don't poll if terminal error state
        if (isFailed || isSkipped || hasError) {
            return
        }

        // Don't start polling if status is pending but no preview or final URL exists
        // This prevents starting polls for assets that just had files replaced
        // and thumbnails haven't been generated yet (would cause 404 loops)
        if (isPending && !hasPreview && !hasFinal) {
            // Only start polling if we expect thumbnails to be generated soon
            // For file replacements, thumbnails may take time to generate
            // We'll let the asset reconciliation handle checking for new thumbnails
            return
        }

        // Check if asset supports thumbnails
        const { state } = getThumbnailState(currentAsset, 0)
        if (state === 'NOT_SUPPORTED') {
            return
        }

        // Start polling immediately
        performPoll()

        // Cleanup on unmount or asset change
        return () => {
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            pollAttemptRef.current = 0
        }
    }, [asset?.id])

    return {
        drawerAsset: drawerAsset || asset, // Fallback to prop if state not initialized
    }
}
