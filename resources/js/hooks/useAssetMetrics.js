import { useCallback } from 'react'
import axios from 'axios'

/**
 * useAssetMetrics Hook
 * 
 * Hook for tracking asset metrics (downloads, views) from the frontend.
 * Handles API calls and error handling.
 * 
 * @returns {Object} Object with tracking functions
 */
export function useAssetMetrics() {
    /**
     * Track a download metric for an asset.
     * 
     * @param {string} assetId Asset UUID
     * @returns {Promise<void>}
     */
    const trackDownload = useCallback(async (assetId) => {
        if (!assetId) return

        try {
            await axios.post(`/app/assets/${assetId}/metrics/track`, {
                type: 'download',
            })
        } catch (error) {
            // Silently fail - metrics should never break user experience
            console.warn('[useAssetMetrics] Failed to track download:', error)
        }
    }, [])

    /**
     * Track a view metric for an asset.
     * 
     * @param {string} assetId Asset UUID
     * @param {string} viewType View type: 'drawer' or 'large_view'
     * @returns {Promise<void>}
     */
    const trackView = useCallback(async (assetId, viewType) => {
        if (!assetId || !viewType) return

        // Client-side deduplication check (optional, server also does it)
        // Store last tracked view in sessionStorage to prevent rapid-fire tracking
        const storageKey = `asset_view_${assetId}_${viewType}`
        const lastTracked = sessionStorage.getItem(storageKey)
        const now = Date.now()
        const windowMs = 5 * 60 * 1000 // 5 minutes in milliseconds

        if (lastTracked) {
            const timeSinceLastTrack = now - parseInt(lastTracked)
            if (timeSinceLastTrack < windowMs) {
                // View was tracked recently, skip
                return
            }
        }

        // Also check for pending requests to prevent duplicate API calls
        const pendingKey = `asset_view_pending_${assetId}_${viewType}`
        if (sessionStorage.getItem(pendingKey)) {
            // Request already in flight, skip
            return
        }

        // Mark as pending
        sessionStorage.setItem(pendingKey, '1')

        try {
            await axios.post(`/app/assets/${assetId}/metrics/track`, {
                type: 'view',
                view_type: viewType,
            })
            
            // Store timestamp of successful track
            sessionStorage.setItem(storageKey, now.toString())
        } catch (error) {
            // Silently fail - metrics should never break user experience
            console.warn('[useAssetMetrics] Failed to track view:', error)
        } finally {
            // Remove pending flag after a short delay to allow for request completion
            setTimeout(() => {
                sessionStorage.removeItem(pendingKey)
            }, 1000)
        }
    }, [])

    /**
     * Get metrics for an asset.
     * 
     * @param {string} assetId Asset UUID
     * @param {Object} options Query options
     * @param {string} [options.type] Metric type: 'download' or 'view'
     * @param {string} [options.period] Period: 'daily', 'weekly', 'monthly'
     * @param {string} [options.startDate] Start date (ISO string)
     * @param {string} [options.endDate] End date (ISO string)
     * @returns {Promise<Object>}
     */
    const getMetrics = useCallback(async (assetId, options = {}) => {
        if (!assetId) return null

        try {
            const params = new URLSearchParams()
            if (options.type) params.append('type', options.type)
            if (options.period) params.append('period', options.period)
            if (options.startDate) params.append('start_date', options.startDate)
            if (options.endDate) params.append('end_date', options.endDate)

            const response = await axios.get(`/app/assets/${assetId}/metrics?${params.toString()}`)
            return response.data
        } catch (error) {
            console.warn('[useAssetMetrics] Failed to get metrics:', error)
            return null
        }
    }, [])

    /**
     * Get download count for an asset.
     * 
     * @param {string} assetId Asset UUID
     * @returns {Promise<number|null>}
     */
    const getDownloadCount = useCallback(async (assetId) => {
        if (!assetId) return null

        try {
            const response = await axios.get(`/app/assets/${assetId}/metrics/downloads`)
            return response.data?.total_count ?? null
        } catch (error) {
            console.warn('[useAssetMetrics] Failed to get download count:', error)
            return null
        }
    }, [])

    /**
     * Get view count for an asset.
     * 
     * @param {string} assetId Asset UUID
     * @returns {Promise<number|null>}
     */
    const getViewCount = useCallback(async (assetId) => {
        if (!assetId) return null

        try {
            const response = await axios.get(`/app/assets/${assetId}/metrics/views`)
            return response.data?.total_count ?? null
        } catch (error) {
            console.warn('[useAssetMetrics] Failed to get view count:', error)
            return null
        }
    }, [])

    return {
        trackDownload,
        trackView,
        getMetrics,
        getDownloadCount,
        getViewCount,
    }
}
