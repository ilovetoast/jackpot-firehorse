/**
 * Password-protected share collection (guests): press-kit style gallery.
 * Filters: GET ?q=&type=&sort=&view= on guest_collection_path.
 */
import { useState, useCallback, useMemo, useRef, useEffect } from 'react'
import { Head, router } from '@inertiajs/react'
import {
    DocumentIcon,
    ArrowDownTrayIcon,
    XMarkIcon,
    LockClosedIcon,
    Squares2X2Icon,
    ListBulletIcon,
} from '@heroicons/react/24/outline'
import AssetGrid from '../../Components/AssetGrid'
import { useCdn403Recovery } from '../../hooks/useCdn403Recovery'

function formatBytes(n) {
    if (n == null || Number.isNaN(Number(n))) return ''
    const v = Number(n)
    if (v < 1024) return `${v} B`
    if (v < 1024 * 1024) return `${(v / 1024).toFixed(1)} KB`
    return `${(v / (1024 * 1024)).toFixed(1)} MB`
}

export default function PublicCollection({
    collection = {},
    assets = [],
    public_collection_downloads_enabled: downloadCollectionEnabled = false,
    branding_options = {},
    cdn_domain = null,
    share_download_post_path: shareDownloadPostPath = null,
    guest_collection_path: guestCollectionPath = '',
    guest_query: guestQueryProp = {},
    guest_collection_asset_total: collectionAssetTotal = 0,
    guest_filtered_total: filteredTotal = 0,
    guest_showing_count: showingCount = 0,
    guest_asset_limit: guestAssetLimit = 500,
}) {
    useCdn403Recovery(cdn_domain)
    const { name, description, brand_name } = collection
    const accentColor = branding_options?.accent_color || branding_options?.primary_color || '#4F46E5'
    const primaryColor = branding_options?.primary_color || accentColor
    const logoUrl = branding_options?.logo_url || null
    const backgroundImageUrl = branding_options?.background_image_url || null
    const themeDark = branding_options?.theme_dark ?? false
    const hasBackground = !!backgroundImageUrl

    const q = typeof guestQueryProp?.q === 'string' ? guestQueryProp.q : ''
    const type = typeof guestQueryProp?.type === 'string' ? guestQueryProp.type : 'all'
    const sort = typeof guestQueryProp?.sort === 'string' ? guestQueryProp.sort : 'newest'
    const view = guestQueryProp?.view === 'list' ? 'list' : 'grid'

    const [downloadPanelOpen, setDownloadPanelOpen] = useState(false)
    const [downloadPanelMode, setDownloadPanelMode] = useState('all') // 'all' | 'selected'
    const [downloadSubmitting, setDownloadSubmitting] = useState(false)
    const [downloadError, setDownloadError] = useState(null)
    const [selectedIds, setSelectedIds] = useState(() => new Set())
    const searchInputRef = useRef(null)

    useEffect(() => {
        setSelectedIds(new Set())
    }, [q, type, sort, view, guestCollectionPath])

    useEffect(() => {
        if (searchInputRef.current) {
            searchInputRef.current.value = q
        }
    }, [q])

    const navigateGuestQuery = useCallback(
        (patch) => {
            if (!guestCollectionPath) return
            const next = {
                q: patch.q !== undefined ? patch.q : q,
                type: patch.type !== undefined ? patch.type : type,
                sort: patch.sort !== undefined ? patch.sort : sort,
                view: patch.view !== undefined ? patch.view : view,
            }
            const params = {}
            if (next.q && String(next.q).trim()) params.q = String(next.q).trim()
            if (next.type && next.type !== 'all') params.type = next.type
            if (next.sort && next.sort !== 'newest') params.sort = next.sort
            if (next.view && next.view !== 'grid') params.view = next.view
            router.get(guestCollectionPath, params, {
                preserveState: true,
                preserveScroll: true,
            })
        },
        [guestCollectionPath, q, type, sort, view]
    )

    const downloadPostUrl =
        shareDownloadPostPath ||
        (collection?.brand_slug && collection?.slug
            ? `/b/${collection.brand_slug}/collections/${collection.slug}/download`
            : '')

    const runZipDownload = useCallback(
        async (assetIds) => {
            setDownloadError(null)
            setDownloadSubmitting(true)
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                if (!downloadPostUrl) {
                    throw new Error('Download is not available.')
                }
                const body = {}
                if (Array.isArray(assetIds) && assetIds.length > 0) {
                    body.asset_ids = assetIds
                }
                const res = await fetch(downloadPostUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(body),
                })
                const data = await res.json().catch(() => ({}))
                if (!res.ok) {
                    throw new Error(data.message || 'Download failed')
                }
                if (data.zip_url) {
                    window.open(data.zip_url, '_blank', 'noopener,noreferrer')
                }
                setDownloadPanelOpen(false)
            } catch (err) {
                setDownloadError(err.message || 'Failed to prepare download. Please try again.')
            } finally {
                setDownloadSubmitting(false)
            }
        },
        [downloadPostUrl]
    )

    const openDownloadPanel = (mode) => {
        setDownloadPanelMode(mode)
        setDownloadError(null)
        setDownloadPanelOpen(true)
    }

    const handleDownloadPanelConfirm = () => {
        if (downloadPanelMode === 'selected') {
            const ids = [...selectedIds]
            if (ids.length === 0) return
            runZipDownload(ids)
        } else {
            runZipDownload(null)
        }
    }

    const bucketIdsList = useMemo(() => {
        const set = selectedIds
        return (assets || []).filter((a) => set.has(String(a.id))).map((a) => a.id)
    }, [assets, selectedIds])

    const toggleBucket = useCallback((id) => {
        const sid = String(id)
        setSelectedIds((prev) => {
            const n = new Set(prev)
            if (n.has(sid)) n.delete(sid)
            else n.add(sid)
            return n
        })
    }, [])

    const selectAllVisible = () => {
        setSelectedIds(new Set((assets || []).map((a) => String(a.id))))
    }

    const clearSelection = () => setSelectedIds(new Set())

    const emptyCollection = collectionAssetTotal === 0
    const noMatches = !emptyCollection && filteredTotal === 0
    const cappedList = filteredTotal > guestAssetLimit

    const baseBg = themeDark ? '#0a0a0a' : '#ffffff'
    const gradientFrom = themeDark ? 'rgba(10,10,10,0.3)' : 'rgba(255,255,255,0.4)'
    const gradientTo = themeDark ? 'rgba(10,10,10,0.95)' : 'rgba(255,255,255,0.95)'
    const textColor = themeDark ? 'text-white' : 'text-gray-900'
    const textMuted = themeDark ? 'text-white/80' : 'text-gray-600'
    const textMutedLight = themeDark ? 'text-white/60' : 'text-gray-500'
    const chromeBorder = themeDark ? 'border-white/15' : 'border-gray-200'
    const chromeBg = themeDark ? 'bg-white/5' : 'bg-white'

    const handleAssetClick = (asset) => {
        if (!downloadCollectionEnabled || !asset.download_url) return
        const a = document.createElement('a')
        a.href = asset.download_url
        a.download = asset.original_filename || asset.title || 'download'
        a.style.display = 'none'
        document.body.appendChild(a)
        a.click()
        document.body.removeChild(a)
    }

    const countLabel =
        cappedList && filteredTotal > 0
            ? `Showing ${showingCount} of ${filteredTotal}`
            : `${filteredTotal} file${filteredTotal !== 1 ? 's' : ''}`

    return (
        <div className="min-h-screen relative" style={{ backgroundColor: baseBg, '--accent': accentColor }}>
            <Head>
                <title>{name ? `${name} — Shared collection` : 'Shared collection'}</title>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            {hasBackground && (
                <>
                    <div
                        className="fixed inset-0 bg-cover bg-center bg-no-repeat blur-2xl scale-105"
                        style={{
                            backgroundImage: `url(${backgroundImageUrl})`,
                            opacity: 0.5,
                        }}
                        aria-hidden
                    />
                    <div
                        className="fixed inset-0"
                        style={{
                            background: `linear-gradient(to bottom, ${gradientFrom} 0%, transparent 25%, transparent 60%, ${gradientTo} 100%)`,
                        }}
                        aria-hidden
                    />
                </>
            )}

            <header className={`relative z-10 pt-10 pb-6 px-4 sm:px-6 max-w-5xl mx-auto ${view === 'list' ? '' : 'text-center'}`}>
                {logoUrl && (
                    <div className={`mb-5 ${view === 'list' ? '' : 'flex justify-center'}`}>
                        <img
                            src={logoUrl}
                            alt=""
                            className="h-14 w-auto object-contain max-h-20"
                            onError={(e) => {
                                e.target.style.display = 'none'
                            }}
                        />
                    </div>
                )}
                <p className={`text-sm font-medium ${textMuted}`}>{brand_name || 'Brand'}</p>
                <h1 className={`mt-1 text-3xl font-bold tracking-tight ${textColor}`}>{name || 'Collection'}</h1>
                <div className={`mt-2 flex flex-wrap items-center gap-2 ${view === 'list' ? '' : 'justify-center'}`}>
                    <span
                        className="inline-flex items-center gap-1 rounded-md px-2.5 py-0.5 text-xs font-medium text-white"
                        style={{ backgroundColor: primaryColor }}
                    >
                        Shared collection
                    </span>
                    <span className={`inline-flex items-center gap-1 text-xs ${textMuted}`} title="Visitors need the link and password.">
                        <LockClosedIcon className="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden />
                        Protected link
                    </span>
                </div>
                {description ? <p className={`mt-3 text-sm max-w-2xl ${view === 'list' ? '' : 'mx-auto'} ${textMuted}`}>{description}</p> : null}
            </header>

            <div className={`relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-4`}>
                {!downloadCollectionEnabled && !emptyCollection ? (
                    <div
                        className={`mb-4 rounded-lg border px-4 py-3 text-sm ${chromeBorder} ${themeDark ? 'bg-amber-500/10 text-amber-100 border-amber-400/30' : 'bg-amber-50 text-amber-900 border-amber-200'}`}
                    >
                        Downloads are disabled for this shared collection.
                    </div>
                ) : null}

                {!emptyCollection && (
                    <div
                        className={`rounded-xl border p-3 sm:p-4 shadow-sm space-y-3 ${chromeBorder} ${chromeBg} ${themeDark ? 'backdrop-blur-md' : ''}`}
                    >
                        <div className="flex flex-col lg:flex-row lg:items-end gap-3">
                            <div className="flex-1 min-w-0">
                                <label htmlFor="guest-share-search" className={`sr-only ${textColor}`}>
                                    Search files
                                </label>
                                <input
                                    id="guest-share-search"
                                    ref={searchInputRef}
                                    type="search"
                                    defaultValue={q}
                                    placeholder="Search by name or tags…"
                                    className={`w-full rounded-lg border px-3 py-2 text-sm ${themeDark ? 'border-white/20 bg-black/30 text-white placeholder:text-white/40' : 'border-gray-300 bg-white text-gray-900'}`}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            navigateGuestQuery({ q: e.currentTarget.value })
                                        }
                                    }}
                                />
                            </div>
                            <div className="flex flex-wrap gap-2 items-center">
                                <select
                                    value={type}
                                    onChange={(e) => navigateGuestQuery({ type: e.target.value })}
                                    className={`rounded-lg border px-2 py-2 text-sm ${themeDark ? 'border-white/20 bg-black/30 text-white' : 'border-gray-300 bg-white'}`}
                                    aria-label="Filter by type"
                                >
                                    <option value="all">All types</option>
                                    <option value="images">Images</option>
                                    <option value="videos">Videos</option>
                                    <option value="documents">Documents</option>
                                    <option value="other">Other</option>
                                </select>
                                <select
                                    value={sort}
                                    onChange={(e) => navigateGuestQuery({ sort: e.target.value })}
                                    className={`rounded-lg border px-2 py-2 text-sm ${themeDark ? 'border-white/20 bg-black/30 text-white' : 'border-gray-300 bg-white'}`}
                                    aria-label="Sort"
                                >
                                    <option value="newest">Newest</option>
                                    <option value="name">Name A–Z</option>
                                    <option value="type">Type</option>
                                </select>
                                <div className={`inline-flex rounded-lg border overflow-hidden ${chromeBorder}`}>
                                    <button
                                        type="button"
                                        onClick={() => navigateGuestQuery({ view: 'grid' })}
                                        className={`p-2 ${view === 'grid' ? 'bg-indigo-600 text-white' : themeDark ? 'text-white/70 hover:bg-white/10' : 'text-gray-600 hover:bg-gray-50'}`}
                                        aria-pressed={view === 'grid'}
                                        title="Grid"
                                    >
                                        <Squares2X2Icon className="h-5 w-5" />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => navigateGuestQuery({ view: 'list' })}
                                        className={`p-2 ${view === 'list' ? 'bg-indigo-600 text-white' : themeDark ? 'text-white/70 hover:bg-white/10' : 'text-gray-600 hover:bg-gray-50'}`}
                                        aria-pressed={view === 'list'}
                                        title="List"
                                    >
                                        <ListBulletIcon className="h-5 w-5" />
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => navigateGuestQuery({ q: searchInputRef.current?.value ?? '' })}
                                    className="rounded-lg px-3 py-2 text-sm font-medium text-white shadow-sm"
                                    style={{ backgroundColor: accentColor }}
                                >
                                    Search
                                </button>
                            </div>
                        </div>
                        <div className={`flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 sm:gap-3 text-sm ${textMuted}`}>
                            <span className={textColor}>{countLabel}</span>
                            {downloadCollectionEnabled ? (
                                <>
                                    <button
                                        type="button"
                                        onClick={selectAllVisible}
                                        disabled={!assets?.length}
                                        className="text-left font-medium disabled:opacity-40 hover:underline"
                                        style={{ color: accentColor }}
                                    >
                                        Select all visible
                                    </button>
                                    <button type="button" onClick={clearSelection} disabled={selectedIds.size === 0} className="text-left font-medium disabled:opacity-40 hover:underline">
                                        Clear selection
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => openDownloadPanel('selected')}
                                        disabled={selectedIds.size === 0 || !assets?.length}
                                        className="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 font-medium disabled:opacity-40"
                                        style={{ borderColor: accentColor, color: accentColor }}
                                    >
                                        <ArrowDownTrayIcon className="h-4 w-4" />
                                        Download selected
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => openDownloadPanel('all')}
                                        disabled={collectionAssetTotal === 0}
                                        className="inline-flex items-center gap-1 rounded-md px-3 py-1.5 font-medium text-white shadow-sm disabled:opacity-40"
                                        style={{ backgroundColor: accentColor }}
                                    >
                                        <ArrowDownTrayIcon className="h-4 w-4" />
                                        Download all
                                    </button>
                                </>
                            ) : null}
                        </div>
                    </div>
                )}
            </div>

            <main className="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-20">
                {emptyCollection ? (
                    <div className={`text-center py-16 ${textMuted}`}>
                        <DocumentIcon className={`mx-auto h-14 w-14 ${textMutedLight}`} aria-hidden />
                        <p className={`mt-3 font-medium ${textColor}`}>This shared collection is empty.</p>
                    </div>
                ) : noMatches ? (
                    <div className={`text-center py-16 ${textMuted}`}>
                        <DocumentIcon className={`mx-auto h-14 w-14 ${textMutedLight}`} aria-hidden />
                        <p className={`mt-3 font-medium ${textColor}`}>No files match your search.</p>
                    </div>
                ) : view === 'grid' && assets?.length > 0 ? (
                    <AssetGrid
                        assets={assets}
                        onAssetClick={handleAssetClick}
                        cardSize={220}
                        showInfo
                        selectedAssetId={null}
                        primaryColor={accentColor}
                        cardVariant={hasBackground ? 'cinematic' : 'default'}
                        bucketAssetIds={bucketIdsList}
                        onBucketToggle={toggleBucket}
                        layoutMode="grid"
                        gridSearchQuery={q}
                    />
                ) : view === 'list' && assets?.length > 0 ? (
                    <ul className={`rounded-xl border divide-y overflow-hidden ${chromeBorder} ${themeDark ? 'divide-white/10 bg-white/5' : 'divide-gray-200 bg-white'}`}>
                        {assets.map((asset) => {
                            const sid = String(asset.id)
                            const checked = selectedIds.has(sid)
                            return (
                                <li key={asset.id} className={`flex items-center gap-3 px-3 py-2 ${themeDark ? 'hover:bg-white/5' : 'hover:bg-gray-50'}`}>
                                    <input
                                        type="checkbox"
                                        className="h-4 w-4 rounded border-gray-300"
                                        checked={checked}
                                        onChange={() => toggleBucket(asset.id)}
                                        aria-label={`Select ${asset.title || asset.original_filename || 'file'}`}
                                    />
                                    <div className="h-12 w-12 shrink-0 rounded bg-gray-100 overflow-hidden">
                                        {asset.final_thumbnail_url || asset.thumbnail_url ? (
                                            <img src={asset.final_thumbnail_url || asset.thumbnail_url} alt="" className="h-full w-full object-cover" />
                                        ) : (
                                            <div className="h-full w-full flex items-center justify-center text-gray-400">
                                                <DocumentIcon className="h-6 w-6" />
                                            </div>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className={`text-sm font-medium truncate ${textColor}`}>{asset.title || asset.original_filename || 'Untitled'}</p>
                                        <p className={`text-xs truncate ${textMuted}`}>{asset.mime_type || ''}</p>
                                    </div>
                                    <span className={`hidden sm:block text-xs shrink-0 ${textMutedLight}`}>{formatBytes(asset.size_bytes)}</span>
                                    {downloadCollectionEnabled && asset.download_url ? (
                                        <a
                                            href={asset.download_url}
                                            className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-white shrink-0"
                                            style={{ backgroundColor: accentColor }}
                                        >
                                            <ArrowDownTrayIcon className="h-4 w-4" />
                                            Download
                                        </a>
                                    ) : null}
                                </li>
                            )
                        })}
                    </ul>
                ) : null}
            </main>

            {downloadPanelOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="download-panel-title" role="dialog" aria-modal="true">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity" aria-hidden onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)} />
                        <div className="relative transform overflow-hidden rounded-xl bg-white px-4 pb-4 pt-5 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    className="rounded-md text-gray-400 hover:text-gray-600 focus:outline-none"
                                    onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)}
                                    disabled={downloadSubmitting}
                                >
                                    <XMarkIcon className="h-5 w-5" aria-hidden />
                                </button>
                            </div>
                            <div className="sm:flex sm:items-start">
                                <div className="mt-3 w-full text-center sm:mt-0 sm:text-left">
                                    <h3 className="text-base font-semibold leading-6 text-gray-900" id="download-panel-title">
                                        {downloadPanelMode === 'selected' ? 'Download selected' : 'Download all'}
                                    </h3>
                                    <p className="mt-1 text-sm text-gray-500">
                                        {downloadPanelMode === 'selected'
                                            ? `ZIP with ${selectedIds.size} selected file${selectedIds.size !== 1 ? 's' : ''}.`
                                            : 'Download all assets in this collection as a ZIP file (respects your plan limits).'}
                                    </p>
                                    {downloadError && <p className="mt-2 text-sm text-red-600">{downloadError}</p>}
                                    <div className="mt-5 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                        <button
                                            type="button"
                                            onClick={handleDownloadPanelConfirm}
                                            disabled={downloadSubmitting || (downloadPanelMode === 'selected' && selectedIds.size === 0)}
                                            className="inline-flex w-full justify-center rounded-lg px-4 py-3 text-sm font-semibold text-white shadow-md hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50 sm:col-start-2"
                                            style={{ backgroundColor: accentColor }}
                                        >
                                            {downloadSubmitting ? 'Preparing…' : 'Download ZIP'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setDownloadPanelOpen(false)}
                                            disabled={downloadSubmitting}
                                            className="mt-3 inline-flex w-full justify-center rounded-lg bg-white px-4 py-3 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 sm:col-start-1 sm:mt-0 disabled:opacity-50"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
