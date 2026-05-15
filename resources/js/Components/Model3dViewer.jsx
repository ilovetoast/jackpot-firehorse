/**
 * Phase 5B: Interactive GLB preview via &lt;model-viewer&gt;.
 * Parent surfaces should only mount this when {@link shouldShowRealtimeGlbModelViewer} is true
 * (registry `model_glb`, `dam_3d_realtime_viewer_enabled`, and a GLB source URL from `preview_3d_viewer_url` or `original`).
 */
import '@google/model-viewer'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { usePage } from '@inertiajs/react'
import ThumbnailPreview from './ThumbnailPreview'
import { failedRasterThumbnailUrls } from '../utils/thumbnailRasterFailedCache'
import {
    cdnUrlForDisplayWithoutQuery,
    getCdnPreviewFailureCopy,
    inferGlbDeliveryVariant,
    isProbablyCloudFrontSignedUrl,
    logCdnMediaDiagnostics,
    probeCdnAssetAvailability,
} from '../utils/cdnAssetLoadDiagnostics'
import {
    getRegistryModelGlbModelSourceUrl,
    getRegistryModel3dPosterDisplayUrl,
    shouldShowRealtimeGlbModelViewer,
} from '../utils/resolveAsset3dPreviewImage'

/**
 * Map CDN probe outcome to copy keys for operator-facing hints (console only).
 *
 * @param {{ category: string, httpStatus?: number|null, modelHost?: string, pageOrigin?: string }|null} probe
 */
function classifyModelViewerLoadFailure(probe) {
    if (probe === null) return 'cors_or_unknown'
    if (!probe) return 'cors_or_unknown'
    if (probe.category === 'unauthorized') return 'unauthorized'
    if (probe.category === 'not_found') return 'not_found'
    if (probe.category === 'cors_or_unknown') return 'cors_or_unknown'
    if (probe.category === 'network') return 'network'
    if (probe.category === 'ok') return 'viewer_failed_ok_head'
    return 'generic'
}

/**
 * Server-side correlation for model-viewer failures. Browsers do not expose CORS text to JS,
 * but when `page_origin` ≠ `model_origin` ops can treat failures as CDN CORS until proven otherwise.
 *
 * @param {string|number} assetId
 * @param {'model_viewer_error'|'model_viewer_retry'|'model_viewer_open_full'|'model_viewer_fallback_active'} kind
 * @param {{ page_origin?: string|null, model_origin?: string|null }} [origins]
 */
function postPreview3dTelemetry(assetId, kind, origins = {}) {
    if (!assetId || typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return
    }
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
    const url =
        typeof route === 'function'
            ? route('assets.preview-3d.telemetry', { asset: assetId })
            : `/app/assets/${assetId}/preview-3d/telemetry`
    const body = { kind, ...origins }
    window
        .fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
        })
        .catch(() => {})
}

function resolveModelViewerOrigins(modelSrc) {
    if (typeof window === 'undefined' || !modelSrc) {
        return { page_origin: null, model_origin: null }
    }
    try {
        return {
            page_origin: window.location.origin,
            model_origin: new URL(modelSrc, window.location.href).origin,
        }
    } catch {
        return { page_origin: window.location.origin, model_origin: null }
    }
}

/**
 * @param {object} props
 * @param {object} props.asset
 * @param {string} [props.className]
 * @param {boolean} [props.lightboxStage] Fullscreen lightbox: freer touch/pointer for orbit (see model-viewer touch-action).
 */
export default function Model3dViewer({ asset, className = '', lightboxStage = false }) {
    const { dam_file_types: damFileTypes, dam_3d_realtime_viewer_enabled: damRealtimeViewer } = usePage().props
    const [viewerFailed, setViewerFailed] = useState(false)
    const [viewerLoading, setViewerLoading] = useState(true)
    const elRef = useRef(null)

    const eligible = shouldShowRealtimeGlbModelViewer(asset, damFileTypes, damRealtimeViewer === true)
    const modelSrc = getRegistryModelGlbModelSourceUrl(asset, damFileTypes)
    const crossOriginModel = useMemo(() => {
        if (!modelSrc || typeof window === 'undefined') {
            return false
        }
        try {
            return new URL(modelSrc, window.location.href).origin !== window.location.origin
        } catch {
            return false
        }
    }, [modelSrc])
    const posterUrl = getRegistryModel3dPosterDisplayUrl(asset, failedRasterThumbnailUrls, damFileTypes)
    // Stub raster must not be model-viewer's `poster` — it stays visible and hides the GLB.
    const posterIsStub = asset?.preview_3d_poster_is_stub === true
    const modelViewerPoster = !posterIsStub && posterUrl ? posterUrl : undefined
    const stubWhy =
        typeof asset?.preview_3d_poster_stub_reason === 'string' ? asset.preview_3d_poster_stub_reason.trim() : ''
    const alt = asset?.title || asset?.original_filename || '3D model'

    const resetViewer = useCallback(() => {
        setViewerFailed(false)
        setViewerLoading(true)
    }, [])

    useEffect(() => {
        resetViewer()
    }, [modelSrc, resetViewer])

    useEffect(() => {
        const el = elRef.current
        if (!el || !eligible || !modelSrc || viewerFailed) {
            return undefined
        }
        const ac = new AbortController()
        const onErr = () => {
            setViewerFailed(true)
            setViewerLoading(false)
            const origins = resolveModelViewerOrigins(modelSrc)
            postPreview3dTelemetry(asset?.id, 'model_viewer_error', origins)
            postPreview3dTelemetry(asset?.id, 'model_viewer_fallback_active', origins)
            ;(async () => {
                try {
                    const probe = await probeCdnAssetAvailability(modelSrc, { signal: ac.signal })
                    if (probe === null) {
                        return
                    }
                    const displayCategory = classifyModelViewerLoadFailure(probe)
                    const hint = getCdnPreviewFailureCopy(displayCategory, probe.httpStatus)
                    logCdnMediaDiagnostics('model-viewer', {
                        asset_id: asset?.id ?? null,
                        variant: inferGlbDeliveryVariant(asset || {}, modelSrc),
                        displayCategory,
                        operator_hint_primary: hint.primary,
                        operator_hint_secondary: hint.secondary ?? null,
                        cdn_host: probe.modelHost || null,
                        http_status: probe.httpStatus,
                        page_origin: probe.pageOrigin || null,
                        cross_origin_model: crossOriginModel,
                        url_delivery_guess: isProbablyCloudFrontSignedUrl(modelSrc) ? 'signed_url' : 'plain_cdn_expect_cookies',
                        cdn_path: cdnUrlForDisplayWithoutQuery(modelSrc, probe.pageOrigin),
                    })
                } catch (e) {
                    if (e && e.name === 'AbortError') {
                        return
                    }
                    logCdnMediaDiagnostics('model-viewer', {
                        asset_id: asset?.id ?? null,
                        variant: inferGlbDeliveryVariant(asset || {}, modelSrc),
                        error: String(e?.message || e),
                    })
                }
            })()
        }
        const onLoad = () => {
            setViewerLoading(false)
        }
        el.addEventListener('error', onErr)
        el.addEventListener('load', onLoad)
        return () => {
            ac.abort()
            el.removeEventListener('error', onErr)
            el.removeEventListener('load', onLoad)
        }
    }, [eligible, modelSrc, viewerFailed, asset?.id, crossOriginModel])

    if (!eligible || !modelSrc) {
        return null
    }

    if (viewerFailed) {
        return (
            <div className={`flex min-h-[220px] flex-col overflow-hidden rounded-lg border border-gray-200 bg-gray-50 ${className}`}>
                <div className="relative min-h-0 flex-1">
                    <ThumbnailPreview
                        asset={asset}
                        alt={alt}
                        className="h-full w-full object-contain"
                        size="lg"
                        liveThumbnailUpdates
                        preferLargeForVector
                    />
                </div>
                <div className="shrink-0 border-t border-gray-200 bg-white px-3 py-2 text-center">
                    <p className="text-sm font-medium text-gray-800">Preview unavailable</p>
                    <p className="mt-0.5 text-xs text-gray-500">Showing poster or placeholder.</p>
                    {stubWhy ? (
                        <p className="mt-1 max-w-md text-[11px] leading-snug text-gray-600">{stubWhy}</p>
                    ) : null}
                    <div className="mt-2 flex flex-wrap items-center justify-center gap-2">
                        <button
                            type="button"
                            className="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-800 shadow-sm hover:bg-gray-50"
                            onClick={() => {
                                postPreview3dTelemetry(asset?.id, 'model_viewer_retry', resolveModelViewerOrigins(modelSrc))
                                resetViewer()
                            }}
                        >
                            Retry 3D preview
                        </button>
                        <a
                            href={modelSrc}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="rounded-md border border-transparent px-3 py-1.5 text-xs font-medium text-sky-700 hover:underline"
                            onClick={() =>
                                postPreview3dTelemetry(asset?.id, 'model_viewer_open_full', resolveModelViewerOrigins(modelSrc))
                            }
                        >
                            Open full preview
                        </a>
                    </div>
                </div>
            </div>
        )
    }

    return (
        <div className={`relative flex min-h-0 w-full flex-col overflow-hidden rounded-lg border border-gray-200 bg-gray-100 ${className}`}>
            {viewerLoading ? (
                <div
                    className="pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 bg-gray-100/90"
                    aria-hidden="true"
                >
                    <div className="h-9 w-9 animate-pulse rounded-full bg-gray-300" />
                    <div className="h-2 w-40 max-w-[70%] animate-pulse rounded bg-gray-300" />
                    <div className="h-2 w-28 max-w-[50%] animate-pulse rounded bg-gray-300" />
                    <p className="text-xs text-gray-500">Loading 3D preview…</p>
                </div>
            ) : null}
            {/* Custom element from @google/model-viewer (not a React DOM component). */}
            <model-viewer
                ref={elRef}
                src={modelSrc}
                poster={modelViewerPoster}
                alt={alt}
                crossOrigin="anonymous"
                style={{
                    width: '100%',
                    height: '100%',
                    minHeight: lightboxStage ? 'min(75dvh, 720px)' : '280px',
                    background: '#f3f4f6',
                    touchAction: lightboxStage ? 'none' : undefined,
                }}
                className="block h-full w-full"
                {...{
                    'camera-controls': '',
                    ...(lightboxStage ? {} : { 'touch-action': 'pan-y' }),
                    'shadow-intensity': '1',
                }}
            />
            <div className="absolute bottom-2 right-2 z-20">
                <a
                    href={modelSrc}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="rounded bg-white/90 px-2 py-1 text-xs font-medium text-sky-800 shadow hover:bg-white"
                    onClick={() =>
                        postPreview3dTelemetry(asset?.id, 'model_viewer_open_full', resolveModelViewerOrigins(modelSrc))
                    }
                >
                    Open full preview
                </a>
            </div>
        </div>
    )
}
