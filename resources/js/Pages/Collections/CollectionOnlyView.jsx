/**
 * C12: Collection-only view — read-only view of a single collection and its assets.
 * For users with collection access grant only (no brand membership).
 */
import { useState } from 'react'
import { usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import { Link } from '@inertiajs/react'

export default function CollectionOnlyView({ collection, assets = [] }) {
    const { auth } = usePage().props
    const [cardSize, setCardSize] = useState(() => {
        if (typeof window === 'undefined') return 220
        const stored = localStorage.getItem('assetGridCardSize')
        return stored ? parseInt(stored, 10) : 220
    })
    const [showInfo, setShowInfo] = useState(() => {
        if (typeof window === 'undefined') return true
        const stored = localStorage.getItem('assetGridShowInfo')
        return stored ? stored === 'true' : true
    })

    const handleAssetClick = (asset) => {
        if (!asset?.id) return
        window.open(route('assets.view', { asset: asset.id }), '_blank', 'noopener,noreferrer')
    }

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppNav brand={auth?.activeBrand} tenant={null} />

            <div className="flex-1 overflow-hidden bg-gray-50">
                <div className="h-full overflow-y-auto">
                    <div className="py-6 px-4 sm:px-6 lg:px-8">
                        <div className="mb-4 flex items-center justify-between">
                            <div>
                                <Link
                                    href={route('collection-invite.landing', { collection: collection.id })}
                                    className="text-sm text-gray-500 hover:text-gray-700"
                                >
                                    ← Back to collection
                                </Link>
                                <h1 className="mt-1 text-2xl font-bold text-gray-900">{collection?.name}</h1>
                                <p className="mt-1 text-sm text-gray-500">
                                    Collection-only access. You can view and download assets.
                                </p>
                            </div>
                        </div>

                        <div className="mb-4">
                            <AssetGridToolbar
                                showInfo={showInfo}
                                onToggleInfo={() => setShowInfo((v) => !v)}
                                cardSize={cardSize}
                                onCardSizeChange={setCardSize}
                                primaryColor="#6366f1"
                                selectedCount={0}
                                filterable_schema={[]}
                                selectedCategoryId={null}
                                available_values={{}}
                                showMoreFilters={false}
                            />
                        </div>

                        {assets && assets.length > 0 ? (
                            <AssetGrid
                                assets={assets}
                                onAssetClick={handleAssetClick}
                                cardSize={cardSize}
                                cardStyle={(auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact' ? 'default' : 'guidelines'}
                                showInfo={showInfo}
                                selectedAssetId={null}
                                primaryColor="#6366f1"
                            />
                        ) : (
                            <div className="py-12 text-center text-gray-500">
                                <p>This collection has no assets yet.</p>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}
