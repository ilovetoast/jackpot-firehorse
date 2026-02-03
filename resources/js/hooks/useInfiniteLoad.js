/**
 * Client-side incremental load for grid/list views.
 * Displays items in batches of batchSize. "Load more" reveals next batch from existing data.
 * Resets when resetDeps change (e.g. category, filters, search).
 *
 * @param {Array} items - Full list of items
 * @param {number} batchSize - Items per batch (default 24)
 * @param {Array} resetDeps - Dependencies that trigger reset to initial batch when changed
 * @returns {{ visibleItems: Array, loadMore: () => void, hasMore: boolean }}
 */
import { useState, useEffect, useCallback } from 'react'

const DEFAULT_BATCH = 24

export function useInfiniteLoad(items = [], batchSize = DEFAULT_BATCH, resetDeps = []) {
    const [visibleCount, setVisibleCount] = useState(batchSize)

    // Reset to initial batch when query/filters change
    useEffect(() => {
        setVisibleCount(batchSize)
    }, [...resetDeps, batchSize]) // eslint-disable-line react-hooks/exhaustive-deps

    const loadMore = useCallback(() => {
        setVisibleCount((prev) => Math.min(prev + batchSize, (items || []).length))
    }, [items, batchSize])

    const safeItems = Array.isArray(items) ? items : []
    const visibleItems = safeItems.slice(0, visibleCount)
    const hasMore = visibleCount < safeItems.length

    return { visibleItems, loadMore, hasMore }
}
