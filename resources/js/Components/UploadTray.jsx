/**
 * Upload tray: batch summary, optional compact filters, capped-height queue list,
 * and collapsed view for large batches (>10 files) so the modal stays usable.
 */

import { useState, useMemo, useRef, useEffect, useCallback } from 'react'
import UploadItemRow from './UploadItemRow'
import UploadBatchSummaryBar from './Upload/UploadBatchSummaryBar'
import { computeUploadCounts } from '../utils/uploadTrayCounts'
import {
    computeOverallBatchUploadPercent,
    isUploadItemTransferTerminal,
} from '../utils/uploadQueueProgress'

/** Tailwind class merge (additive; avoids new dependencies) */
function cn(...parts) {
    return parts.filter(Boolean).join(' ')
}

const VIRTUAL_THRESHOLD = 42
const ROW_ESTIMATE_PX = 120
/** Above this count, default to collapsed list (active + issues + sample of completed). */
const COLLAPSE_LIST_THRESHOLD = 10

const FILTER_IDS = ['all', 'uploading', 'processing', 'ready', 'failed', 'skipped']

const FILTER_LABELS = {
    all: 'All',
    uploading: 'Uploading',
    processing: 'Processing',
    ready: 'Ready',
    failed: 'Failed',
    skipped: 'Skipped',
}

function countImages(items) {
    return items.filter((i) => i.file?.type?.startsWith('image/')).length
}

function deriveBatchPhase(batchStatus, counts, total, items = []) {
    if (total === 0) return 'idle'
    if (batchStatus === 'processing_followup') return 'processing_followup'

    const list = Array.isArray(items) ? items : []
    const allTerminal =
        list.length === total && total > 0 && list.every(isUploadItemTransferTerminal)

    if (allTerminal) {
        if (batchStatus === 'finalizing' || counts.processing > 0) return 'processing_previews'
        if (counts.failed > 0 || batchStatus === 'partial_success') return 'complete_with_errors'
        if (counts.skipped > 0) return 'complete_with_errors'
        if (batchStatus === 'ready') return 'batch_ready'
        return 'complete'
    }

    if (batchStatus === 'complete') return 'complete'
    if (batchStatus === 'partial_success') return 'complete_with_errors'
    if (batchStatus === 'finalizing') return 'processing_previews'
    if (batchStatus === 'uploading') {
        const waiting = counts.queued + counts.uploading
        if (counts.queued === total && counts.uploading === 0) return 'preparing'
        if (waiting > 0 && counts.uploading === 0 && counts.queued > 0) return 'checking'
        return 'uploading'
    }
    if (batchStatus === 'ready' && total > 0) {
        if (counts.failed > 0) return 'complete_with_errors'
        return 'batch_ready'
    }
    return 'idle'
}

function itemFilterBucket(item) {
    if (item.uploadStatus === 'skipped') return 'skipped'
    if (item.uploadStatus === 'failed') return 'failed'
    const life = item.lifecycle || ''
    if (life === 'finalized') {
        const t = item.pipelineThumbStatus
        const terminal = t === 'completed' || t === 'failed'
        if (item.assetId && !terminal) return 'processing'
        return 'ready'
    }
    if (life === 'finalizing' || item.uploadStatus === 'processing') return 'processing'
    if (item.uploadStatus === 'uploading' || item.uploadStatus === 'queued') return 'uploading'
    return 'all'
}

/**
 * When many files are selected, show a compact subset (preserve original order).
 * @returns {Set<string>|null} clientIds to show, or null = show all
 */
function buildCollapsedVisibleIdSet(items) {
    const n = items.length
    if (n <= COLLAPSE_LIST_THRESHOLD) return null

    const idSet = new Set()
    const add = (it) => {
        if (it?.clientId) idSet.add(it.clientId)
    }

    for (const it of items) {
        const b = itemFilterBucket(it)
        if (b === 'failed' || b === 'skipped') add(it)
        if (b === 'uploading' || b === 'processing') add(it)
        const life = it.lifecycle || ''
        // Bytes on S3, not yet finalized — still “active” for the user
        if (life === 'uploaded' && it.uploadStatus === 'complete') add(it)
    }

    const readyOnes = items.filter((it) => itemFilterBucket(it) === 'ready')
    readyOnes.slice(-5).forEach(add)

    const queuedOnes = items.filter((it) => it.uploadStatus === 'queued' && !idSet.has(it.clientId))
    queuedOnes.slice(0, 2).forEach(add)

    return idSet
}

function filterByIdSet(items, idSet) {
    return items.filter((it) => idSet.has(it.clientId))
}

function countPerFilter(items, filterId) {
    if (filterId === 'all') return items.length
    return items.filter((it) => itemFilterBucket(it) === filterId).length
}

/**
 * @param {Object} props
 * @param {Object} props.uploadManager
 * @param {string} props.batchStatus
 * @param {Function} [props.onRemoveItem]
 * @param {Function} [props.onRetryItem] — (clientId) => void
 * @param {string} [props.className]
 * @param {boolean} [props.disabled]
 * @param {string|null} [props.brandPrimary] — workspace primary for batch + row progress fills
 */
export default function UploadTray({
    uploadManager,
    batchStatus = 'idle',
    onRemoveItem,
    onRetryItem,
    className = '',
    disabled = false,
    brandPrimary = null,
}) {
    const { hasItems, items } = uploadManager
    const [uploadFilter, setUploadFilter] = useState('all')
    const [scrollTop, setScrollTop] = useState(0)
    const [viewportH, setViewportH] = useState(400)
    const [listExpanded, setListExpanded] = useState(false)
    const listRef = useRef(null)

    const imageCount = useMemo(() => countImages(items), [items])
    const counts = useMemo(() => computeUploadCounts(items), [items])
    const batchPhase = useMemo(
        () => deriveBatchPhase(batchStatus, counts, items.length, items),
        [batchStatus, counts, items]
    )
    const overallPercent = useMemo(() => computeOverallBatchUploadPercent(items), [items])

    const collapsedIdSet = useMemo(() => buildCollapsedVisibleIdSet(items), [items])
    const useCollapsedList = collapsedIdSet !== null && !listExpanded
    const itemsForList = useMemo(() => {
        if (!useCollapsedList || collapsedIdSet === null) return items
        return filterByIdSet(items, collapsedIdSet)
    }, [items, useCollapsedList, collapsedIdSet])

    const hiddenOtherCount = useMemo(() => {
        if (!useCollapsedList) return 0
        return Math.max(0, items.length - itemsForList.length)
    }, [useCollapsedList, items.length, itemsForList.length])

    const filteredItems = useMemo(() => {
        if (uploadFilter === 'all') return itemsForList
        return itemsForList.filter((item) => itemFilterBucket(item) === uploadFilter)
    }, [itemsForList, uploadFilter])

    const useVirtual = filteredItems.length >= VIRTUAL_THRESHOLD

    const measureViewport = useCallback(() => {
        const el = listRef.current
        if (el) setViewportH(el.clientHeight || 400)
    }, [])

    useEffect(() => {
        measureViewport()
    }, [filteredItems.length, measureViewport])

    useEffect(() => {
        const el = listRef.current
        if (!el || !useVirtual) return undefined
        const ro = new ResizeObserver(() => measureViewport())
        ro.observe(el)
        return () => ro.disconnect()
    }, [useVirtual, measureViewport])

    const { start, end, paddingTop, paddingBottom } = useMemo(() => {
        if (!useVirtual) {
            return { start: 0, end: filteredItems.length - 1, paddingTop: 0, paddingBottom: 0 }
        }
        const overscan = 10
        const startIdx = Math.max(0, Math.floor(scrollTop / ROW_ESTIMATE_PX) - overscan)
        const endIdx = Math.min(
            filteredItems.length - 1,
            Math.ceil((scrollTop + viewportH) / ROW_ESTIMATE_PX) + overscan
        )
        const top = startIdx * ROW_ESTIMATE_PX
        const bottom = Math.max(0, (filteredItems.length - endIdx - 1) * ROW_ESTIMATE_PX)
        return { start: startIdx, end: endIdx, paddingTop: top, paddingBottom: bottom }
    }, [useVirtual, scrollTop, viewportH, filteredItems.length])

    const visibleSlice = useMemo(() => {
        if (!useVirtual) return filteredItems
        if (filteredItems.length === 0) return []
        return filteredItems.slice(start, end + 1)
    }, [useVirtual, filteredItems, start, end])

    const containPerformance = items.length >= 40

    const problemCount = counts.failed + counts.skipped
    const showFilterRow = useMemo(() => {
        if (items.length === 0) return false
        if (problemCount > 0) return true
        if (items.length > COLLAPSE_LIST_THRESHOLD) return true
        return false
    }, [items.length, problemCount])

    const filterButtonClass = (active, isFailedFilter, emphasizeFailure) => {
        if (active && isFailedFilter && emphasizeFailure) {
            return 'rounded px-2 py-0.5 text-xs font-semibold text-red-800 bg-red-50 ring-1 ring-red-200/80'
        }
        if (active) {
            return 'rounded px-2 py-0.5 text-xs font-medium text-gray-900 bg-gray-200/90'
        }
        return 'rounded px-2 py-0.5 text-xs text-gray-500 hover:text-gray-800 hover:bg-gray-100/80'
    }

    useEffect(() => {
        if (items.length <= COLLAPSE_LIST_THRESHOLD) {
            setListExpanded(false)
        }
    }, [items.length])

    if (!hasItems) {
        return null
    }

    const emphasizeFailure = counts.failed > 0
    /** Match UploadAssetDialog `uploadModalLayoutLock` — fixed queue viewport stops the tray from shrinking as rows update. */
    const lockQueueViewport = items.length >= 8

    return (
        <div className={`overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm ${className}`}>
            <div className="sticky top-0 z-20 border-b border-gray-200 bg-white/95 px-4 py-2.5 backdrop-blur-sm">
                <div className="mb-2 flex items-center justify-between gap-2">
                    <h3 className="text-sm font-medium text-gray-900">Upload queue</h3>
                    <span className="text-xs text-gray-500 tabular-nums">{items.length} files</span>
                </div>
                <UploadBatchSummaryBar
                    totalCount={items.length}
                    imageCount={imageCount}
                    counts={counts}
                    batchPhase={batchPhase}
                    overallPercent={overallPercent}
                    compact={items.length >= 8}
                    brandPrimary={brandPrimary}
                />
                {showFilterRow && (
                    <div
                        className="mt-2 flex flex-wrap items-center gap-x-0.5 border-t border-gray-100 pt-2 text-[11px] text-gray-500"
                        role="tablist"
                        aria-label="Filter uploads by status"
                    >
                        {FILTER_IDS.filter((fid) => fid === 'all' || countPerFilter(items, fid) > 0).map(
                            (fid, idx) => {
                                const cnt = countPerFilter(items, fid)
                                const active = uploadFilter === fid
                                const label = FILTER_LABELS[fid]
                                const isFailed = fid === 'failed'
                                const text = fid === 'all' ? label : `${label} (${cnt})`
                                return (
                                    <span key={fid} className="inline-flex items-center">
                                        {idx > 0 && (
                                            <span className="mx-1 select-none text-gray-300" aria-hidden>
                                                ·
                                            </span>
                                        )}
                                        <button
                                            type="button"
                                            role="tab"
                                            aria-selected={active}
                                            onClick={() => setUploadFilter(fid)}
                                            className={filterButtonClass(active, isFailed, emphasizeFailure)}
                                        >
                                            {text}
                                        </button>
                                    </span>
                                )
                            }
                        )}
                    </div>
                )}
            </div>

            {collapsedIdSet !== null && (
                <div className="border-b border-gray-100 bg-gray-50/80 px-3 py-2">
                    {useCollapsedList ? (
                        <>
                            <p className="text-[11px] leading-snug text-gray-600">
                                Showing active and issue files first.
                                {hiddenOtherCount > 0 ? (
                                    <span className="text-gray-500">
                                        {' '}
                                        <span className="tabular-nums font-medium text-gray-700">{hiddenOtherCount}</span>{' '}
                                        other file{hiddenOtherCount !== 1 ? 's' : ''} hidden.
                                    </span>
                                ) : null}
                            </p>
                            <button
                                type="button"
                                className="mt-1.5 text-[11px] font-medium text-gray-800 hover:text-gray-950 underline decoration-gray-300 underline-offset-2"
                                onClick={() => setListExpanded(true)}
                            >
                                Show all {items.length} files
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            className="text-[11px] font-medium text-gray-600 hover:text-gray-900"
                            onClick={() => {
                                setListExpanded(false)
                                setUploadFilter('all')
                            }}
                        >
                            Collapse list
                        </button>
                    )}
                </div>
            )}

            {filteredItems.length === 0 ? (
                <div className="px-4 py-8 text-center text-sm text-gray-500">No files in this view.</div>
            ) : (
                <div
                    ref={listRef}
                    className={cn(
                        'jp-upload-modal-scroll overflow-y-auto overscroll-contain',
                        lockQueueViewport
                            ? 'h-[min(32vh,280px)] sm:h-[min(36vh,320px)]'
                            : 'max-h-[min(32vh,280px)] sm:max-h-[min(36vh,320px)]',
                    )}
                    onScroll={(e) => {
                        if (useVirtual) setScrollTop(e.currentTarget.scrollTop)
                    }}
                >
                    {useVirtual && paddingTop > 0 && (
                        <div style={{ height: paddingTop }} aria-hidden className="pointer-events-none" />
                    )}
                    <div>
                        {visibleSlice.map((item) => (
                            <UploadItemRow
                                key={item.clientId}
                                item={item}
                                uploadManager={uploadManager}
                                onRemove={onRemoveItem}
                                onRetry={onRetryItem}
                                disabled={disabled}
                                containPerformance={containPerformance}
                                brandPrimary={brandPrimary}
                            />
                        ))}
                    </div>
                    {useVirtual && paddingBottom > 0 && (
                        <div style={{ height: paddingBottom }} aria-hidden className="pointer-events-none" />
                    )}
                </div>
            )}
        </div>
    )
}
