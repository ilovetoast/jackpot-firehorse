/**
 * Phase 3.1: Background Asset Reconciliation Hook
 * 
 * Bounded, non-invasive background reconciliation loop for asset thumbnails and processing state.
 * 
 * This is NOT a live subscription. It's a quiet, page-level refresh loop that:
 * - Only runs when at least one visible asset is processing
 * - Uses router.reload() to refresh the asset grid
 * - Auto-stops when no assets are processing, max attempts reached, or component unmounts
 * - Never polls forever or adds subscriptions
 * - Pauses when isPaused === true (e.g., when upload dialog is open)
 * 
 * Rules:
 * - No polling unless at least one visible asset is processing
 * - No per-asset timers
 * - No polling inside thumbnail or row components
 * - No cache-busting query params
 * - No backend changes
 * - Poll must auto-stop
 * - MUST pause when isPaused === true (prevents dialog closure from router.reload)
 * 
 * Phase 3.1 invariant:
 * Background reconciliation MUST pause while upload dialog is open.
 * Inertia reloads reset page-owned state (dialogs, modals).
 * 
 * @param {Object} options
 * @param {Array} options.assets - Current assets array
 * @param {number} options.selectedCategoryId - Current selected category ID (stops polling on category change)
 * @param {boolean} options.isPaused - If true, reconciliation pauses entirely (no polling, no reloads)
 * @returns {void} - Hook manages polling internally
 */
import { useEffect, useRef } from 'react'
import { router } from '@inertiajs/react'
import { getThumbnailState } from '../utils/thumbnailUtils'

const POLL_INTERVAL_MS = 6000 // 6 seconds (between 5-8 seconds as specified)
const MAX_POLL_ATTEMPTS = 8 // Max attempts (~6-10 as specified, using 8)

export function useAssetReconciliation({ assets, selectedCategoryId, isPaused = false }) {
    const intervalIdRef = useRef(null)
    const pollAttemptsRef = useRef(0)
    const prevCategoryIdRef = useRef(selectedCategoryId)
    const assetsRef = useRef(assets)
    const isPausedRef = useRef(isPaused)

    // Keep assets ref in sync
    useEffect(() => {
        assetsRef.current = assets
    }, [assets])

    // Keep isPaused ref in sync (for interval callback to check latest value)
    useEffect(() => {
        isPausedRef.current = isPaused
    }, [isPaused])

    // Phase 3.1E: Detect if any assets are processing
    // Only start polling if at least one visible asset is processing
    // NEVER poll for NOT_SUPPORTED files (determined by extension/mime only)
    const hasProcessingAssets = (assets || []).some(asset => {
        if (!asset || !asset.id) return false
        const { state } = getThumbnailState(asset)
        // Phase 3.1E: Only poll for PENDING state (processing in progress)
        // NOT_SUPPORTED files never poll (file type doesn't support thumbnails)
        // FAILED files don't poll (generation failed, user must retry)
        // AVAILABLE files don't poll (already complete)
        return state === 'PENDING' || asset.processing === true
    })

    // Cleanup function
    const cleanup = () => {
        if (intervalIdRef.current) {
            clearInterval(intervalIdRef.current)
            intervalIdRef.current = null
        }
        pollAttemptsRef.current = 0
    }

    // Main reconciliation effect
    useEffect(() => {
        // Phase 3.1: Early-exit when paused (MANDATORY)
        // If isPaused === true:
        // - Do not start polling
        // - Do not call router.reload
        // - Do not increment attempts
        // - Do not set intervals
        // - Immediately return / noop
        if (isPaused) {
            cleanup()
            return
        }

        // Stop conditions:
        // 1. Category changed (user navigated) - stop immediately
        // 2. No assets are processing - stop immediately
        // 3. Max attempts reached - stop immediately
        
        // Reset attempts counter when category changes
        if (prevCategoryIdRef.current !== selectedCategoryId) {
            cleanup()
            prevCategoryIdRef.current = selectedCategoryId
            return
        }

        // Stop if no assets are processing
        if (!hasProcessingAssets) {
            cleanup()
            return
        }

        // Start polling only if assets are processing and not paused
        if (hasProcessingAssets && !intervalIdRef.current) {
            // Reset attempts when starting new poll cycle
            pollAttemptsRef.current = 0

            intervalIdRef.current = setInterval(() => {
                // Phase 3.1: Check pause state on every interval tick (MANDATORY)
                // If paused, immediately stop and cleanup
                // Use ref to check latest value (closure may have stale value)
                if (isPausedRef.current) {
                    cleanup()
                    return
                }

                // Phase 3.1E: Check stop conditions before each poll
                // Only poll for PENDING state (processing in progress)
                // NOT_SUPPORTED, FAILED, and AVAILABLE states never poll
                const currentAssets = assetsRef.current || []
                const stillProcessing = currentAssets.some(asset => {
                    if (!asset || !asset.id) return false
                    const { state } = getThumbnailState(asset)
                    return state === 'PENDING' || asset.processing === true
                })

                // Stop if no longer processing or max attempts reached
                if (!stillProcessing || pollAttemptsRef.current >= MAX_POLL_ATTEMPTS) {
                    cleanup()
                    return
                }

                // Increment attempts
                pollAttemptsRef.current += 1

                // Reload assets only (preserves scroll and state)
                router.reload({
                    only: ['assets'],
                    preserveScroll: true,
                    preserveState: true,
                })
            }, POLL_INTERVAL_MS)
        }

        // Cleanup on unmount or when dependencies change
        return cleanup
    }, [hasProcessingAssets, selectedCategoryId, isPaused])

    // Cleanup on unmount
    useEffect(() => {
        return cleanup
    }, [])
}
