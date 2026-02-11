/**
 * Collections Index (C4 read-only UI; C5 create + add/remove assets).
 * Uses CollectionAssetQueryService for asset data; C5 adds create and assign UI.
 */
import { useState, useEffect, useCallback, useMemo } from 'react'
import { usePage, router } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import CollectionsSidebar from '../../Components/Collections/CollectionsSidebar'
import CollectionPublicBar from '../../Components/Collections/CollectionPublicBar'
import CreateCollectionModal from '../../Components/Collections/CreateCollectionModal'
import EditCollectionModal from '../../Components/Collections/EditCollectionModal'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import { useBucket } from '../../contexts/BucketContext'
import { RectangleStackIcon, PlusIcon, GlobeAltIcon } from '@heroicons/react/24/outline'
import { useInfiniteLoad } from '../../hooks/useInfiniteLoad'
import LoadMoreFooter from '../../Components/LoadMoreFooter'

export default function CollectionsIndex({
    collections = [],
    assets = [],
    selected_collection = null,
    can_update_collection = false,
    can_create_collection = false,
    can_add_to_collection = false,
    can_remove_from_collection = false,
    public_collections_enabled = false,
    sort = 'created',
    sort_direction = 'desc',
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

    // Incremental load: show 24 initially, load more on scroll or button click
    const infiniteResetDeps = [selectedCollectionId, typeof window !== 'undefined' ? window.location.search : '']
    const { visibleItems, loadMore, hasMore } = useInfiniteLoad(localAssets, 24, infiniteResetDeps)

    const [showCreateModal, setShowCreateModal] = useState(false)
    const [showEditModal, setShowEditModal] = useState(false)
    const [mobileCollectionsOpen, setMobileCollectionsOpen] = useState(false)
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

    // Phase D1: Download bucket from app-level context so the bar does not remount on collection/category change (no flash)
    const { bucketAssetIds, bucketAdd, bucketRemove, bucketClear, bucketAddBatch, clearIfEmpty } = useBucket()

    useEffect(() => {
        clearIfEmpty(download_bucket_count ?? 0)
    }, [download_bucket_count, clearIfEmpty])

    const handleBucketToggle = useCallback((assetId) => {
        if (bucketAssetIds.includes(assetId)) bucketRemove(assetId)
        else bucketAdd(assetId)
    }, [bucketAssetIds, bucketAdd, bucketRemove])

    const visibleIds = useMemo(() => (localAssets || []).map((a) => a.id).filter(Boolean), [localAssets])
    const allVisibleInBucket = visibleIds.length > 0 && visibleIds.every((id) => bucketAssetIds.includes(id))
    const handleSelectAllToggle = useCallback(async () => {
        if (visibleIds.length === 0) return
        if (allVisibleInBucket) {
            for (const id of visibleIds) {
                await bucketRemove(id)
            }
        } else {
            await bucketAddBatch(visibleIds)
        }
    }, [visibleIds, allVisibleInBucket, bucketAddBatch, bucketRemove])

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

                {/* Mobile: Collections slide-out (visible when lg:hidden) */}
                {mobileCollectionsOpen && (
                    <>
                        <div className="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden" aria-hidden="true" onClick={() => setMobileCollectionsOpen(false)} />
                        <div className="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] flex flex-col shadow-xl lg:hidden" style={{ backgroundColor: sidebarColor, top: '5rem' }} role="dialog" aria-modal="true" aria-label="Collections">
                            <div className="flex items-center justify-between h-14 px-4 border-b shrink-0" style={{ borderColor: textColor === '#ffffff' ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.1)' }}>
                                <span className="text-sm font-semibold" style={{ color: textColor }}>Collections</span>
                                <button type="button" onClick={() => setMobileCollectionsOpen(false)} className="rounded-md p-2 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white/50" style={{ color: textColor }} aria-label="Close collections">
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <div className="flex-1 overflow-y-auto py-4 px-2">
                                <div className="flex items-center justify-between px-2 mb-3">
                                    {can_create_collection && (
                                        <button type="button" onClick={() => { setShowCreateModal(true); setMobileCollectionsOpen(false) }} className="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg bg-white/20 hover:bg-white/30 text-white focus:outline-none focus:ring-2 focus:ring-white/50">
                                            <PlusIcon className="h-4 w-4" /> Create collection
                                        </button>
                                    )}
                                </div>
                                <div className="space-y-0.5">
                                    {collections.length === 0 ? (
                                        <div className="px-3 py-2 text-sm opacity-80" style={{ color: textColor }}>No collections yet</div>
                                    ) : (
                                        collections.map((c) => {
                                            const isActive = selectedCollectionId != null && c.id === selectedCollectionId
                                            const showPublic = public_collections_enabled && !!c.is_public
                                            const count = typeof c.assets_count === 'number' ? c.assets_count : null
                                            return (
                                                <button key={c.id} type="button" onClick={() => { router.get('/app/collections', { collection: c.id }, { preserveState: true }); setMobileCollectionsOpen(false) }} className={`flex items-center w-full px-3 py-2.5 text-sm font-medium rounded-lg text-left gap-2 ${isActive ? (textColor === '#000000' ? 'bg-black/10 text-black' : 'bg-white/20 text-white') : (textColor === '#000000' ? 'text-gray-800 hover:bg-black/5' : 'text-white/90 hover:bg-white/10')}`} style={textColor === '#000000' ? {} : { color: isActive ? '#fff' : 'rgba(255,255,255,0.9)' }}>
                                                    <RectangleStackIcon className="h-4 w-4 flex-shrink-0" />
                                                    <span className="truncate flex-1 min-w-0">{c.name}</span>
                                                    {showPublic && <GlobeAltIcon className="h-4 w-4 flex-shrink-0 opacity-80" title="Public" aria-hidden="true" />}
                                                    {count !== null && <span className="text-xs opacity-80">{count}</span>}
                                                </button>
                                            )
                                        })
                                    )}
                                </div>
                            </div>
                        </div>
                    </>
                )}

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
                <div className="flex-1 overflow-hidden bg-gray-50 h-full relative flex flex-col">
                    <div className="lg:hidden flex items-center gap-2 py-2 px-4 sm:px-6 border-b border-gray-200 bg-white/80 backdrop-blur-sm shrink-0">
                        <button type="button" onClick={() => setMobileCollectionsOpen(true)} className="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg bg-white border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2">
                            <RectangleStackIcon className="h-5 w-5 text-gray-600" />
                            <span>Collections</span>
                            {selected_collection && <span className="text-gray-500 truncate max-w-[120px]">— {selected_collection.name}</span>}
                        </button>
                    </div>
                    <div className={`flex-1 min-h-0 overflow-y-auto ${bucketAssetIds.length > 0 ? 'pb-24' : ''}`}>
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
                                            sortBy={sort}
                                            sortDirection={sort_direction}
                                            onSortChange={(newSort, newDir) => {
                                                const urlParams = new URLSearchParams(window.location.search)
                                                urlParams.set('sort', newSort)
                                                urlParams.set('sort_direction', newDir)
                                                router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'sort', 'sort_direction'] })
                                            }}
                                            showMoreFilters={true}
                                            moreFiltersContent={
                                                <AssetGridSecondaryFilters
                                                    filterable_schema={[]}
                                                    selectedCategoryId={null}
                                                    available_values={{}}
                                                    canManageFields={false}
                                                    assetType="image"
                                                    primaryColor={auth.activeBrand?.primary_color || '#6366f1'}
                                                    sortBy={sort}
                                                    sortDirection={sort_direction}
                                                    onSortChange={(newSort, newDir) => {
                                                        const urlParams = new URLSearchParams(window.location.search)
                                                        urlParams.set('sort', newSort)
                                                        urlParams.set('sort_direction', newDir)
                                                        router.get(window.location.pathname, Object.fromEntries(urlParams), { preserveState: true, preserveScroll: true, only: ['assets', 'sort', 'sort_direction'] })
                                                    }}
                                                    assetResultCount={visibleItems?.length ?? 0}
                                                    totalInCategory={localAssets?.length ?? 0}
                                                    barTrailingContent={
                                                        hasAssets ? (
                                                            <div className="flex items-center gap-2">
                                                                <button
                                                                    type="button"
                                                                    onClick={handleSelectAllToggle}
                                                                    className="px-2 py-1 text-xs font-medium text-gray-600 bg-white border border-gray-300 rounded hover:bg-gray-50"
                                                                >
                                                                    {allVisibleInBucket ? 'Deselect all' : 'Select all'}
                                                                </button>
                                                            </div>
                                                        ) : null
                                                    }
                                                />
                                            }
                                        />
                                    </div>

                                    {hasAssets ? (
                                        <>
                                        <AssetGrid
                                            assets={visibleItems}
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
                                        <LoadMoreFooter onLoadMore={loadMore} hasMore={hasMore} />
                                        </>
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

                    {/* Download bucket bar is mounted at app level (DownloadBucketBarGlobal) so it doesn't flash on collection change */}

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
