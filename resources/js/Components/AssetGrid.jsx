/**
 * AssetGrid Component
 *
 * Grid layout for displaying assets with true CSS Grid scaling.
 * Uses AssetGridContainer for variable column sizing.
 *
 * HARD STABILIZATION: Grid thumbnails are snapshot-only by design to prevent flicker and re-render thrash.
 * Thumbnails in the grid must ONLY update via:
 * - Full page reload
 * - Explicit router.reload({ only: ['assets'] })
 *
 * Live thumbnail updates are intentionally disabled in the grid context.
 * See AssetDrawer for live thumbnail behavior when viewing asset details.
 *
 * Desktop: click-drag (including from card chrome/thumbnail, not on controls) draws a marquee;
 * assets intersecting the rectangle are selected in SelectionContext (desktop-style). Hold Ctrl/Cmd
 * or Shift anytime during the drag (or on release) to add to the current selection instead of
 * replacing it — same idea as additive multi-select on the desktop. Touch pointers are ignored.
 *
 * @param {Object} props
 * @param {Array} props.assets - Array of asset objects to display
 * @param {Function} props.onAssetClick - Optional click handler when an asset is clicked
 * @param {Function} props.onAssetDoubleClick - Optional double-click / double-tap (e.g. fullscreen zoom)
 * @param {number} props.cardSize - Card size in pixels (160-360, default 220)
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {number|null} props.selectedAssetId - ID of currently selected asset
 * @param {string} props.primaryColor - Brand primary color for selected highlight
 */
import { useState, useEffect, useLayoutEffect, useRef, useCallback, useMemo } from 'react'
import AssetCard from './AssetCard'
import AssetGridContainer from './AssetGridContainer'
import { useSelectionOptional } from '../contexts/SelectionContext'
import { executionEnhancedGridContainerClass } from '../utils/executionEnhancedGridContainerClass'
import { isExecutionEnhancedGridMode } from '../utils/assetCardEnhancedExecutionChrome'

const MARQUEE_DRAG_THRESHOLD_PX = 5
/** Matches Tailwind `gap-7` (1.75rem) for column width math */
const MASONRY_COLUMN_GAP_PX = 28
/** After a completed rubber-band, suppress AssetCard clicks (see AssetCard handleClick). */
const MARQUEE_SUPPRESS_CLICK_MS = 600

/** Cap masonry tile image height; scales slightly with card width preference. */
export function masonryMaxHeightForCardSize(cardSize) {
    const s = Math.max(160, Math.min(600, cardSize))
    return Math.min(560, Math.round(s * 2.35))
}

function rectsIntersect(a, b) {
    return !(a.right < b.left || a.left > b.right || a.bottom < b.top || a.top > b.bottom)
}

/** True if pointer event should treat marquee as additive (add intersecting items, do not clear). */
function isMarqueeAdditivePointerEvent(ev) {
    return !!(ev.ctrlKey || ev.metaKey || ev.shiftKey)
}

function assetToSelectionItem(asset, selectionAssetType) {
    return {
        id: asset.id,
        type: selectionAssetType,
        name: asset.title ?? asset.original_filename ?? '',
        thumbnail_url: asset.final_thumbnail_url ?? asset.thumbnail_url ?? asset.preview_thumbnail_url ?? null,
        category_id: asset.metadata?.category_id ?? asset.category_id ?? null,
    }
}

export default function AssetGrid({
    assets = [],
    onAssetClick = null,
    onAssetDoubleClick = null,
    cardSize = 220,
    showInfo = true,
    selectedAssetId = null,
    primaryColor = '#6366f1',
    cardVariant = 'default', // 'default' | 'cinematic' — cinematic = transparent cards over background
    cardStyle = 'default', // 'default' | 'guidelines' — guidelines = brand guidelines color-tile style (flat, label below, hover shadow)
    selectedAssetIds = [], // Phase 2 – Step 7: Bulk selection
    onAssetSelect = null, // Phase 2 – Step 7: Bulk selection callback
    bucketAssetIds = [], // Phase D1: Download bucket IDs
    onBucketToggle = null, // Phase D1: Toggle single asset in bucket
    isPendingApprovalMode = false, // Phase L.6.2: Approval inbox mode
    isPendingPublicationFilter = false, // Phase J.3.1: Pending publication filter active
    onAssetApproved = null, // Phase L.6.2: Callback when asset is approved/rejected
    selectionAssetType = 'asset', // Phase 2 Selection: 'asset' | 'execution' for SelectionContext type
    layoutMode = 'grid', // 'grid' | 'masonry' — column count still follows card size; masonry uses natural heights (capped)
    executionThumbnailViewMode = null, // Deliverables: standard | enhanced | presentation
    /** Server ?q= — highlight matching video AI tags in card when non-empty */
    gridSearchQuery = '',
    /** Assets uniform grid: 'cover' | 'contain' from View menu */
    gridImageFit = undefined,
}) {
    const safeAssets = (assets || []).filter(Boolean)
    const selection = useSelectionOptional()

    const containerRef = useRef(null)
    const masonryMeasureRef = useRef(null)
    const [masonryColumnCount, setMasonryColumnCount] = useState(1)
    const itemRefs = useRef(new Map())
    const marqueeSessionRef = useRef(null)
    const [marqueeRect, setMarqueeRect] = useState(null)

    const clearMarqueeSession = useCallback(() => {
        marqueeSessionRef.current = null
        setMarqueeRect(null)
        document.body.style.removeProperty('user-select')
    }, [])

    const applyMarqueeSelection = useCallback(
        (clientRect, additive) => {
            if (!selection) return
            const intersecting = []
            itemRefs.current.forEach((el, id) => {
                if (!el?.getBoundingClientRect) return
                const cardRect = el.getBoundingClientRect()
                if (rectsIntersect(clientRect, cardRect)) {
                    intersecting.push(id)
                }
            })
            const assetById = new Map(safeAssets.map((a) => [String(a.id), a]))
            const picked = intersecting.map((id) => assetById.get(String(id))).filter(Boolean)

            if (additive) {
                picked.forEach((asset) => {
                    if (!selection.isSelected(asset.id)) {
                        selection.selectItem(assetToSelectionItem(asset, selectionAssetType))
                    }
                })
            } else {
                selection.clearSelection()
                if (picked.length > 0) {
                    selection.selectMultiple(picked.map((a) => assetToSelectionItem(a, selectionAssetType)))
                }
            }
        },
        [selection, safeAssets, selectionAssetType]
    )

    useEffect(() => {
        return () => {
            document.body.style.removeProperty('user-select')
        }
    }, [])

    const prevIdsRef = useRef(new Set())
    const [animatedIds, setAnimatedIds] = useState(new Set())
    const [visibleIds, setVisibleIds] = useState(new Set())

    const idsKey = safeAssets.map((a) => a.id).join(',')

    useLayoutEffect(() => {
        const currIds = new Set(safeAssets.map((a) => a.id))
        const prevIds = prevIdsRef.current

        if (prevIds.size === 0) {
            setAnimatedIds(currIds)
        } else {
            const isAppend = [...prevIds].every((id) => currIds.has(id)) && currIds.size > prevIds.size
            if (isAppend) {
                const newIds = new Set([...currIds].filter((id) => !prevIds.has(id)))
                if (newIds.size > 0) {
                    setAnimatedIds((prev) => new Set([...prev, ...newIds]))
                }
            } else {
                setAnimatedIds(currIds)
            }
        }
        prevIdsRef.current = currIds
    }, [idsKey])

    useLayoutEffect(() => {
        if (animatedIds.size === 0) return
        const id = requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                setVisibleIds((prev) => new Set([...prev, ...animatedIds]))
            })
        })
        return () => cancelAnimationFrame(id)
    }, [animatedIds])

    useEffect(() => {
        if (animatedIds.size === 0) return
        const t = setTimeout(() => setAnimatedIds(new Set()), 1000)
        return () => clearTimeout(t)
    }, [animatedIds])

    const clampedCardSize = useMemo(() => Math.max(160, Math.min(600, cardSize)), [cardSize])

    useLayoutEffect(() => {
        if (layoutMode !== 'masonry') return
        const el = masonryMeasureRef.current
        if (!el) return
        const gap = MASONRY_COLUMN_GAP_PX
        const colUnit = clampedCardSize + gap
        const update = () => {
            const w = el.getBoundingClientRect().width
            if (w < 8) return
            const n = Math.max(1, Math.floor((w + gap) / colUnit))
            setMasonryColumnCount((prev) => (prev !== n ? n : prev))
        }
        update()
        const ro = new ResizeObserver(update)
        ro.observe(el)
        return () => ro.disconnect()
    }, [layoutMode, clampedCardSize])

    const masonryColumns = useMemo(() => {
        if (layoutMode !== 'masonry') return []
        const n = Math.max(1, masonryColumnCount)
        const cols = Array.from({ length: n }, () => [])
        safeAssets.forEach((asset, index) => {
            cols[index % n].push({ asset, index })
        })
        return cols
    }, [layoutMode, safeAssets, masonryColumnCount])

    const handleContainerPointerDown = useCallback(
        (e) => {
            // Marquee is for mouse/pen only; touch uses taps and multi-select UI.
            if (e.pointerType === 'touch') return
            if (e.button !== 0) return
            if (!selection) return
            if (onAssetSelect) return
            if (e.target.closest('button, a, input, select, textarea, [role="button"], label')) return

            const startX = e.clientX
            const startY = e.clientY
            const pointerId = e.pointerId
            marqueeSessionRef.current = {
                startX,
                startY,
                pointerId,
                additive: isMarqueeAdditivePointerEvent(e),
                active: false,
            }

            const onMove = (ev) => {
                const sess = marqueeSessionRef.current
                if (!sess || ev.pointerId !== sess.pointerId) return
                if (isMarqueeAdditivePointerEvent(ev)) {
                    sess.additive = true
                }
                const dx = ev.clientX - sess.startX
                const dy = ev.clientY - sess.startY
                if (!sess.active) {
                    if (Math.hypot(dx, dy) < MARQUEE_DRAG_THRESHOLD_PX) return
                    sess.active = true
                    document.body.style.userSelect = 'none'
                    ev.preventDefault()
                }
                const left = Math.min(sess.startX, ev.clientX)
                const top = Math.min(sess.startY, ev.clientY)
                const width = Math.abs(ev.clientX - sess.startX)
                const height = Math.abs(ev.clientY - sess.startY)
                setMarqueeRect({ left, top, width, height })
            }

            const onUp = (ev) => {
                if (ev.pointerId !== pointerId) return
                window.removeEventListener('pointermove', onMove)
                window.removeEventListener('pointerup', onUp)
                window.removeEventListener('pointercancel', onUp)
                const sess = marqueeSessionRef.current
                if (!sess) return

                if (sess.active) {
                    ev.preventDefault()
                    const left = Math.min(sess.startX, ev.clientX)
                    const top = Math.min(sess.startY, ev.clientY)
                    const right = Math.max(sess.startX, ev.clientX)
                    const bottom = Math.max(sess.startY, ev.clientY)
                    const additive =
                        sess.additive || isMarqueeAdditivePointerEvent(ev)
                    applyMarqueeSelection(
                        { left, top, right, bottom },
                        additive
                    )
                    if (typeof window !== 'undefined') {
                        window.__assetGridMarqueeSuppressClickUntil =
                            Date.now() + MARQUEE_SUPPRESS_CLICK_MS
                    }
                }
                clearMarqueeSession()
            }

            window.addEventListener('pointermove', onMove)
            window.addEventListener('pointerup', onUp)
            window.addEventListener('pointercancel', onUp)
        },
        [selection, onAssetSelect, applyMarqueeSelection, clearMarqueeSession]
    )

    if (safeAssets.length === 0) {
        return null
    }

    const isEnhancedGridMode = isExecutionEnhancedGridMode(executionThumbnailViewMode)
    const enhancedGridContainerClass = executionEnhancedGridContainerClass(executionThumbnailViewMode)

    const minAnimatedIndex = safeAssets.findIndex((a) => animatedIds.has(a.id))
    const staggerMs = 20
    const masonryMaxPx = masonryMaxHeightForCardSize(cardSize)

    const setItemRef = (assetId, el) => {
        const key = String(assetId)
        if (el) {
            itemRefs.current.set(key, el)
        } else {
            itemRefs.current.delete(key)
        }
    }

    const renderAssetCell = (asset, index) => {
        const isEntering = animatedIds.has(asset.id)
        const isVisible = !isEntering || visibleIds.has(asset.id)
        const delay = isEntering && minAnimatedIndex >= 0 ? (index - minAnimatedIndex) * staggerMs : 0
        return (
            <div
                key={asset.id}
                ref={(el) => setItemRef(asset.id, el)}
                data-asset-card
                data-asset-id={asset.id}
                className={`transition-all duration-300 ease-out ${
                    isVisible ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-2 scale-[0.98]'
                }`}
                style={{ transitionDelay: `${delay}ms` }}
            >
                <AssetCard
                    asset={asset}
                    onClick={onAssetClick}
                    onDoubleClick={onAssetDoubleClick}
                    showInfo={showInfo}
                    isSelected={selectedAssetId === asset.id}
                    primaryColor={primaryColor}
                    cardVariant={cardVariant}
                    isBulkSelected={selectedAssetIds.includes(asset.id)}
                    onBulkSelect={onAssetSelect ? () => onAssetSelect(asset.id) : null}
                    isInBucket={bucketAssetIds.includes(asset.id)}
                    onBucketToggle={onBucketToggle ? () => onBucketToggle(asset.id) : null}
                    isPendingApprovalMode={isPendingApprovalMode}
                    isPendingPublicationFilter={isPendingPublicationFilter}
                    onAssetApproved={onAssetApproved ? () => onAssetApproved(asset.id) : null}
                    cardStyle={cardStyle}
                    cardSize={cardSize}
                    selectionAssetType={selectionAssetType}
                    layoutMode={layoutMode}
                    masonryMaxHeightPx={masonryMaxPx}
                    executionThumbnailViewMode={executionThumbnailViewMode}
                    gridSearchQuery={gridSearchQuery}
                    gridImageFit={gridImageFit}
                />
            </div>
        )
    }

    return (
        <div
            ref={containerRef}
            data-enhanced-grid-mode={isEnhancedGridMode ? 'true' : 'false'}
            className={['relative -m-1 rounded-lg p-1', enhancedGridContainerClass].filter(Boolean).join(' ')}
            onPointerDownCapture={handleContainerPointerDown}
        >
            {isEnhancedGridMode && (
                <span
                    className="pointer-events-none absolute right-1.5 top-1.5 z-[1] select-none text-base opacity-80 drop-shadow-sm"
                    title="Enhanced preview mode"
                    aria-hidden
                >
                    ✨
                </span>
            )}
            {marqueeRect && (
                <div
                    className="pointer-events-none fixed z-[100] border-2 border-dashed rounded-sm"
                    style={{
                        left: marqueeRect.left,
                        top: marqueeRect.top,
                        width: marqueeRect.width,
                        height: marqueeRect.height,
                        borderColor: primaryColor,
                        backgroundColor: `${primaryColor}26`,
                    }}
                    aria-hidden
                />
            )}
            {layoutMode === 'masonry' ? (
                <div
                    ref={masonryMeasureRef}
                    className="w-full min-w-0"
                    style={{ '--asset-card-size': `${clampedCardSize}px` }}
                >
                    {/*
                      Top-align columns so the first tile in each column shares one baseline
                      (items-center left empty space above shorter columns).
                    */}
                    <div className="flex w-full min-w-0 items-start gap-7">
                        {masonryColumns.map((col, ci) => (
                            <div key={ci} className="flex min-w-0 flex-1 flex-col gap-7">
                                {col.map(({ asset, index }) => renderAssetCell(asset, index))}
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <AssetGridContainer cardSize={cardSize} layoutMode={layoutMode}>
                    {safeAssets.map((asset, index) => renderAssetCell(asset, index))}
                </AssetGridContainer>
            )}
        </div>
    )
}
