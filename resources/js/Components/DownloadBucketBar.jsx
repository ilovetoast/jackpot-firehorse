/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 * Phase D1.1 — Download Bucket UX Polish
 *
 * Sticky bar shown when download bucket has items.
 * - Clickable count with expandable preview (thumbnails, per-item remove)
 * - Clear (with app ConfirmDialog when > 5 items)
 * - View downloads link, Create Download CTA
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { router, Link } from '@inertiajs/react'
import { ChevronDownIcon, ChevronUpIcon, XMarkIcon } from '@heroicons/react/24/outline'
import ConfirmDialog from './ConfirmDialog'

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

export default function DownloadBucketBar({
  bucketCount = 0,
  onCountChange = null,
  onRemove = null,
  onClear = null,
}) {
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState(null)
  const [expanded, setExpanded] = useState(false)
  const [previewItems, setPreviewItems] = useState([])
  const [loadingPreview, setLoadingPreview] = useState(false)
  const expandedOnceRef = useRef(false)

  // When count becomes 0, collapse and reset
  useEffect(() => {
    if (bucketCount <= 0) {
      setExpanded(false)
      setPreviewItems([])
      expandedOnceRef.current = false
    }
  }, [bucketCount])

  const fetchPreviewItems = useCallback(() => {
    if (bucketCount <= 0) return
    setLoadingPreview(true)
    fetch(route('download-bucket.items') + '?details=1', {
      method: 'GET',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error('Failed to load'))))
      .then((data) => {
        setPreviewItems(data.items || [])
      })
      .catch(() => setPreviewItems([]))
      .finally(() => setLoadingPreview(false))
  }, [bucketCount])

  // Fetch only when user first opens the panel — avoids refetch on every remove (no flash)
  useEffect(() => {
    if (!expanded) {
      expandedOnceRef.current = false
      return
    }
    if (bucketCount > 0 && !expandedOnceRef.current) {
      expandedOnceRef.current = true
      fetchPreviewItems()
    }
  }, [expanded, bucketCount])

  const handleToggleExpand = () => {
    setExpanded((prev) => !prev)
  }

  const performClear = useCallback(() => {
    if (onClear) {
      onClear()
    } else {
      fetch(route('download-bucket.clear'), {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then((r) => r.json())
        .then(() => {
          if (onCountChange) onCountChange(0)
        })
    }
  }, [onClear, onCountChange])

  const handleClear = () => {
    if (bucketCount > 5) {
      setShowClearConfirm(true)
      return
    }
    performClear()
  }

  const handleConfirmClear = () => {
    setShowClearConfirm(false)
    performClear()
  }

  const handleRemoveItem = (assetId) => {
    setPreviewItems((prev) => prev.filter((i) => i.id !== assetId))
    if (onRemove) {
      onRemove(assetId)
    } else {
      fetch(route('download-bucket.remove', { asset: assetId }), {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })
        .then((r) => r.json())
        .then((data) => {
          const count = data?.count ?? 0
          if (onCountChange) onCountChange(count)
        })
    }
  }

  const handlePreviewAsset = (assetId) => {
    setExpanded(false)
    router.get(route('assets.index'), { asset: assetId }, { preserveState: true, preserveScroll: true })
  }

  const handleCreateDownload = () => {
    setError(null)
    setCreating(true)
    router.post(route('downloads.store'), { source: 'grid' }, {
      preserveScroll: true,
      onSuccess: () => {
        setCreating(false)
        setExpanded(false)
        if (onCountChange) onCountChange(0)
        router.visit(route('downloads.index'))
      },
      onError: (errors) => {
        setCreating(false)
        setError(errors?.message || 'Could not create download.')
      },
    })
  }

  if (bucketCount <= 0) return null

  return (
    <>
    <div className="fixed bottom-0 left-0 right-0 z-40 border-t border-gray-200 bg-white shadow-lg safe-area-pb">
      {/* Expandable preview panel — grows upward */}
      {expanded && (
        <div className="border-b border-gray-200 bg-gray-50 max-h-64 overflow-y-auto">
          <div className="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
            <p className="text-xs font-medium text-gray-500 mb-2">
              {loadingPreview ? 'Loading…' : `${previewItems.length} item${previewItems.length !== 1 ? 's' : ''} selected`}
            </p>
            <div className="flex flex-wrap gap-2">
              {loadingPreview ? (
                <span className="text-sm text-gray-400">Loading preview…</span>
              ) : (
                previewItems.map((item) => {
                  const displayName = item.original_filename || item.title || 'Asset'
                  // Use backend-provided URL only (final or preview); no client fallback to avoid 404 for non-completed
                  const thumbUrl = item.thumbnail_url || item.final_thumbnail_url || item.preview_thumbnail_url || null
                  return (
                    <div
                      key={item.id}
                      className="group relative flex items-center gap-2 rounded-lg border border-gray-200 bg-white p-1.5 shadow-sm"
                    >
                      <button
                        type="button"
                        onClick={() => handlePreviewAsset(item.id)}
                        className="flex min-w-0 flex-shrink items-center gap-2 rounded focus:outline-none focus:ring-2 focus:ring-indigo-500"
                      >
                        <div className="h-12 w-12 flex-shrink-0 overflow-hidden rounded bg-gray-100">
                          {thumbUrl ? (
                            <img
                              src={thumbUrl}
                              alt=""
                              className="h-full w-full object-cover"
                            />
                          ) : (
                            <span className="flex h-full w-full items-center justify-center text-xs text-gray-400">
                              —
                            </span>
                          )}
                        </div>
                        <div className="min-w-0 flex-1 text-left">
                          <span
                            className="block max-w-[120px] truncate text-sm text-gray-700"
                            title={displayName}
                          >
                            {displayName}
                          </span>
                          {item.category_name && (
                            <span className="block max-w-[120px] truncate text-xs text-gray-400">
                              {item.category_name}
                            </span>
                          )}
                        </div>
                      </button>
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          handleRemoveItem(item.id)
                        }}
                        className="flex-shrink-0 rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500"
                        aria-label="Remove from bucket"
                      >
                        <XMarkIcon className="h-5 w-5" />
                      </button>
                    </div>
                  )
                })
              )}
            </div>
          </div>
        </div>
      )}

      {/* Main bar */}
      <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
        <div className="flex items-center gap-3">
          <button
            type="button"
            onClick={handleToggleExpand}
            className="inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500"
          >
            {expanded ? (
              <ChevronUpIcon className="h-5 w-5 text-gray-500" />
            ) : (
              <ChevronDownIcon className="h-5 w-5 text-gray-500" />
            )}
            <span>
              {bucketCount} item{bucketCount !== 1 ? 's' : ''} selected
            </span>
          </button>
          <button
            type="button"
            onClick={handleClear}
            className="text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 rounded px-2 py-1"
          >
            Clear
          </button>
        </div>
        <div className="flex items-center gap-3">
          {error && (
            <span className="text-sm text-red-600">{error}</span>
          )}
          <Link
            href={route('downloads.index')}
            className="text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 rounded px-2 py-1"
          >
            View downloads
          </Link>
          <button
            type="button"
            onClick={handleCreateDownload}
            disabled={creating}
            className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {creating ? 'Creating…' : 'Create Download'}
          </button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      open={showClearConfirm}
      onClose={() => setShowClearConfirm(false)}
      onConfirm={handleConfirmClear}
      title="Clear download bucket"
      message={`Clear all ${bucketCount} items from the download bucket? You can add assets again from the grid.`}
      confirmText="Clear"
      cancelText="Cancel"
      variant="warning"
    />
    </>
  )
}
