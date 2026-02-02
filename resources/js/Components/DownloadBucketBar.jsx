/**
 * Phase D1 — Secure Asset Downloader (Foundation)
 * Phase D1.1 — Download Bucket UX Polish
 *
 * Sticky bar shown when download bucket has items.
 * - Clickable count with expandable preview (thumbnails, per-item remove)
 * - Clear (with app ConfirmDialog when > 5 items)
 * - View downloads link, Create Download CTA
 *
 * IMPORTANT (phase locked): When download-create errors exist in usePage().props.errors, auto-open
 * Create Download panel so the user sees validation inline. Do not remove this behavior.
 */
import { useState, useEffect, useCallback, useRef } from 'react'
import { router, Link, usePage } from '@inertiajs/react'
import { ChevronDownIcon, ChevronUpIcon, XMarkIcon } from '@heroicons/react/24/outline'
import ConfirmDialog from './ConfirmDialog'
import CreateDownloadPanel from './CreateDownloadPanel'

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''

const DOWNLOAD_CREATE_ERROR_KEYS = ['expires_at', 'name', 'password', 'access_mode', 'branding_options', 'message']

export default function DownloadBucketBar({
  bucketCount = 0,
  onCountChange = null,
  onRemove = null,
  onClear = null,
  primaryColor,
}) {
  const { errors: pageErrors = {}, auth } = usePage().props
  const brandPrimary = primaryColor || auth?.activeBrand?.primary_color || '#6366f1'
  const [error, setError] = useState(null)
  const [expanded, setExpanded] = useState(false)
  const [showClearConfirm, setShowClearConfirm] = useState(false)
  const [showCreatePanel, setShowCreatePanel] = useState(false)

  // Open Create Download panel when page has download-create errors (e.g. redirect back with validation errors)
  const hasDownloadCreateError = DOWNLOAD_CREATE_ERROR_KEYS.some(
    (key) => pageErrors[key] !== undefined && (typeof pageErrors[key] === 'string' || Array.isArray(pageErrors[key]))
  )
  useEffect(() => {
    if (hasDownloadCreateError && bucketCount > 0) setShowCreatePanel(true)
  }, [hasDownloadCreateError, bucketCount])
  const [previewItems, setPreviewItems] = useState([])
  const [loadingPreview, setLoadingPreview] = useState(false)
  const expandedOnceRef = useRef(false)
  const prevCountRef = useRef(bucketCount)
  const [countJustBumped, setCountJustBumped] = useState(false)

  // When count becomes 0, collapse and reset
  useEffect(() => {
    if (bucketCount <= 0) {
      setExpanded(false)
      setPreviewItems([])
      expandedOnceRef.current = false
    }
  }, [bucketCount])

  // Trigger count "bump" animation when user adds an asset (count goes up)
  useEffect(() => {
    const prev = prevCountRef.current
    prevCountRef.current = bucketCount
    if (bucketCount > prev && prev >= 0) {
      setCountJustBumped(true)
      const t = setTimeout(() => setCountJustBumped(false), 450)
      return () => clearTimeout(t)
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

  const handleOpenCreatePanel = () => {
    setError(null)
    setShowCreatePanel(true)
  }

  const handleCreateSuccess = () => {
    setShowCreatePanel(false)
    setExpanded(false)
    if (onCountChange) onCountChange(0)
  }

  if (bucketCount <= 0) return null

  return (
    <>
    <div className="fixed bottom-0 left-0 right-0 z-[60] border-t border-gray-200 bg-white shadow-lg safe-area-pb">
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
                        className="flex min-w-0 flex-shrink items-center gap-2 rounded focus:outline-none focus:ring-2"
                        style={{ ['--tw-ring-color']: brandPrimary }}
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
            <span className="inline-flex items-baseline gap-0.5">
              <span
                key={bucketCount}
                className={`inline-block tabular-nums font-semibold ${countJustBumped ? 'animate-bucket-count-bump' : ''}`}
                aria-live="polite"
              >
                {bucketCount}
              </span>
              <span> item{bucketCount !== 1 ? 's' : ''} selected</span>
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
            className="text-sm font-medium text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 rounded px-2 py-1"
            style={{ ['--tw-ring-color']: brandPrimary }}
          >
            View downloads
          </Link>
          <button
            type="button"
            onClick={handleOpenCreatePanel}
            className="inline-flex items-center rounded-md px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
            style={{ backgroundColor: brandPrimary, ['--tw-ring-color']: brandPrimary }}
          >
            Create Download
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

    <CreateDownloadPanel
      open={showCreatePanel}
      onClose={() => setShowCreatePanel(false)}
      bucketCount={bucketCount}
      previewItems={previewItems}
      onSuccess={handleCreateSuccess}
    />
    </>
  )
}
