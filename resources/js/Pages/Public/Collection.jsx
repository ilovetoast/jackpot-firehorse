/**
 * Password-protected share collection (guests): press-kit style gallery.
 * Filters: GET ?q=&type=&sort=&view=grid|list|masonry on guest_collection_path.
 */
import { useState, useCallback, useMemo, useRef, useEffect } from 'react'
import { Head, router } from '@inertiajs/react'
import axios from 'axios'
import LoadMoreFooter from '../../Components/LoadMoreFooter'
import {
    DocumentIcon,
    ArrowDownTrayIcon,
    XMarkIcon,
    LockClosedIcon,
    Squares2X2Icon,
    ListBulletIcon,
} from '@heroicons/react/24/outline'
import AssetGrid from '../../Components/AssetGrid'
import PublicShareAssetLightbox from '../../Components/Public/PublicShareAssetLightbox'
import FilmGrainOverlay from '../../Components/FilmGrainOverlay'
import { useCdn403Recovery } from '../../hooks/useCdn403Recovery'
import { contrastTextOnPrimary } from '../../utils/contrastTextOnPrimary'
import { publicShareCinemaLayers } from '../../utils/publicShareCinemaBackground'
import { saveUrlAsDownload } from '../../utils/singleAssetDownload'
import { JACKPOT_WORDMARK_INVERTED_SRC } from '../../Components/Brand/LogoMark'

/** Show a “may take a while” notice in the ZIP modal above this file count (full collection or selected). */
const LARGE_PUBLIC_ZIP_WARNING_THRESHOLD = 25

function formatBytes(n) {
    if (n == null || Number.isNaN(Number(n))) return ''
    const v = Number(n)
    if (v < 1024) return `${v} B`
    if (v < 1024 * 1024) return `${(v / 1024).toFixed(1)} KB`
    return `${(v / (1024 * 1024)).toFixed(1)} MB`
}

/** Masonry layout icon (matches AssetGridViewOptionsDropdown layout control). */
function GuestLayoutMasonryIcon({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 20 16" stroke="currentColor" strokeWidth={1.5} aria-hidden>
            <rect x="1" y="1" width="5.5" height="4" rx="0.5" />
            <rect x="8" y="1" width="5.5" height="7" rx="0.5" />
            <rect x="14.5" y="1" width="4.5" height="5" rx="0.5" />
            <rect x="1" y="6" width="5.5" height="9" rx="0.5" />
            <rect x="8" y="9" width="5.5" height="6" rx="0.5" />
            <rect x="14.5" y="7" width="4.5" height="8" rx="0.5" />
        </svg>
    )
}

export default function PublicCollection({
    collection = {},
    assets = [],
    public_collection_downloads_enabled: downloadCollectionEnabled = false,
    branding_options: brandingOptions = {},
    public_share_theme: publicShareThemeProp = null,
    cdn_domain: cdnDomain = null,
    share_download_post_path: shareDownloadPostPath = null,
    guest_collection_path: guestCollectionPath = '',
    guest_query: guestQueryProp = {},
    guest_collection_asset_total: collectionAssetTotal = 0,
    guest_filtered_total: filteredTotal = 0,
    next_page_url = null,
}) {
    useCdn403Recovery(cdnDomain)
    const { name, description, brand_name } = collection
    const theme = publicShareThemeProp || brandingOptions || {}
    const accentColor = theme.accent_color || theme.primary_color || '#7c3aed'
    const primaryColor = theme.primary_color || accentColor
    const logoUrl = theme.logo_url || null
    const backgroundImageUrl = theme.background_image_url || null
    const { color: onPrimaryBtn } = contrastTextOnPrimary(primaryColor)
    const { color: onAccentBtn } = contrastTextOnPrimary(accentColor)

    const q = typeof guestQueryProp?.q === 'string' ? guestQueryProp.q : ''
    const type = typeof guestQueryProp?.type === 'string' ? guestQueryProp.type : 'all'
    const sort = typeof guestQueryProp?.sort === 'string' ? guestQueryProp.sort : 'newest'
    const viewRaw = typeof guestQueryProp?.view === 'string' ? String(guestQueryProp.view).toLowerCase() : 'grid'
    const view = viewRaw === 'list' || viewRaw === 'masonry' ? viewRaw : 'grid'

    const [downloadPanelOpen, setDownloadPanelOpen] = useState(false)
    const [downloadPanelMode, setDownloadPanelMode] = useState('all')
    const [downloadSubmitting, setDownloadSubmitting] = useState(false)
    const [downloadError, setDownloadError] = useState(null)
    const [selectedIds, setSelectedIds] = useState(() => new Set())
    const [lightboxIndex, setLightboxIndex] = useState(null)
    /** Row-level single-file download (same-origin fetch; avoids CDN opening in a new tab). */
    const [publicListDownloadId, setPublicListDownloadId] = useState(null)
    const searchInputRef = useRef(null)
    /** Index in `assetsList` for Shift-click range select (download checkboxes). */
    const lastBucketAnchorIndexRef = useRef(null)

    const [assetsList, setAssetsList] = useState(() => (Array.isArray(assets) ? assets.filter(Boolean) : []))
    const [nextPageUrl, setNextPageUrl] = useState(next_page_url ?? null)
    const [loadingMore, setLoadingMore] = useState(false)
    const loadMoreRef = useRef(null)
    const loadMoreAbortRef = useRef(null)

    useEffect(() => {
        setSelectedIds(new Set())
        lastBucketAnchorIndexRef.current = null
    }, [q, type, sort, view, guestCollectionPath])

    useEffect(() => {
        if (loadMoreAbortRef.current) {
            loadMoreAbortRef.current.abort()
            loadMoreAbortRef.current = null
        }
        setAssetsList(Array.isArray(assets) ? assets.filter(Boolean) : [])
        setNextPageUrl(next_page_url ?? null)
    }, [assets, next_page_url, q, type, sort, view, guestCollectionPath])

    useEffect(() => {
        if (searchInputRef.current) {
            searchInputRef.current.value = q
        }
    }, [q])

    const loadMore = useCallback(async () => {
        if (!nextPageUrl || loadingMore) return
        setLoadingMore(true)
        const ac = new AbortController()
        loadMoreAbortRef.current = ac
        try {
            const separator = nextPageUrl.includes('?') ? '&' : '?'
            const url = `${nextPageUrl}${separator}load_more=1`
            const response = await axios.get(url, { signal: ac.signal })
            const data = response.data?.data ?? []
            setAssetsList((prev) => [...prev, ...(Array.isArray(data) ? data : [])])
            setNextPageUrl(response.data?.next_page_url ?? null)
        } catch (e) {
            if (e.name === 'CanceledError' || e.code === 'ERR_CANCELED') return
            console.error('Guest collection infinite scroll failed', e)
            if (e.response?.status === 500) setNextPageUrl(null)
        } finally {
            if (loadMoreAbortRef.current === ac) loadMoreAbortRef.current = null
            setLoadingMore(false)
        }
    }, [nextPageUrl, loadingMore])

    useEffect(() => {
        if (!loadMoreRef.current) return
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting && nextPageUrl && !loadingMore) {
                    loadMore()
                }
            },
            { rootMargin: '200px' }
        )
        observer.observe(loadMoreRef.current)
        return () => observer.disconnect()
    }, [nextPageUrl, loadingMore, loadMore])

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
        async (assetIds, downloadTab) => {
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
                    downloadTab?.close?.()
                    const msg =
                        typeof data.message === 'string' && data.message.trim()
                            ? data.message
                            : res.status === 404
                              ? 'This shared collection is no longer available.'
                              : 'Download failed. Please try again.'
                    throw new Error(msg)
                }
                const zipUrl = typeof data.zip_url === 'string' ? data.zip_url.trim() : ''
                if (!zipUrl) {
                    downloadTab?.close?.()
                    throw new Error('No download link was returned. Please try again.')
                }
                /** Popup blockers allow a tab opened on the click; opening only after `fetch` is often blocked. */
                if (downloadTab && !downloadTab.closed) {
                    downloadTab.location.href = zipUrl
                } else {
                    const a = document.createElement('a')
                    a.href = zipUrl
                    a.target = '_blank'
                    a.rel = 'noopener noreferrer'
                    document.body.appendChild(a)
                    a.click()
                    a.remove()
                }
                setDownloadPanelOpen(false)
            } catch (err) {
                downloadTab?.close?.()
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
        const downloadTab =
            typeof window !== 'undefined'
                ? window.open('about:blank', '_blank', 'noopener,noreferrer')
                : null
        if (downloadPanelMode === 'selected') {
            const ids = [...selectedIds]
            if (ids.length === 0) {
                downloadTab?.close?.()
                return
            }
            runZipDownload(ids, downloadTab)
        } else {
            runZipDownload(null, downloadTab)
        }
    }

    const bucketIdsList = useMemo(() => {
        const set = selectedIds
        return (assetsList || []).filter((a) => set.has(String(a.id))).map((a) => a.id)
    }, [assetsList, selectedIds])

    const toggleBucket = useCallback((id, ev) => {
        const list = assetsList || []
        const idx = list.findIndex((a) => String(a.id) === String(id))
        if (idx < 0) return

        if (ev?.shiftKey && lastBucketAnchorIndexRef.current != null) {
            const anchor = lastBucketAnchorIndexRef.current
            const lo = Math.min(anchor, idx)
            const hi = Math.max(anchor, idx)
            setSelectedIds((prev) => {
                const clickedSelected = prev.has(String(id))
                const targetOn = !clickedSelected
                const n = new Set(prev)
                for (let k = lo; k <= hi; k++) {
                    const aid = String(list[k].id)
                    if (targetOn) n.add(aid)
                    else n.delete(aid)
                }
                return n
            })
            return
        }

        const sid = String(id)
        setSelectedIds((prev) => {
            const n = new Set(prev)
            if (n.has(sid)) n.delete(sid)
            else n.add(sid)
            return n
        })
        lastBucketAnchorIndexRef.current = idx
    }, [assetsList])

    const selectAllVisible = () => {
        setSelectedIds(new Set((assetsList || []).map((a) => String(a.id))))
        lastBucketAnchorIndexRef.current = null
    }

    const clearSelection = () => {
        setSelectedIds(new Set())
        lastBucketAnchorIndexRef.current = null
    }

    const emptyCollection = collectionAssetTotal === 0
    const noMatches = !emptyCollection && filteredTotal === 0

    const downloadZipAssetCount =
        downloadPanelMode === 'selected' ? selectedIds.size : collectionAssetTotal
    const showLargePublicDownloadWarning =
        downloadZipAssetCount > LARGE_PUBLIC_ZIP_WARNING_THRESHOLD
    const hasMoreInFilter = filteredTotal > (assetsList?.length ?? 0)

    const openLightboxForAsset = useCallback(
        (asset) => {
            const list = assetsList || []
            const i = list.findIndex((a) => String(a.id) === String(asset.id))
            if (i >= 0) setLightboxIndex(i)
        },
        [assetsList]
    )

    const closeLightbox = useCallback(() => setLightboxIndex(null), [])

    const downloadPublicListSingleAsset = useCallback(
        async (asset, e) => {
            e?.stopPropagation?.()
            if (!asset?.download_url || publicListDownloadId != null) return
            setPublicListDownloadId(String(asset.id))
            try {
                const url = new URL(asset.download_url, window.location.origin).href
                const raw = asset.original_filename || asset.title || 'download'
                const name =
                    String(raw)
                        .split(/[/\\]/)
                        .pop()
                        ?.replace(/["\\]/g, '_')
                        ?.slice(0, 200) || 'download'
                await saveUrlAsDownload(url, name)
            } catch {
                window.alert('Download failed. Please try again.')
            } finally {
                setPublicListDownloadId(null)
            }
        },
        [publicListDownloadId]
    )

    const goLightboxPrev = useCallback(() => {
        setLightboxIndex((i) => {
            if (i == null || !(assetsList || []).length) return i
            return i <= 0 ? assetsList.length - 1 : i - 1
        })
    }, [assetsList])

    const goLightboxNext = useCallback(() => {
        setLightboxIndex((i) => {
            if (i == null || !(assetsList || []).length) return i
            return i >= assetsList.length - 1 ? 0 : i + 1
        })
    }, [assetsList])

    const countLabel =
        hasMoreInFilter && filteredTotal > 0
            ? `Showing ${assetsList.length} of ${filteredTotal}`
            : `${filteredTotal} file${filteredTotal !== 1 ? 's' : ''}`

    const hasBackground = !!backgroundImageUrl
    const lightboxAsset = lightboxIndex != null && assetsList?.[lightboxIndex] ? assetsList[lightboxIndex] : null

    const { cinemaBase, noPhoto: cinemaStackNoPhoto, withPhotoOverlay: cinemaStackWithPhoto } = publicShareCinemaLayers(
        primaryColor,
        accentColor
    )

    return (
        <div
            className="min-h-screen relative overflow-hidden text-white"
            style={{
                backgroundColor: cinemaBase,
                '--share-primary': primaryColor,
                '--share-accent': accentColor,
            }}
        >
            <Head>
                <title>{name ? `${name} — Shared collection` : 'Shared collection'}</title>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <div className="fixed inset-0 pointer-events-none" aria-hidden>
                {hasBackground ? (
                    <>
                        <div
                            className="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-[0.38] blur-3xl scale-105"
                            style={{ backgroundImage: `url(${backgroundImageUrl})` }}
                        />
                        <div className="absolute inset-0" style={{ background: cinemaStackWithPhoto }} />
                    </>
                ) : (
                    <div className="absolute inset-0" style={{ background: cinemaStackNoPhoto }} />
                )}
                {/* Top read fade + side vignette so hero text pops like the reference */}
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_85%_55%_at_50%_0%,rgba(255,255,255,0.04)_0%,transparent_55%)]" />
                <div className="absolute inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/70" />
                <div className="absolute inset-0 bg-gradient-to-r from-black/50 via-transparent to-black/50 opacity-80" />
            </div>

            <div className="relative z-10">
                <header className="pt-10 pb-8 px-4 sm:px-6 max-w-5xl mx-auto text-center sm:pt-12">
                    <div className="mb-5 flex flex-col items-center sm:mb-6">
                        <img
                            src={JACKPOT_WORDMARK_INVERTED_SRC}
                            alt="Jackpot"
                            width={132}
                            height={36}
                            decoding="async"
                            draggable={false}
                            className="h-9 w-auto max-w-[min(15rem,88vw)] object-contain opacity-[0.48] drop-shadow-[0_2px_14px_rgba(0,0,0,0.45)] sm:h-10 md:h-11"
                        />
                        <span className="mt-1.5 text-[10px] font-medium uppercase tracking-[0.18em] text-white/30">
                            Powered by Jackpot
                        </span>
                    </div>
                    {logoUrl ? (
                        <div className="mb-6 flex justify-center">
                            <img
                                src={logoUrl}
                                alt=""
                                className="h-16 w-auto max-h-24 object-contain drop-shadow-lg"
                                onError={(e) => {
                                    e.target.style.display = 'none'
                                }}
                            />
                        </div>
                    ) : null}
                    <p className="text-xs font-medium uppercase tracking-[0.2em] text-white/45">{brand_name || 'Brand'}</p>
                    <h1 className="mt-2 text-3xl sm:text-4xl font-bold tracking-tight text-white drop-shadow-sm">{name || 'Collection'}</h1>
                    {description ? <p className="mt-4 text-sm sm:text-base text-white/65 max-w-2xl mx-auto leading-relaxed">{description}</p> : null}
                    <div className="mt-5 flex flex-wrap items-center justify-center gap-2">
                        <span
                            className="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold shadow-lg"
                            style={{ backgroundColor: primaryColor, color: onPrimaryBtn }}
                        >
                            Shared collection
                        </span>
                        <span
                            className="inline-flex items-center gap-1 rounded-full border border-white/10 bg-zinc-900/55 px-3 py-1 text-xs text-white/75 shadow-inner shadow-white/[0.03]"
                            title="Visitors need the link and password."
                        >
                            <LockClosedIcon className="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden />
                            Protected link
                        </span>
                    </div>
                </header>

                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-4">
                    {!downloadCollectionEnabled && !emptyCollection ? (
                        <div className="mb-4 rounded-xl border border-amber-400/25 bg-amber-500/10 px-4 py-3 text-sm text-amber-100/95">
                            Downloads are disabled for this shared collection.
                        </div>
                    ) : null}

                    {!emptyCollection && (
                        <div className="rounded-2xl border border-white/[0.08] bg-zinc-950/35 p-4 sm:p-5 shadow-xl shadow-black/40 backdrop-blur-xl space-y-4 ring-1 ring-white/[0.04]">
                            <div className="flex flex-col lg:flex-row lg:items-end gap-3">
                                <div className="flex-1 min-w-0">
                                    <label htmlFor="guest-share-search" className="sr-only">
                                        Search files
                                    </label>
                                    <input
                                        id="guest-share-search"
                                        ref={searchInputRef}
                                        type="search"
                                        defaultValue={q}
                                        placeholder="Search by name or tags…"
                                        className="w-full rounded-xl border border-white/15 bg-black/30 px-3 py-2.5 text-sm text-white placeholder:text-white/35 focus:border-white/25 focus:outline-none focus:ring-2 focus:ring-white/15"
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
                                        className="rounded-xl border border-white/15 bg-black/30 px-2 py-2.5 text-sm text-white"
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
                                        className="rounded-xl border border-white/15 bg-black/30 px-2 py-2.5 text-sm text-white"
                                        aria-label="Sort"
                                    >
                                        <option value="newest">Newest</option>
                                        <option value="name">Name A–Z</option>
                                        <option value="type">Type</option>
                                    </select>
                                    <div className="inline-flex rounded-xl border border-white/15 overflow-hidden">
                                        <button
                                            type="button"
                                            onClick={() => navigateGuestQuery({ view: 'grid' })}
                                            className="p-2.5 transition"
                                            style={
                                                view === 'grid'
                                                    ? { backgroundColor: primaryColor, color: onPrimaryBtn }
                                                    : { color: 'rgba(255,255,255,0.65)' }
                                            }
                                            aria-pressed={view === 'grid'}
                                            title="Uniform grid"
                                        >
                                            <Squares2X2Icon className="h-5 w-5" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => navigateGuestQuery({ view: 'masonry' })}
                                            className="p-2.5 transition border-l border-white/10"
                                            style={
                                                view === 'masonry'
                                                    ? { backgroundColor: primaryColor, color: onPrimaryBtn }
                                                    : { color: 'rgba(255,255,255,0.65)' }
                                            }
                                            aria-pressed={view === 'masonry'}
                                            title="Masonry"
                                        >
                                            <GuestLayoutMasonryIcon className="h-5 w-5" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => navigateGuestQuery({ view: 'list' })}
                                            className="p-2.5 transition border-l border-white/10"
                                            style={
                                                view === 'list'
                                                    ? { backgroundColor: primaryColor, color: onPrimaryBtn }
                                                    : { color: 'rgba(255,255,255,0.65)' }
                                            }
                                            aria-pressed={view === 'list'}
                                            title="List"
                                        >
                                            <ListBulletIcon className="h-5 w-5" />
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => navigateGuestQuery({ q: searchInputRef.current?.value ?? '' })}
                                        className="rounded-xl px-4 py-2.5 text-sm font-semibold shadow-lg transition hover:opacity-95"
                                        style={{ backgroundColor: accentColor, color: onAccentBtn }}
                                    >
                                        Search
                                    </button>
                                </div>
                            </div>
                            <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 sm:gap-3 text-sm text-white/60">
                                <span className="text-white/90 font-medium">{countLabel}</span>
                                {downloadCollectionEnabled ? (
                                    <>
                                        <button
                                            type="button"
                                            onClick={selectAllVisible}
                                            disabled={!assetsList?.length}
                                            className="text-left font-medium disabled:opacity-40 hover:underline"
                                            style={{ color: accentColor }}
                                            title="Select every file on this page. Shift-click two checkboxes to select the range between them."
                                        >
                                            Select all visible
                                        </button>
                                        <button
                                            type="button"
                                            onClick={clearSelection}
                                            disabled={selectedIds.size === 0}
                                            className="text-left font-medium disabled:opacity-40 hover:underline text-white/80"
                                        >
                                            Clear selection
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => openDownloadPanel('selected')}
                                            disabled={selectedIds.size === 0 || !assetsList?.length}
                                            className="inline-flex items-center gap-1 rounded-xl border border-white/20 px-3 py-2 font-medium disabled:opacity-40 text-white/90 hover:bg-white/5"
                                        >
                                            <ArrowDownTrayIcon className="h-4 w-4" />
                                            Download selected
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => openDownloadPanel('all')}
                                            disabled={collectionAssetTotal === 0}
                                            className="inline-flex items-center gap-1 rounded-xl px-3 py-2 font-semibold shadow-lg disabled:opacity-40 transition hover:opacity-95"
                                            style={{ backgroundColor: primaryColor, color: onPrimaryBtn }}
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

                <main className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-24">
                    {emptyCollection ? (
                        <div className="text-center py-20 text-white/55">
                            <DocumentIcon className="mx-auto h-14 w-14 text-white/25" aria-hidden />
                            <p className="mt-4 font-medium text-white/80">This shared collection is empty.</p>
                        </div>
                    ) : noMatches ? (
                        <div className="text-center py-20 text-white/55">
                            <DocumentIcon className="mx-auto h-14 w-14 text-white/25" aria-hidden />
                            <p className="mt-4 font-medium text-white/80">No files match your search.</p>
                        </div>
                    ) : (view === 'grid' || view === 'masonry') && assetsList?.length > 0 ? (
                        <div className="w-full min-w-0">
                            <AssetGrid
                                assets={assetsList}
                                onAssetClick={openLightboxForAsset}
                                cardSize={220}
                                showInfo
                                selectedAssetId={null}
                                primaryColor={primaryColor}
                                cardVariant="cinematic"
                                bucketAssetIds={bucketIdsList}
                                onBucketToggle={toggleBucket}
                                layoutMode={view === 'masonry' ? 'masonry' : 'grid'}
                                gridSearchQuery={q}
                                splitTitleFooter
                            />
                        </div>
                    ) : view === 'list' && assetsList?.length > 0 ? (
                        <ul className="rounded-2xl border border-white/[0.08] divide-y divide-white/10 overflow-hidden bg-zinc-950/35 backdrop-blur-md ring-1 ring-white/[0.04]">
                            {assetsList.map((asset) => {
                                const sid = String(asset.id)
                                const checked = selectedIds.has(sid)
                                return (
                                    <li
                                        key={asset.id}
                                        className="flex items-center gap-3 px-3 py-2.5 hover:bg-white/5 cursor-pointer"
                                        onClick={(e) => {
                                            if (e.target.closest('input, a, button')) return
                                            openLightboxForAsset(asset)
                                        }}
                                    >
                                        <span onClick={(e) => e.stopPropagation()}>
                                            <input
                                                type="checkbox"
                                                className="h-4 w-4 rounded border-white/30 bg-black/40"
                                                checked={checked}
                                                onChange={(e) => {
                                                    e.stopPropagation()
                                                    toggleBucket(asset.id, e)
                                                }}
                                                aria-label={`Select ${asset.title || asset.original_filename || 'file'}`}
                                            />
                                        </span>
                                        <div className="h-12 w-12 shrink-0 rounded-lg bg-white/10 overflow-hidden">
                                            {asset.final_thumbnail_url || asset.thumbnail_url ? (
                                                <img src={asset.final_thumbnail_url || asset.thumbnail_url} alt="" className="h-full w-full object-cover" />
                                            ) : (
                                                <div className="h-full w-full flex items-center justify-center text-white/35">
                                                    <DocumentIcon className="h-6 w-6" />
                                                </div>
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium truncate text-white/95">{asset.title || asset.original_filename || 'Untitled'}</p>
                                            <p className="text-xs truncate text-white/45">{asset.mime_type || ''}</p>
                                        </div>
                                        <span className="hidden sm:block text-xs shrink-0 text-white/35">{formatBytes(asset.size_bytes)}</span>
                                        {downloadCollectionEnabled && asset.download_url ? (
                                            <button
                                                type="button"
                                                disabled={publicListDownloadId != null}
                                                onClick={(e) => downloadPublicListSingleAsset(asset, e)}
                                                className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold shrink-0 shadow-md transition hover:opacity-95 disabled:cursor-wait disabled:opacity-60"
                                                style={{ backgroundColor: accentColor, color: onAccentBtn }}
                                            >
                                                <ArrowDownTrayIcon className="h-4 w-4" />
                                                {publicListDownloadId === String(asset.id) ? '…' : 'Download'}
                                            </button>
                                        ) : null}
                                    </li>
                                )
                            })}
                        </ul>
                    ) : null}
                    {!emptyCollection && !noMatches && assetsList?.length > 0 && nextPageUrl ? (
                        <>
                            <div ref={loadMoreRef} className="h-10" aria-hidden="true" />
                            <LoadMoreFooter
                                onLoadMore={loadMore}
                                hasMore={!!nextPageUrl}
                                isLoading={loadingMore}
                            />
                        </>
                    ) : null}
                </main>
            </div>

            {downloadPanelOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="download-panel-title" role="dialog" aria-modal="true">
                    <div className="flex min-h-full items-end justify-center p-4 text-center sm:items-center sm:p-0">
                        <div className="fixed inset-0 bg-black/70 backdrop-blur-sm transition-opacity" aria-hidden onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)} />
                        <div className="relative transform overflow-hidden rounded-2xl border border-white/10 bg-zinc-950 px-4 pb-5 pt-5 text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-lg sm:p-6">
                            <div className="absolute right-0 top-0 pr-4 pt-4">
                                <button
                                    type="button"
                                    className="rounded-md text-white/50 hover:text-white focus:outline-none"
                                    onClick={() => !downloadSubmitting && setDownloadPanelOpen(false)}
                                    disabled={downloadSubmitting}
                                >
                                    <XMarkIcon className="h-5 w-5" aria-hidden />
                                </button>
                            </div>
                            <div className="sm:flex sm:items-start">
                                <div className="mt-2 w-full text-center sm:mt-0 sm:text-left">
                                    <h3 className="text-base font-semibold leading-6 text-white" id="download-panel-title">
                                        {downloadPanelMode === 'selected' ? 'Download selected' : 'Download all'}
                                    </h3>
                                    <p className="mt-2 text-sm text-white/55">
                                        {downloadPanelMode === 'selected'
                                            ? `ZIP with ${selectedIds.size} selected file${selectedIds.size !== 1 ? 's' : ''} (visible selection only).`
                                            : 'ZIP with all assets in this collection (entire collection, not only the current filter).'}
                                    </p>
                                    {showLargePublicDownloadWarning ? (
                                        <p
                                            className="mt-3 rounded-lg border border-amber-500/35 bg-amber-500/10 px-3 py-2 text-left text-sm text-amber-100/95"
                                            role="status"
                                        >
                                            {downloadPanelMode === 'all'
                                                ? `This collection has ${collectionAssetTotal.toLocaleString()} files. Building the ZIP can take a little while—please stay on this step until the download begins.`
                                                : `You are downloading ${selectedIds.size.toLocaleString()} files. Building the ZIP can take a little while—please stay on this step until the download begins.`}
                                        </p>
                                    ) : null}
                                    {downloadError && <p className="mt-3 text-sm text-red-300">{downloadError}</p>}
                                    <div className="mt-6 sm:mt-6 sm:grid sm:grid-flow-row-dense sm:grid-cols-2 sm:gap-3">
                                        <button
                                            type="button"
                                            onClick={handleDownloadPanelConfirm}
                                            disabled={downloadSubmitting || (downloadPanelMode === 'selected' && selectedIds.size === 0)}
                                            className="inline-flex w-full justify-center rounded-xl px-4 py-3 text-sm font-semibold shadow-lg transition hover:opacity-95 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-50 sm:col-start-2"
                                            style={{ backgroundColor: primaryColor, color: onPrimaryBtn }}
                                        >
                                            {downloadSubmitting ? 'Preparing…' : 'Download ZIP'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setDownloadPanelOpen(false)}
                                            disabled={downloadSubmitting}
                                            className="mt-3 inline-flex w-full justify-center rounded-xl border border-white/15 bg-white/5 px-4 py-3 text-sm font-semibold text-white hover:bg-white/10 sm:col-start-1 sm:mt-0 disabled:opacity-50"
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

            {lightboxAsset ? (
                <PublicShareAssetLightbox
                    asset={lightboxAsset}
                    index={lightboxIndex}
                    total={assetsList.length}
                    primaryHex={primaryColor}
                    downloadsEnabled={downloadCollectionEnabled}
                    zipSelectionIncludesAsset={selectedIds.has(String(lightboxAsset.id))}
                    zipSelectionCount={selectedIds.size}
                    onToggleZipSelection={
                        downloadCollectionEnabled ? () => toggleBucket(lightboxAsset.id) : undefined
                    }
                    onClose={closeLightbox}
                    onPrev={goLightboxPrev}
                    onNext={goLightboxNext}
                />
            ) : null}

            <FilmGrainOverlay />
        </div>
    )
}
