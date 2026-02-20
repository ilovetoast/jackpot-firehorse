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
 * @param {Object} props
 * @param {Array} props.assets - Array of asset objects to display
 * @param {Function} props.onAssetClick - Optional click handler when an asset is clicked
 * @param {number} props.cardSize - Card size in pixels (160-360, default 220)
 * @param {boolean} props.showInfo - Whether to show asset info (title, file type)
 * @param {number|null} props.selectedAssetId - ID of currently selected asset
 * @param {string} props.primaryColor - Brand primary color for selected highlight
 */
import { useState, useEffect, useLayoutEffect, useRef } from 'react'
import AssetCard from './AssetCard'
import AssetGridContainer from './AssetGridContainer'

export default function AssetGrid({ 
    assets = [], 
    onAssetClick = null,
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
}) {
    const safeAssets = (assets || []).filter(Boolean)
    if (safeAssets.length === 0) {
        return null
    }

    // Per-item entrance: only animate new items (initial load or infinite scroll append)
    const prevIdsRef = useRef(new Set())
    const [animatedIds, setAnimatedIds] = useState(new Set())
    const [visibleIds, setVisibleIds] = useState(new Set())

    // Stable dependency: only run when asset ids actually change (safeAssets is new array every render)
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

    // Trigger transition: double rAF ensures hidden state is painted before we transition
    useLayoutEffect(() => {
        if (animatedIds.size === 0) return
        const id = requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                setVisibleIds((prev) => new Set([...prev, ...animatedIds]))
            })
        })
        return () => cancelAnimationFrame(id)
    }, [animatedIds])

    // Clear animatedIds after cascade completes (34 tiles × 20ms + 280ms ≈ 960ms)
    useEffect(() => {
        if (animatedIds.size === 0) return
        const t = setTimeout(() => setAnimatedIds(new Set()), 1000)
        return () => clearTimeout(t)
    }, [animatedIds])

    // Waterfall cascade: 20ms stagger, 280ms duration — noticeable but not slow
    const minAnimatedIndex = safeAssets.findIndex((a) => animatedIds.has(a.id))
    const staggerMs = 20

    return (
        <AssetGridContainer cardSize={cardSize}>
            {safeAssets.map((asset, index) => {
                const isEntering = animatedIds.has(asset.id)
                const isVisible = !isEntering || visibleIds.has(asset.id)
                const delay = isEntering && minAnimatedIndex >= 0 ? (index - minAnimatedIndex) * staggerMs : 0
                return (
                <div
                    key={asset.id}
                    className={`transition-all duration-300 ease-out ${
                        isVisible ? 'opacity-100 translate-y-0 scale-100' : 'opacity-0 translate-y-2 scale-[0.98]'
                    }`}
                    style={{ transitionDelay: `${delay}ms` }}
                >
                <AssetCard
                    asset={asset}
                    onClick={onAssetClick}
                    showInfo={showInfo}
                    isSelected={selectedAssetId === asset.id}
                    primaryColor={primaryColor}
                    cardVariant={cardVariant}
                    isBulkSelected={selectedAssetIds.includes(asset.id)} // Phase 2 – Step 7
                    onBulkSelect={onAssetSelect ? () => onAssetSelect(asset.id) : null} // Phase 2 – Step 7
                    isInBucket={bucketAssetIds.includes(asset.id)} // Phase D1
                    onBucketToggle={onBucketToggle ? () => onBucketToggle(asset.id) : null} // Phase D1
                    isPendingApprovalMode={isPendingApprovalMode} // Phase L.6.2
                    isPendingPublicationFilter={isPendingPublicationFilter} // Phase J.3.1
                    onAssetApproved={onAssetApproved ? () => onAssetApproved(asset.id) : null} // Phase L.6.2
                    cardStyle={cardStyle}
                    selectionAssetType={selectionAssetType} // Phase 2 Selection
                />
                </div>
                )
            })}
        </AssetGridContainer>
    )
}
