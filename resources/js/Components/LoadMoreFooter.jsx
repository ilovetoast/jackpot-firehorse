/**
 * Load more footer: optional fallback button (IntersectionObserver lives in the page).
 * Shared by Assets, Deliverables, and Collections index views.
 */
export default function LoadMoreFooter({
    onLoadMore,
    hasMore,
    isLoading = false,
}) {
    if (!hasMore) return null

    return (
        <div className="flex justify-center py-4">
            <button
                type="button"
                onClick={onLoadMore}
                disabled={isLoading}
                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
                {isLoading ? 'Loading…' : 'Load more'}
            </button>
        </div>
    )
}
