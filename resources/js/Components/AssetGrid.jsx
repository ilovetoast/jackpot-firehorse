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
}) {
    if (!assets || assets.length === 0) {
        return null
    }
    
    return (
        <AssetGridContainer cardSize={cardSize}>
            {assets.map((asset) => (
                <AssetCard
                    // CRITICAL: Stable key to prevent remounts/flashing.
                    // Asset cards must be keyed by asset.id ONLY.
                    key={asset.id}
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
                />
            ))}
        </AssetGridContainer>
    )
}
