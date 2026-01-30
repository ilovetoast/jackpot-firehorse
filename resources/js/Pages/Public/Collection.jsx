/**
 * Public collection page (C8). No auth. Brand header, collection name/description, asset grid, download per asset.
 */
import { DocumentIcon } from '@heroicons/react/24/outline'
import AssetGrid from '../Components/AssetGrid'

export default function PublicCollection({ collection = {}, assets = [] }) {
    const { name, description, brand_name } = collection

    // Handle asset click - for public collections, clicking downloads the asset
    const handleAssetClick = (asset) => {
        if (asset.download_url) {
            window.open(asset.download_url, '_blank', 'noopener,noreferrer')
        }
    }

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Brand header — no AppNav */}
            <header className="bg-white border-b border-gray-200">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
                    <p className="text-sm font-medium text-gray-500">{brand_name || 'Brand'}</p>
                    <h1 className="mt-1 text-2xl font-bold text-gray-900">{name || 'Collection'}</h1>
                    {description && (
                        <p className="mt-2 text-sm text-gray-600 max-w-2xl">{description}</p>
                    )}
                </div>
            </header>

            <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                {!assets || assets.length === 0 ? (
                    <div className="text-center py-12 text-gray-500">
                        <DocumentIcon className="mx-auto h-12 w-12 text-gray-300" />
                        <p className="mt-2">No assets in this collection.</p>
                    </div>
                ) : (
                    <AssetGrid
                        assets={assets}
                        onAssetClick={handleAssetClick}
                        cardSize={220}
                        showInfo={true}
                        selectedAssetId={null}
                        primaryColor="#6366f1"
                    />
                )}
            </main>
        </div>
    )
}
