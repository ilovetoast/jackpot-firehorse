/**
 * Load more footer: IntersectionObserver sentinel + Load more button.
 * Triggers loadMore when user scrolls near bottom (~300px) or clicks button.
 * Shared by Assets, Deliverables, and Collections index views.
 */
import { useEffect, useRef, useCallback } from 'react'

const ROOT_MARGIN = '300px'

export default function LoadMoreFooter({
    onLoadMore,
    hasMore,
    isLoading = false,
}) {
    const sentinelRef = useRef(null)
    const loadMoreRef = useCallback(onLoadMore, [onLoadMore])

    useEffect(() => {
        if (!hasMore || isLoading || !sentinelRef.current) return

        const observer = new IntersectionObserver(
            (entries) => {
                const [entry] = entries
                if (entry?.isIntersecting) {
                    loadMoreRef()
                }
            },
            { root: null, rootMargin: ROOT_MARGIN, threshold: 0 }
        )

        observer.observe(sentinelRef.current)
        return () => observer.disconnect()
    }, [hasMore, isLoading, loadMoreRef])

    if (!hasMore) return null

    return (
        <div className="flex flex-col items-center gap-3 py-6">
            {/* Sentinel for IntersectionObserver - invisible trigger */}
            <div ref={sentinelRef} className="h-1 w-full" aria-hidden="true" />
            {isLoading ? (
                <div className="flex items-center gap-2 text-sm text-gray-500">
                    <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                    Loading...
                </div>
            ) : (
                <button
                    type="button"
                    onClick={onLoadMore}
                    className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                >
                    Load more
                </button>
            )}
        </div>
    )
}
