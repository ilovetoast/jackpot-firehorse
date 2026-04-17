/**
 * C12: Collection-only grid — same drawer, filters, and load-more behavior as internal collections;
 * permissions hide bulk/admin actions. Downloads allowed via RestrictCollectionOnlyUser whitelist.
 */
import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { usePage, router, Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AssetGrid from '../../Components/AssetGrid'
import AssetGridToolbar from '../../Components/AssetGridToolbar'
import AssetGridSecondaryFilters from '../../Components/AssetGridSecondaryFilters'
import AssetDrawer from '../../Components/AssetDrawer'
import SelectionActionBar from '../../Components/SelectionActionBar'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import CollectionFiltersBar from '../../Components/Collections/CollectionFiltersBar'
import { getWorkspaceButtonColor } from '../../utils/colorUtils'
import axios from 'axios'

const COLLECTION_ONLY_PARTIAL_RELOAD = [
    'assets',
    'next_page_url',
    'filtered_grid_total',
    'grid_folder_total',
    'q',
    'sort',
    'sort_direction',
    'group_by_category',
    'collection_type',
    'category_id',
    'filter_categories',
    'filterable_schema',
    'available_values',
    'filters',
]

const COLLECTION_GUEST_FILTER_URL_KEYS = ['collection_type', 'category_id']

export default function CollectionOnlyView({
    collection,
    assets = [],
    next_page_url = null,
    filtered_grid_total = 0,
    grid_folder_total = 0,
    sort = 'created',
    sort_direction = 'desc',
    q: searchQuery = '',
    collection_type = 'all',
    category_id: categoryFilterId = null,
    group_by_category = false,
    filter_categories = [],
    filterable_schema = [],
    available_values: availableValuesProp = {},
}) {
    const page = usePage()
    const { auth } = page.props
    const availableValues = availableValuesProp || page.props.available_values || {}
    const workspaceAccentColor = getWorkspaceButtonColor(auth?.activeBrand)
    const viewPath = route('collection-invite.view', { collection: collection.id })

    const [assetsList, setAssetsList] = useState(Array.isArray(assets) ? assets.filter(Boolean) : [])
    const [nextPageUrl, setNextPageUrl] = useState(next_page_url ?? null)
    const [loading, setLoading] = useState(false)
    const loadMoreRef = useRef(null)

    const [activeAssetId, setActiveAssetId] = useState(null)
    const [openDrawerWithZoom, setOpenDrawerWithZoom] = useState(false)

    useEffect(() => {
        setAssetsList(Array.isArray(assets) ? assets.filter(Boolean) : [])
        setNextPageUrl(next_page_url ?? null)
    }, [assets, next_page_url])

    const loadMore = useCallback(async () => {
        if (!nextPageUrl || loading) return
        setLoading(true)
        try {
            const url = nextPageUrl + (nextPageUrl.includes('?') ? '&' : '?') + 'load_more=1'
            const response = await axios.get(url)
            const data = response.data?.data ?? []
            setAssetsList((prev) => [...(prev || []).filter(Boolean), ...(Array.isArray(data) ? data.filter(Boolean) : [])])
            setNextPageUrl(response.data?.next_page_url ?? null)
        } catch (e) {
            console.error('Infinite scroll failed', e)
        } finally {
            setLoading(false)
        }
    }, [nextPageUrl, loading])

    useEffect(() => {
        if (!loadMoreRef.current) return
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting && nextPageUrl && !loading) loadMore()
            },
            { rootMargin: '200px' }
        )
        observer.observe(loadMoreRef.current)
        return () => observer.disconnect()
    }, [nextPageUrl, loading, loadMore])

    const safeAssetsList = (assetsList || []).filter(Boolean)
    const activeAsset = activeAssetId ? safeAssetsList.find((a) => a?.id === activeAssetId) : null

    useEffect(() => {
        if (activeAssetId && !safeAssetsList.some((a) => a?.id === activeAssetId)) {
            setActiveAssetId(null)
        }
    }, [activeAssetId, safeAssetsList])

    const categorySections = useMemo(() => {
        if (!group_by_category || !safeAssetsList.length) return null
        const map = new Map()
        for (const a of safeAssetsList) {
            const key = a.category?.id != null ? String(a.category.id) : 'uncategorized'
            const label = a.category?.name ?? 'Uncategorized'
            if (!map.has(key)) {
                map.set(key, { key, label, assets: [] })
            }
            map.get(key).assets.push(a)
        }
        const sections = Array.from(map.values())
        sections.sort((x, y) => {
            const ux = x.key === 'uncategorized' ? 1 : 0
            const uy = y.key === 'uncategorized' ? 1 : 0
            if (ux !== uy) return ux - uy
            return x.label.localeCompare(y.label, undefined, { sensitivity: 'base' })
        })
        return sections.length > 0 ? sections : null
    }, [group_by_category, safeAssetsList])

    const handleAssetGridClick = useCallback((asset) => {
        setOpenDrawerWithZoom(false)
        setActiveAssetId(asset?.id ?? null)
    }, [])
    const handleAssetDoubleClick = useCallback((asset) => {
        setOpenDrawerWithZoom(true)
        setActiveAssetId(asset?.id ?? null)
    }, [])
    const handleInitialZoomConsumed = useCallback(() => {
        setOpenDrawerWithZoom(false)
    }, [])

    const handleLifecycleUpdate = (updatedAsset) => {
        setAssetsList((prev) =>
            (prev || []).filter(Boolean).map((a) => (a?.id === updatedAsset?.id ? { ...a, ...updatedAsset } : a))
        )
    }

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
    const getStoredGridLayout = () => {
        if (typeof window === 'undefined') return 'grid'
        const v = localStorage.getItem('assetGridLayout')
        return v === 'masonry' ? 'masonry' : 'grid'
    }
    const [cardSize, setCardSize] = useState(getStoredCardSize)
    const [showInfo, setShowInfo] = useState(getStoredShowInfo)
    const [layoutMode, setLayoutMode] = useState(getStoredGridLayout)
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridCardSize', cardSize.toString())
    }, [cardSize])
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridShowInfo', showInfo.toString())
    }, [showInfo])
    useEffect(() => {
        if (typeof window !== 'undefined') localStorage.setItem('assetGridLayout', layoutMode)
    }, [layoutMode])

    const hasAssets = safeAssetsList.length > 0

    return (
        <div className="h-screen flex flex-col overflow-hidden">
            <AppHead title={collection?.name ? `${collection.name} — Collection` : 'Collection'} />
            <AppNav brand={auth?.activeBrand} tenant={null} />

            <div className="relative flex flex-1 overflow-hidden" style={{ height: 'calc(100vh - 5rem)' }}>
                <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-gray-50">
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <div className="py-6 px-4 sm:px-6 lg:px-8">
                            <div className="mb-4 flex items-center justify-between gap-4">
                                <div>
                                    <Link
                                        href={route('collection-invite.landing', { collection: collection.id })}
                                        className="text-sm text-gray-500 hover:text-gray-700"
                                    >
                                        ← Back to collection
                                    </Link>
                                    <h1 className="mt-1 text-2xl font-bold text-gray-900">{collection?.name}</h1>
                                    <p className="mt-1 text-sm text-gray-500">
                                        You can browse with the same filters as your team sees. Editing and internal tools
                                        require permissions you don&apos;t have on this account.
                                    </p>
                                </div>
                            </div>

                            <div className="mb-8">
                                <AssetGridToolbar
                                    showInfo={showInfo}
                                    onToggleInfo={() => setShowInfo((v) => !v)}
                                    cardSize={cardSize}
                                    onCardSizeChange={setCardSize}
                                    layoutMode={layoutMode}
                                    onLayoutModeChange={setLayoutMode}
                                    primaryColor={workspaceAccentColor}
                                    selectedCount={0}
                                    filterable_schema={filterable_schema}
                                    selectedCategoryId={categoryFilterId}
                                    available_values={availableValues}
                                    searchTagAutocompleteTenantId={auth?.activeCompany?.id}
                                    searchPlaceholder="Search items, titles, tags…"
                                    clearFiltersCollectionsView
                                    clearFiltersInertiaOnly={COLLECTION_ONLY_PARTIAL_RELOAD}
                                    filterUrlNavigationKeys={COLLECTION_GUEST_FILTER_URL_KEYS}
                                    beforeSearchSlot={
                                        <CollectionFiltersBar
                                            collectionId={collection.id}
                                            collectionType={collection_type}
                                            categoryId={categoryFilterId}
                                            filterCategories={filter_categories}
                                            primaryColor={workspaceAccentColor}
                                        />
                                    }
                                    sortBy={sort}
                                    sortDirection={sort_direction}
                                    searchQuery={searchQuery}
                                    inertiaSearchOnly={COLLECTION_ONLY_PARTIAL_RELOAD}
                                    onSortChange={
                                        group_by_category
                                            ? null
                                            : (newSort, newDir) => {
                                                  const urlParams = new URLSearchParams(window.location.search)
                                                  urlParams.set('sort', newSort)
                                                  urlParams.set('sort_direction', newDir)
                                                  router.get(viewPath, Object.fromEntries(urlParams), {
                                                      preserveState: true,
                                                      preserveScroll: true,
                                                      only: COLLECTION_ONLY_PARTIAL_RELOAD,
                                                  })
                                              }
                                    }
                                    showMoreFilters
                                    moreFiltersContent={
                                        <AssetGridSecondaryFilters
                                            filterable_schema={filterable_schema}
                                            selectedCategoryId={categoryFilterId}
                                            available_values={availableValues}
                                            canManageFields={false}
                                            assetType={collection_type === 'deliverable' ? 'document' : 'image'}
                                            filterUrlNavigationKeys={COLLECTION_GUEST_FILTER_URL_KEYS}
                                            clearFiltersCollectionsView
                                            inertiaPartialReloadKeys={COLLECTION_ONLY_PARTIAL_RELOAD}
                                            primaryColor={workspaceAccentColor}
                                            sortBy={sort}
                                            sortDirection={sort_direction}
                                            onSortChange={
                                                group_by_category
                                                    ? null
                                                    : (newSort, newDir) => {
                                                          const urlParams = new URLSearchParams(window.location.search)
                                                          urlParams.set('sort', newSort)
                                                          urlParams.set('sort_direction', newDir)
                                                          router.get(viewPath, Object.fromEntries(urlParams), {
                                                              preserveState: true,
                                                              preserveScroll: true,
                                                              only: COLLECTION_ONLY_PARTIAL_RELOAD,
                                                          })
                                                      }
                                            }
                                            assetResultCount={safeAssetsList?.length ?? 0}
                                            totalInCategory={safeAssetsList?.length ?? 0}
                                            filteredGridTotal={typeof filtered_grid_total === 'number' ? filtered_grid_total : null}
                                            gridFolderTotal={typeof grid_folder_total === 'number' ? grid_folder_total : null}
                                            hasMoreAvailable={!!nextPageUrl}
                                        />
                                    }
                                />
                            </div>

                            {hasAssets ? (
                                <>
                                    {categorySections ? (
                                        <div className="space-y-10">
                                            {categorySections.map((sec) => (
                                                <section key={sec.key}>
                                                    <h3 className="mb-4 border-b border-gray-100 px-0.5 pb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                        {sec.label}
                                                    </h3>
                                                    <AssetGrid
                                                        assets={sec.assets}
                                                        onAssetClick={handleAssetGridClick}
                                                        onAssetDoubleClick={handleAssetDoubleClick}
                                                        cardSize={cardSize}
                                                        layoutMode={layoutMode}
                                                        cardStyle={
                                                            (auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact'
                                                                ? 'default'
                                                                : 'guidelines'
                                                        }
                                                        showInfo={showInfo}
                                                        selectedAssetId={activeAssetId}
                                                        primaryColor={workspaceAccentColor}
                                                        selectedAssetIds={[]}
                                                        onAssetSelect={null}
                                                        selectionAssetType="asset"
                                                    />
                                                </section>
                                            ))}
                                        </div>
                                    ) : (
                                        <AssetGrid
                                            assets={safeAssetsList}
                                            onAssetClick={handleAssetGridClick}
                                            onAssetDoubleClick={handleAssetDoubleClick}
                                            cardSize={cardSize}
                                            layoutMode={layoutMode}
                                            cardStyle={
                                                (auth?.activeBrand?.asset_grid_style ?? 'clean') === 'impact'
                                                    ? 'default'
                                                    : 'guidelines'
                                            }
                                            showInfo={showInfo}
                                            selectedAssetId={activeAssetId}
                                            primaryColor={workspaceAccentColor}
                                            selectedAssetIds={[]}
                                            onAssetSelect={null}
                                            selectionAssetType="asset"
                                        />
                                    )}
                                    {nextPageUrl ? <div ref={loadMoreRef} className="h-10" aria-hidden="true" /> : null}
                                    {loading && (
                                        <div className="flex justify-center py-6">
                                            <svg
                                                className="h-8 w-8 animate-spin text-indigo-600"
                                                xmlns="http://www.w3.org/2000/svg"
                                                fill="none"
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path
                                                    className="opacity-75"
                                                    fill="currentColor"
                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                                />
                                            </svg>
                                        </div>
                                    )}
                                    {nextPageUrl && (
                                        <LoadMoreFooter onLoadMore={loadMore} hasMore={!!nextPageUrl} isLoading={loading} />
                                    )}
                                </>
                            ) : (
                                <div className="mx-auto max-w-2xl py-16 text-center text-gray-500">
                                    <p>No assets match your filters, or this collection is empty.</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                {/* Asset Drawer — single instance, portals to document.body */}
                {activeAssetId && (
                    <AssetDrawer
                        key={activeAssetId}
                        asset={activeAsset}
                        externalCollectionGuest
                        onClose={() => {
                            setActiveAssetId(null)
                            setOpenDrawerWithZoom(false)
                        }}
                        assets={safeAssetsList}
                        currentAssetIndex={activeAsset ? safeAssetsList.findIndex((a) => a?.id === activeAsset?.id) : -1}
                        onAssetUpdate={handleLifecycleUpdate}
                        selectionAssetType="asset"
                        initialZoomOpen={openDrawerWithZoom}
                        onInitialZoomConsumed={handleInitialZoomConsumed}
                        collectionContext={{ show: false }}
                    />
                )}
            </div>

            <SelectionActionBar
                currentPageIds={safeAssetsList.map((a) => a.id)}
                currentPageItems={safeAssetsList.map((a) => ({
                    id: a.id,
                    type: 'asset',
                    name: a.title ?? a.original_filename ?? '',
                    thumbnail_url: a.final_thumbnail_url ?? a.thumbnail_url ?? a.preview_thumbnail_url ?? null,
                    category_id: a.metadata?.category_id ?? a.category_id ?? null,
                }))}
                onOpenBulkEdit={null}
                createDownloadSource="collection"
                collectionId={collection.id}
            />
        </div>
    )
}
