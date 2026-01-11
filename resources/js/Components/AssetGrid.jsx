/**
 * AssetGrid Component
 * 
 * Grid layout for displaying assets with true CSS Grid scaling.
 * Uses AssetGridContainer for variable column sizing.
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
}) {
    if (!assets || assets.length === 0) {
        return null
    }
    
    return (
        <AssetGridContainer cardSize={cardSize}>
            {assets.map((asset) => (
                <AssetCard
                    key={asset.id || asset.uuid || asset.title}
                    asset={asset}
                    onClick={onAssetClick}
                    showInfo={showInfo}
                    isSelected={selectedAssetId === asset.id}
                    primaryColor={primaryColor}
                />
            ))}
        </AssetGridContainer>
    )
}
