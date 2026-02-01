/**
 * Collections Index (C4 read-only UI; C5 create + add/remove assets).
 * Uses CollectionAssetQueryService for asset data; C5 adds create and assign UI.
 */
import { useState, useEffect, useCallback } from 'react'
import { usePage, router } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import CollectionsSidebar from '../../Components/Collections/CollectionsSidebar'
import CollectionPublicBar from '../../Components/Collections/CollectionPublicBar'
import CreateCollectionModal from '../../Components/Collections/CreateCollectionModal'
import EditCollectionModal from '../../Components/Collections/EditCollectionModal'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetDrawer from '../../Components/AssetDrawer'
import DownloadBucketBar from '../../Components/DownloadBucketBar'
import { RectangleStackIcon, FolderIcon } from '@heroicons/react/24/outline'

export default function CollectionsIndex({
    collections = [],
    assets = [],
    selected_collection = null,
    can_update_collection = false,
    can_create_collection = false,
    can_add_to_collection = false,
    can_remove_from_collection = false,
    public_collections_enabled = false,
}) {
    const { auth, download_bucket_count } = usePage().props
    const selectedCollectionId = selected_collection?.id ?? null

    const sidebarColor = auth.activeBrand?.nav_color || auth.activeBrand?.primary_color || '#1f2937'
    const isLightColor = (color) => {
        if (!color || color === '#ffffff' || color === '#FFFFFF') return true
        const hex = color.replace('#', '')
        const r = parseInt(hex.substr(0, 2), 16)
        const g = parseInt(hex.substr(2, 2), 16)
        const b = parseInt(hex.substr(4, 2), 16)
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255
        return luminance > 0.5
    }
    const textColor = isLightColor(sidebarColor) ? '#000000' : '#ffffff'

    const [localAssets, setLocalAssets] = useState(assets)
    useEffect(() => {
        setLocalAssets(assets)
    }, [assets])

    const [showCreateModal, setShowCreateModal] = useState(false)
    const [showEditModal, setShowEditModal] = useState(false)
    const [activeAssetId, setActiveAssetId] = useState(null)
    const activeAsset = activeAssetId ? localAssets.find((a) => a.id === activeAssetId) : null

    const handleCollectionCreated = (newCollection) => {
        router.get('/app/collections', { collection: newCollection.id }, { preserveState: false })
    }

    useEffect(() => {
        if (activeAssetId && !localAssets.some((a) => a.id === activeAssetId)) {
            setActiveAssetId(null)
        }
    }, [activeAssetId, localAssets])

    useEffect(() => {
        setActiveAssetId(null)
    }, [selectedCollectionId])

    // Phase D1: Download bucket — same as Assets/Index; persists across /app/assets, /app/deliverables, /app/collections (session)
    const [bucketAssetIds, setBucketAssetIds] = useState([])
    useEffect(() => {
        if (typeof window === 'undefined' || !window.route) return
        fetch(route('download-bucket.items'), {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.ok ? r.json() : Promise.reject(new Error('Failed to load bucket')))
            .then((data) => {
                const ids = (data.items || []).map((i) => (typeof i === 'string' ? i : i.id))
                setBucketAssetIds(ids)
            })
            .catch(() => setBucketAssetIds([]))
    }, [selectedCollectionId])

    useEffect(() => {
        const count = download_bucket_count ?? 0
        if (typeof count === 'number' && count === 0 && bucketAssetIds.length > 0) {
            setBucketAssetIds([])
        }
    }, [download_bucket_count])

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || ''
    const applyBucketResponse = useCallback((data) => {
        const ids = (data?.items || []).map((i) => (typeof i === 'string' ? i : i.id))
        setBucketAssetIds(ids)
    }, [])
    const bucketAdd = useCallback((assetId) => {
        return fetch(route('download-bucket.add'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ asset_id: assetId }),
        })
            .then((r) => r.json().catch(() => ({})))
            .then((data) => {
                if (Array.isArray(data?.items) || typeof data?.count === 'number') applyBucketResponse(data)
            })
    }, [applyBucketResponse])
    const bucketRemove = useCallback((assetId) => {
        return fetch(route('download-bucket.remove', { asset: assetId }), {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then((r) => r.json()).then(applyBucketResponse)
    }, [applyBucketResponse])
    const bucketClear = useCallback(() => {
        return fetch(route('download-bucket.clear'), {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then((r) => r.json()).then(applyBucketResponse)
    }, [applyBucketResponse])
    const handleBucketToggle = useCallback((assetId) => {
        if (bucketAssetIds.includes(assetId)) bucketRemove(assetId)
        else bucketAdd(assetId)
    }, [bucketAssetIds, bucketAdd, bucketRemove])

    const getStoredCardSize = () => {
        if (typeof window === 'undefined') return 220
        const stored = localStorage.getItem('assetGridCardSize')
        return stored ? parseInt(stored, 10) : 220
    }
    const getStoredShowInfo = () => {
        if (typeof window === 'undefined') return true
        const stored = localStorage.getItem('assetGridShowInfo')
        return stored ? stored === 'true' : true
    }
    const [cardSize, setCardSize] = useState(getStoredCardSize)
    const [showInfo, setShowInfo] = useState(getStoredShowInfo)
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridCardSize', cardSize.toString())
    }, [cardSize])
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridShowInfo', showInfo.toString())
    }, [showInfo])

    const handleLifecycleUpdate = (updatedAsset) => {
        setLocalAssets((prev) =>
            prev.map((a) => (a.id === updatedAsset?.id ? { ...a, ...updatedAsset } : a))
        )
    }

    const showGrid = selectedCollectionId != null
    const hasAssets = localAssets && localAssets.length > 0

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppNav brand={auth.activeBrand} tenant={null} />

            <div className="flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                {/* Sidebar */}
                <div className="hidden lg:flex lg:flex-shrink-0">
                    <CollectionsSidebar
                        collections={collections}
                        selectedCollectionId={selectedCollectionId}
                        sidebarColor={sidebarColor}
                        textColor={textColor}
                        canCreateCollection={can_create_collection}
                        onCreateCollection={() => setShowCreateModal(true)}
                        publicCollectionsEnabled={public_collections_enabled}
                    />
                </div>

                <CreateCollectionModal
                    open={showCreateModal}
                    onClose={() => setShowCreateModal(false)}
                    onCreated={handleCollectionCreated}
                />
                <EditCollectionModal
                    open={showEditModal}
                    collection={selected_collection}
                    publicCollectionsEnabled={public_collections_enabled}
                    onClose={() => setShowEditModal(false)}
                    onSaved={() => {
                        setShowEditModal(false)
                        router.reload()
                    }}
                />

                {/* Main content */}
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative">
                    <div className={`h-full overflow-y-auto ${bucketAssetIds.length > 0 ? 'pb-24' : ''}`}>
                        <div className="py-6 px-4 sm:px-6 lg:px-8">
                            {!showGrid ? (
                                /* No collection selected: show "No collections yet" or "Select a collection" */
                                collections.length === 0 ? (
                                    <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                        <div className="mb-8">
                                            <RectangleStackIcon className="mx-auto h-16 w-16 text-gray-300" />
                                        </div>
                                        <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                            No collections yet
                                        </h2>
                                        <p className="mt-4 text-base leading-7 text-gray-600">
                                            Collections let you group and share assets. No collections have been created yet.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                        <div className="mb-8">
                                            <RectangleStackIcon className="mx-auto h-16 w-16 text-gray-300" />
                                        </div>
                                        <h2 className="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                            Select a collection
                                        </h2>
                                        <p className="mt-4 text-base leading-7 text-gray-600">
                                            Choose a collection from the sidebar to view its assets.
                                        </p>
                                    </div>
                                )
                            ) : (
                                <>
                                    {/* C10: Collection bar: name + Public toggle (only when feature enabled) */}
                                    {selected_collection && (
                                        <CollectionPublicBar
                                            collection={selected_collection}
                                            publicCollectionsEnabled={public_collections_enabled}
                                            assetCount={localAssets.length}
                                            canUpdateCollection={can_update_collection}
                                            onEditClick={() => setShowEditModal(true)}
                                            onPublicChange={() => {
                                                router.reload()
                                            }}
                                        />
                                    )}
                                    <div className="mb-8">
                                        <AssetGridToolbar
                                            showInfo={showInfo}
                                            onToggleInfo={() => setShowInfo((v) => !v)}
                                            cardSize={cardSize}
                                            onCardSizeChange={setCardSize}
                                            primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                            bulkSelectedCount={0}
                                            onBulkEdit={null}
                                            onToggleBulkMode={null}
                                            isBulkMode={false}
                                            filterable_schema={[]}
                                            selectedCategoryId={null}
                                            available_values={{}}
                                            showMoreFilters={false}
                                        />
                                    </div>

                                    {hasAssets ? (
                                        <AssetGrid
                                            assets={localAssets}
                                            onAssetClick={(asset) => setActiveAssetId(asset?.id ?? null)}
                                            cardSize={cardSize}
                                            showInfo={showInfo}
                                            selectedAssetId={activeAssetId}
                                            primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                            selectedAssetIds={[]}
                                            onAssetSelect={null}
                                            bucketAssetIds={bucketAssetIds}
                                            onBucketToggle={handleBucketToggle}
                                        />
                                    ) : (
                                        /* Empty state: collection selected but no assets */
                                        <div className="max-w-2xl mx-auto py-16 px-6 text-center">
                                            <FolderIcon className="mx-auto h-16 w-16 text-gray-300" />
                                            <h2 className="mt-4 text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">
                                                This collection doesn&apos;t contain any assets yet.
                                            </h2>
                                            <p className="mt-4 text-base leading-7 text-gray-600">
                                                Assets added to this collection will appear here.
                                            </p>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    <DownloadBucketBar
                        bucketCount={bucketAssetIds.length}
                        onCountChange={() => setBucketAssetIds([])}
                        onRemove={bucketRemove}
                        onClear={bucketClear}
                    />

                    {/* Asset Drawer - Desktop */}
                    {activeAssetId && (
                        <div className="hidden md:block absolute right-0 top-0 bottom-0 z-50">
                            <AssetDrawer
                                key={activeAssetId}
                                asset={activeAsset}
                                onClose={() => setActiveAssetId(null)}
                                assets={localAssets}
                                currentAssetIndex={activeAsset ? localAssets.findIndex((a) => a.id === activeAsset.id) : -1}
                                onAssetUpdate={handleLifecycleUpdate}
                                bucketAssetIds={bucketAssetIds}
                                onBucketToggle={handleBucketToggle}
                                collectionContext={{
                                    show: true,
                                    selectedCollectionId,
                                    canAddToCollection: can_add_to_collection,
                                    canRemoveFromCollection: can_remove_from_collection,
                                    canCreateCollection: can_create_collection,
                                    onOpenCreateCollection: () => setShowCreateModal(true),
                                    onAssetRemovedFromCollection: (assetId, collectionId) => {
                                        if (collectionId === selectedCollectionId) {
                                            setLocalAssets((prev) => prev.filter((a) => a.id !== assetId))
                                            setActiveAssetId(null)
                                        }
                                    },
                                }}
                            />
                        </div>
                    )}
                </div>
            </div>

            {/* Asset Drawer - Mobile */}
            {activeAssetId && (
                <div className="md:hidden fixed inset-0 z-50">
                    <div className="absolute inset-0 bg-black/50" onClick={() => setActiveAssetId(null)} aria-hidden="true" />
                    <AssetDrawer
                        key={activeAssetId}
                        asset={activeAsset}
                        onClose={() => setActiveAssetId(null)}
                        assets={localAssets}
                        currentAssetIndex={activeAsset ? localAssets.findIndex((a) => a.id === activeAsset.id) : -1}
                        onAssetUpdate={handleLifecycleUpdate}
                        bucketAssetIds={bucketAssetIds}
                        onBucketToggle={handleBucketToggle}
                        collectionContext={{
                            show: true,
                            selectedCollectionId,
                            canAddToCollection: can_add_to_collection,
                            canRemoveFromCollection: can_remove_from_collection,
                            canCreateCollection: can_create_collection,
                            onOpenCreateCollection: () => setShowCreateModal(true),
                            onAssetRemovedFromCollection: (assetId, collectionId) => {
                                if (collectionId === selectedCollectionId) {
                                    setLocalAssets((prev) => prev.filter((a) => a.id !== assetId))
                                    setActiveAssetId(null)
                                }
                            },
                        }}
                    />
                </div>
            )}
        </div>
    )
}
