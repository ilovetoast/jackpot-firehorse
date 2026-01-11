/**
 * AssetGrid Component
 * 
 * Responsive grid layout for displaying assets.
 * 
 * Grid breakpoints:
 * - Mobile: 2 columns (grid-cols-2)
 * - Tablet: 3 columns (md:grid-cols-3)
 * - Desktop: 4-5 columns (lg:grid-cols-4 xl:grid-cols-5)
 * 
 * @param {Object} props
 * @param {Array} props.assets - Array of asset objects to display
 * @param {Function} props.onAssetClick - Optional click handler when an asset is clicked
 */
import AssetCard from './AssetCard'

export default function AssetGrid({ assets = [], onAssetClick = null }) {
    if (!assets || assets.length === 0) {
        return null
    }
    
    return (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4">
            {assets.map((asset) => (
                <AssetCard
                    key={asset.id || asset.uuid || asset.title}
                    asset={asset}
                    onClick={onAssetClick}
                />
            ))}
        </div>
    )
}
