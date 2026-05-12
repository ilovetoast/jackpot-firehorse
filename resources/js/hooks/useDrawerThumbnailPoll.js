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
 * - After one refresh when final thumbnail is confirmed (so full thumbnail_mode_urls can load), or on terminal error
 * - Thumbnail error exists (status is 'failed' or 'skipped')
 * 
 * @param {Object} options
 * @param {Object|null} options.asset - Asset object (null when drawer is closed)
 * @param {Function} options.onAssetUpdate - Callback when asset thumbnail updates (receives updated asset)
 * @returns {Object} - { drawerAsset: updated asset for drawer display }
 */
import { useEffect, useRef, useState } from 'react'
import { getThumbnailState, supportsThumbnail } from '../utils/thumbnailUtils'
import { mergeThumbnailModeUrlsDrawerSync, mergeThumbnailModeUrlsPreserveCache } from '../utils/thumbnailModes'

// Poll schedule: 2s → 3s → 5s → 10s → 15s → stop
const POLL_SCHEDULE = [2000, 3000, 5000, 10000, 15000] // milliseconds
const MAX_POLL_ATTEMPTS = POLL_SCHEDULE.length

/** Studio / AI (presentation) jobs: main thumbnail can already be complete while these run */
const MODE_PIPELINE_POLL_MS = 3000
const MODE_PIPELINE_MAX_POLLS = 80

/**
 * @param {Object|null|undefined} a
 * @returns {boolean}
 */
function isPerModePipelineProcessing(a) {
    if (!a) {
        return false
    }
    const top = a.thumbnail_modes_status && typeof a.thumbnail_modes_status === 'object' ? a.thumbnail_modes_status : {}
    const nested =
        a.metadata?.thumbnail_modes_status && typeof a.metadata.thumbnail_modes_status === 'object'
            ? a.metadata.thumbnail_modes_status
            : {}
    const pick = (k) => String(top[k] ?? nested[k] ?? '').toLowerCase()
    return pick('enhanced') === 'processing' || pick('presentation') === 'processing'
}

/**
 * Re-start polling when a mode enters processing (optimistic UI after queueing Studio / AI).
 * @param {Object|null|undefined} asset
 */
function modePipelineWatchKey(asset) {
    if (!asset?.id) {
        return ''
    }
    return `${asset.id}:${isPerModePipelineProcessing(asset) ? 'modes-busy' : 'modes-idle'}`
}

export function useDrawerThumbnailPoll({ asset, onAssetUpdate, pollEnabled = true }) {
    const timeoutIdRef = useRef(null)
    const pollAttemptRef = useRef(0)
    const modePipelinePollsRef = useRef(0)
    const assetIdRef = useRef(asset?.id)
    const assetRef = useRef(asset)
    const onAssetUpdateRef = useRef(onAssetUpdate)
    const pollEnabledRef = useRef(pollEnabled)
    const [drawerAsset, setDrawerAsset] = useState(asset)

    // Update refs when props change
    useEffect(() => {
        assetRef.current = asset
        onAssetUpdateRef.current = onAssetUpdate
        pollEnabledRef.current = pollEnabled
    }, [asset, onAssetUpdate, pollEnabled])

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
                const prevStatus =
                    prevDrawerAsset?.thumbnail_modes_status &&
                    typeof prevDrawerAsset.thumbnail_modes_status === 'object'
                        ? prevDrawerAsset.thumbnail_modes_status
                        : {}
                const nextStatus =
                    asset.thumbnail_modes_status && typeof asset.thumbnail_modes_status === 'object'
                        ? asset.thumbnail_modes_status
                        : {}
                const prevMeta =
                    prevDrawerAsset?.thumbnail_modes_meta &&
                    typeof prevDrawerAsset.thumbnail_modes_meta === 'object'
                        ? prevDrawerAsset.thumbnail_modes_meta
                        : {}
                const nextMeta =
                    asset.thumbnail_modes_meta && typeof asset.thumbnail_modes_meta === 'object'
                        ? asset.thumbnail_modes_meta
                        : {}
                return {
                    ...asset, // Grid state is source of truth
                    // Only use polling updates if grid doesn't have them yet
                    final_thumbnail_url: asset.final_thumbnail_url || prevDrawerAsset?.final_thumbnail_url,
                    preview_thumbnail_url: asset.preview_thumbnail_url || prevDrawerAsset?.preview_thumbnail_url,
                    thumbnail_version: asset.thumbnail_version || prevDrawerAsset?.thumbnail_version,
                    thumbnail_mode_urls: mergeThumbnailModeUrlsDrawerSync(
                        asset.thumbnail_mode_urls,
                        prevDrawerAsset?.thumbnail_mode_urls,
                    ),
                    thumbnail_modes_status: { ...prevStatus, ...nextStatus },
                    thumbnail_modes_meta: { ...prevMeta, ...nextMeta },
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
        if (!asset || !asset.id || asset.is_virtual_google_font) {
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
            modePipelinePollsRef.current = 0
            assetIdRef.current = asset.id
        }
    }, [asset?.id])

    // Perform single asset thumbnail status check
    const performPoll = async () => {
        if (!pollEnabledRef.current) {
            return
        }
        const currentAsset = assetRef.current
        if (!currentAsset || !currentAsset.id || currentAsset.is_virtual_google_font) {
            return
        }

        // Check if we should stop polling
        const thumbnailStatus = currentAsset.thumbnail_status?.value || currentAsset.thumbnail_status
        const hasFinal = !!currentAsset.final_thumbnail_url
        const hasPreview = !!currentAsset.preview_thumbnail_url
        const isFailed = thumbnailStatus === 'failed'
        const isSkipped = thumbnailStatus === 'skipped'
        const hasError = !!currentAsset.thumbnail_error
        const isPending = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'

        // Stop conditions (completed+final still runs one batch fetch below for full thumbnail_mode_urls)
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
                const analysisStatusChanged =
                    String(updatedAssetData.analysis_status ?? '') !==
                    String(currentAsset.analysis_status ?? '')
                const errorChanged = updatedAssetData.thumbnail_error !== currentAsset.thumbnail_error
                const nextModesMeta = updatedAssetData.thumbnail_modes_meta
                const mergedModeUrls = mergeThumbnailModeUrlsPreserveCache(
                    currentAsset.thumbnail_mode_urls,
                    updatedAssetData.thumbnail_mode_urls,
                    currentAsset.thumbnail_modes_meta,
                    nextModesMeta,
                )
                const modeUrlsEffectivelyChanged =
                    JSON.stringify(mergedModeUrls ?? null) !==
                    JSON.stringify(currentAsset.thumbnail_mode_urls ?? null)
                const modeMetaChanged =
                    JSON.stringify(nextModesMeta ?? null) !==
                    JSON.stringify(currentAsset.thumbnail_modes_meta ?? null)
                const modesStatusChanged =
                    JSON.stringify(updatedAssetData.thumbnail_modes_status ?? null) !==
                    JSON.stringify(currentAsset.thumbnail_modes_status ?? null)

                if (
                    versionChanged ||
                    finalNowAvailable ||
                    previewNowAvailable ||
                    statusChanged ||
                    analysisStatusChanged ||
                    errorChanged ||
                    modeUrlsEffectivelyChanged ||
                    modeMetaChanged ||
                    modesStatusChanged
                ) {
                    const nextModesStatus =
                        updatedAssetData.thumbnail_modes_status ?? currentAsset.thumbnail_modes_status
                    // Merge updated data into current asset
                    // Note: updatedAssetData has asset_id, but we keep id from currentAsset
                    const updatedAsset = {
                        ...currentAsset,
                        analysis_status: updatedAssetData.analysis_status ?? currentAsset.analysis_status,
                        preview_thumbnail_url: updatedAssetData.preview_thumbnail_url ?? currentAsset.preview_thumbnail_url,
                        final_thumbnail_url: updatedAssetData.final_thumbnail_url ?? currentAsset.final_thumbnail_url,
                        thumbnail_status: updatedAssetData.thumbnail_status ?? currentAsset.thumbnail_status,
                        thumbnail_version: updatedAssetData.thumbnail_version ?? currentAsset.thumbnail_version,
                        thumbnail_error: updatedAssetData.thumbnail_error ?? currentAsset.thumbnail_error,
                        thumbnail_mode_urls: mergedModeUrls ?? updatedAssetData.thumbnail_mode_urls ?? currentAsset.thumbnail_mode_urls,
                        thumbnail_modes_meta: nextModesMeta ?? currentAsset.thumbnail_modes_meta,
                        thumbnail_modes_status: nextModesStatus,
                        metadata: {
                            ...(currentAsset.metadata && typeof currentAsset.metadata === 'object'
                                ? currentAsset.metadata
                                : {}),
                            ...(nextModesStatus && typeof nextModesStatus === 'object'
                                ? { thumbnail_modes_status: nextModesStatus }
                                : {}),
                        },
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

        const a = assetRef.current
        const stAfter = a.thumbnail_status?.value || a.thumbnail_status
        const hasFinalAfter = !!a.final_thumbnail_url
        const modeBusy = isPerModePipelineProcessing(a)

        if (stAfter === 'failed' || stAfter === 'skipped' || !!a.thumbnail_error) {
            return
        }

        // Main raster pipeline can be done while Studio / AI jobs still run — keep polling until modes settle.
        if (stAfter === 'completed' && hasFinalAfter && !modeBusy) {
            return
        }

        if (modeBusy) {
            modePipelinePollsRef.current += 1
            if (modePipelinePollsRef.current > MODE_PIPELINE_MAX_POLLS) {
                return
            }
            timeoutIdRef.current = setTimeout(() => {
                performPoll()
            }, MODE_PIPELINE_POLL_MS)
            return
        }

        modePipelinePollsRef.current = 0

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
        if (!pollEnabled) {
            if (timeoutIdRef.current) {
                clearTimeout(timeoutIdRef.current)
                timeoutIdRef.current = null
            }
            pollAttemptRef.current = 0
            modePipelinePollsRef.current = 0
            return
        }
        const currentAsset = assetRef.current
        if (!currentAsset || !currentAsset.id || currentAsset.is_virtual_google_font) {
            return
        }

        // Reset polling state for new asset
        pollAttemptRef.current = 0
        modePipelinePollsRef.current = 0

        // Check if we should even start polling
        const thumbnailStatus = currentAsset.thumbnail_status?.value || currentAsset.thumbnail_status
        const hasFinal = !!currentAsset.final_thumbnail_url
        const hasPreview = !!currentAsset.preview_thumbnail_url
        const isFailed = thumbnailStatus === 'failed'
        const isSkipped = thumbnailStatus === 'skipped'
        const hasError = !!currentAsset.thumbnail_error
        const isPending = thumbnailStatus === 'pending' || thumbnailStatus === 'processing'
        const modeBusyStart = isPerModePipelineProcessing(currentAsset)

        // Don't poll if terminal error state
        if (isFailed || isSkipped || hasError) {
            return
        }

        // Studio / AI queued while main thumbnails already exist — must poll batch status
        if (modeBusyStart) {
            performPoll()
            return () => {
                if (timeoutIdRef.current) {
                    clearTimeout(timeoutIdRef.current)
                    timeoutIdRef.current = null
                }
                pollAttemptRef.current = 0
                modePipelinePollsRef.current = 0
            }
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
            modePipelinePollsRef.current = 0
        }
    }, [asset?.id, pollEnabled, modePipelineWatchKey(asset)])

    return {
        drawerAsset: drawerAsset || asset, // Fallback to prop if state not initialized
    }
}
