/**
 * Phase 5B: Interactive GLB preview via &lt;model-viewer&gt;.
 * Parent surfaces should only mount this when {@link shouldShowRealtimeGlbModelViewer} is true
 * (registry `model_glb`, DAM_3D enabled, and a GLB source URL from `preview_3d_viewer_url` or `original`).
 */
import '@google/model-viewer'
import { useCallback, useEffect, useRef, useState } from 'react'
import { usePage } from '@inertiajs/react'
import ThumbnailPreview from './ThumbnailPreview'
import { failedRasterThumbnailUrls } from '../utils/thumbnailRasterFailedCache'
import {
    getRegistryModelGlbModelSourceUrl,
    getRegistryModel3dPosterDisplayUrl,
    shouldShowRealtimeGlbModelViewer,
} from '../utils/resolveAsset3dPreviewImage'

function postPreview3dTelemetry(assetId, kind) {
    if (!assetId || typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return
    }
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
    const url =
        typeof route === 'function'
            ? route('assets.preview-3d.telemetry', { asset: assetId })
            : `/app/assets/${assetId}/preview-3d/telemetry`
    window
        .fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ kind }),
            credentials: 'same-origin',
        })
        .catch(() => {})
}

/**
 * @param {object} props
 * @param {object} props.asset
 * @param {string} [props.className]
 * @param {boolean} [props.lightboxStage] Fullscreen lightbox: freer touch/pointer for orbit (see model-viewer touch-action).
 */
export default function Model3dViewer({ asset, className = '', lightboxStage = false }) {
    const { dam_file_types: damFileTypes, dam_3d_enabled: dam3dEnabled } = usePage().props
    const [viewerFailed, setViewerFailed] = useState(false)
    const [viewerLoading, setViewerLoading] = useState(true)
    const elRef = useRef(null)

    const eligible = shouldShowRealtimeGlbModelViewer(asset, damFileTypes, dam3dEnabled === true)
    const modelSrc = getRegistryModelGlbModelSourceUrl(asset, damFileTypes)
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
            return
        }
        const onErr = () => {
            setViewerFailed(true)
            setViewerLoading(false)
            postPreview3dTelemetry(asset?.id, 'model_viewer_error')
            postPreview3dTelemetry(asset?.id, 'model_viewer_fallback_active')
        }
        const onLoad = () => {
            setViewerLoading(false)
        }
        el.addEventListener('error', onErr)
        el.addEventListener('load', onLoad)
        return () => {
            el.removeEventListener('error', onErr)
            el.removeEventListener('load', onLoad)
        }
    }, [eligible, modelSrc, viewerFailed, asset?.id])

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
                                postPreview3dTelemetry(asset?.id, 'model_viewer_retry')
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
                            onClick={() => postPreview3dTelemetry(asset?.id, 'model_viewer_open_full')}
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
                    onClick={() => postPreview3dTelemetry(asset?.id, 'model_viewer_open_full')}
                >
                    Open full preview
                </a>
            </div>
        </div>
    )
}
