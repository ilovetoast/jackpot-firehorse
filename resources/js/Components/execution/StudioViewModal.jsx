import { useState, useCallback, useEffect, useRef } from 'react'
import ReactCrop, { convertToPixelCrop } from 'react-image-crop'
import 'react-image-crop/dist/ReactCrop.css'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import { XMarkIcon, ArrowPathIcon } from '@heroicons/react/24/outline'

const EASE_PANEL = 'cubic-bezier(0.16, 1, 0.3, 1)'

/** Optional aspect lock (omit for free-form — drag each edge/corner independently). */
const ASPECT_LOCKS = [
    { id: 'free', label: 'Free', aspect: undefined },
    { id: '1:1', label: '1:1', aspect: 1 },
    { id: '4:5', label: '4:5', aspect: 4 / 5 },
    { id: '3:4', label: '3:4', aspect: 3 / 4 },
    { id: '16:9', label: '16:9', aspect: 16 / 9 },
    { id: '9:16', label: '9:16', aspect: 9 / 16 },
]

function clamp01(v) {
    return Math.min(1, Math.max(0, v))
}

/**
 * Map a pixel crop (react-image-crop, relative to rendered img dimensions) to normalized 0–1 on the source.
 *
 * @param {{ x: number, y: number, width: number, height: number }} pixelCrop
 * @param {HTMLImageElement} img
 */
function pixelCropDisplayToNormalized(pixelCrop, img) {
    const nw = img.naturalWidth
    const nh = img.naturalHeight
    const dw = img.width
    const dh = img.height
    if (!nw || !nh || !dw || !dh) {
        return null
    }
    const sx = nw / dw
    const sy = nh / dh
    const x = pixelCrop.x * sx
    const y = pixelCrop.y * sy
    const w = pixelCrop.width * sx
    const h = pixelCrop.height * sy
    return {
        x: clamp01(x / nw),
        y: clamp01(y / nh),
        width: clamp01(w / nw),
        height: clamp01(h / nh),
    }
}

/**
 * Manual Studio View crop on the large source thumbnail; saves normalized crop via parent callback.
 *
 * @param {Object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {string|null|undefined} props.imageSrc
 * @param {(payload: { crop: { x: number, y: number, width: number, height: number }, poi: { x: number, y: number }|null }) => Promise<boolean|void>} props.onSave — return false to keep modal open
 * @param {boolean} [props.saving]
 * @param {boolean} [props.previewLoading] — source preview still generating
 */
export default function StudioViewModal({ open, onClose, imageSrc, onSave, saving = false, previewLoading = false }) {
    /** @type {import('react-image-crop').PercentCrop} */
    const [crop, setCrop] = useState({
        unit: '%',
        x: 0,
        y: 0,
        width: 100,
        height: 100,
    })
    const [zoom, setZoom] = useState(1)
    const [naturalSize, setNaturalSize] = useState({ width: 0, height: 0 })
    const [sourceDecode, setSourceDecode] = useState('idle')
    const [aspectLockId, setAspectLockId] = useState('free')
    const [poiNorm, setPoiNorm] = useState(null)
    const [panelEntered, setPanelEntered] = useState(false)
    const [saveError, setSaveError] = useState(null)
    const imgRef = useRef(null)

    const aspectLock = ASPECT_LOCKS.find((a) => a.id === aspectLockId)?.aspect

    useEffect(() => {
        if (!open) {
            setPanelEntered(false)
            setSourceDecode('idle')
            return undefined
        }
        setSaveError(null)
        setPanelEntered(false)
        setPoiNorm(null)
        setAspectLockId('free')
        setNaturalSize({ width: 0, height: 0 })
        setSourceDecode('idle')
        setCrop({ unit: '%', x: 0, y: 0, width: 100, height: 100 })
        setZoom(1)
        const id = requestAnimationFrame(() => {
            requestAnimationFrame(() => setPanelEntered(true))
        })
        return () => cancelAnimationFrame(id)
    }, [open])

    useEffect(() => {
        if (!open || !imageSrc || previewLoading) {
            return undefined
        }
        let cancelled = false
        setSourceDecode('loading')
        setNaturalSize({ width: 0, height: 0 })

        const img = new Image()
        const finishReady = () => {
            if (cancelled) {
                return
            }
            const nw = img.naturalWidth
            const nh = img.naturalHeight
            if (!nw || !nh) {
                setSourceDecode('error')
                return
            }
            setNaturalSize({ width: nw, height: nh })
            setSourceDecode('ready')
        }

        img.onload = () => {
            const d = img.decode?.()
            if (d && typeof d.then === 'function') {
                d.then(finishReady).catch(finishReady)
            } else {
                finishReady()
            }
        }
        img.onerror = () => {
            if (!cancelled) {
                setSourceDecode('error')
            }
        }
        img.src = imageSrc

        return () => {
            cancelled = true
            img.onload = null
            img.onerror = null
            img.src = ''
        }
    }, [open, imageSrc, previewLoading])

    const setPoiFromCropCenter = useCallback(() => {
        const img = imgRef.current
        const nw = naturalSize.width
        const nh = naturalSize.height
        if (!img || !nw || !nh || !img.width || !img.height) {
            return
        }
        const pixelCrop = convertToPixelCrop(crop, img.width, img.height)
        const norm = pixelCropDisplayToNormalized(pixelCrop, img)
        if (!norm) {
            return
        }
        setPoiNorm({
            x: clamp01(norm.x + norm.width / 2),
            y: clamp01(norm.y + norm.height / 2),
        })
    }, [crop, naturalSize.height, naturalSize.width])

    const handlePoiDoubleClick = useCallback((e) => {
        e.preventDefault()
        e.stopPropagation()
        const el = e.currentTarget
        const r = el.getBoundingClientRect()
        if (!r.width || !r.height) {
            return
        }
        const x = clamp01((e.clientX - r.left) / r.width)
        const y = clamp01((e.clientY - r.top) / r.height)
        setPoiNorm({ x, y })
    }, [])

    const createImage = (url) =>
        new Promise((resolve, reject) => {
            const image = new Image()
            image.addEventListener('load', () => resolve(image))
            image.addEventListener('error', (error) => reject(error))
            image.src = url
        })

    const handleSave = async () => {
        if (!imageSrc) {
            return
        }
        setSaveError(null)
        try {
            const img = imgRef.current
            if (!img?.naturalWidth || !img.width || !img.height) {
                const image = await createImage(imageSrc)
                const nw = image.naturalWidth
                const nh = image.naturalHeight
                if (!nw || !nh) {
                    throw new Error('Image dimensions unavailable')
                }
                const cropNorm = { x: 0, y: 0, width: 1, height: 1 }
                if (cropNorm.width < 0.01 || cropNorm.height < 0.01) {
                    throw new Error('Crop area is too small')
                }
                const ok = await onSave({ crop: cropNorm, poi: poiNorm })
                if (ok !== false) {
                    onClose()
                }
                return
            }

            const pixelCrop = convertToPixelCrop(crop, img.width, img.height)
            let cropNorm = pixelCropDisplayToNormalized(pixelCrop, img)
            if (!cropNorm) {
                throw new Error('Could not read crop from preview')
            }
            if (cropNorm.width < 0.01 || cropNorm.height < 0.01) {
                throw new Error('Crop area is too small')
            }
            const ok = await onSave({ crop: cropNorm, poi: poiNorm })
            if (ok !== false) {
                onClose()
            }
        } catch (e) {
            console.error(e)
            const msg = e?.message || 'Could not save Studio View'
            setSaveError(msg)
        }
    }

    if (!open) {
        return null
    }

    const showDecodeLoading =
        Boolean(imageSrc) && !previewLoading && (sourceDecode === 'loading' || sourceDecode === 'idle')
    const showCropper = Boolean(imageSrc) && !previewLoading && sourceDecode === 'ready'
    const showMissing = !imageSrc && !previewLoading
    const showDecodeError = Boolean(imageSrc) && !previewLoading && sourceDecode === 'error'

    const previewMaxPx = Math.min(520, Math.round(228 + (zoom - 1) * 146))

    return (
        <Dialog open={open} onClose={saving ? () => {} : onClose} className="relative z-[10200]">
            <div
                className="fixed inset-0 bg-black/50 transition-opacity duration-200 ease-out"
                style={{ opacity: panelEntered ? 1 : 0 }}
                aria-hidden
            />
            <div className="fixed inset-0 flex items-start justify-center overflow-y-auto p-4 pt-10 sm:items-center sm:p-6">
                <DialogPanel
                    className="my-auto flex w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200/90 bg-white shadow-[0_24px_80px_-24px_rgba(15,23,42,0.35)] ring-1 ring-black/[0.04] transition-[opacity,transform] duration-200"
                    style={{
                        opacity: panelEntered ? 1 : 0,
                        transform: panelEntered ? 'translateY(0) scale(1)' : 'translateY(10px) scale(0.985)',
                        transitionTimingFunction: EASE_PANEL,
                        maxHeight: 'min(90vh, 880px)',
                    }}
                >
                    <div className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 px-5 py-4 md:px-8 md:py-5">
                        <div className="min-w-0">
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                <span className="inline-flex items-center rounded-md bg-indigo-600/90 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                                    Studio
                                </span>
                                <DialogTitle className="text-lg font-semibold tracking-tight text-gray-900">
                                    Create Studio View
                                </DialogTitle>
                            </div>
                            <p className="max-w-xl text-sm leading-relaxed text-gray-500">
                                Drag the <span className="font-medium text-gray-700">edges and corners</span> of the
                                crop box to trim each side independently (free aspect). Use optional aspect lock for
                                a fixed shape. Double-click sets an optional focal point. Saved as your Studio
                                thumbnail for grids and AI.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={saving}
                            className="shrink-0 rounded-lg p-1.5 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:opacity-40"
                            aria-label="Close"
                        >
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="min-h-0 flex-1 bg-gradient-to-b from-gray-50/90 to-gray-100/80 px-4 py-4 md:px-6 md:py-5">
                        <div className="rounded-xl border border-gray-200/80 bg-white p-2 shadow-sm md:p-2.5">
                            <div className="relative overflow-hidden rounded-lg border border-gray-900/10 bg-gradient-to-b from-zinc-800 to-zinc-950 shadow-inner">
                                <div className="relative flex min-h-[260px] w-full items-center justify-center overflow-auto py-3">
                                    {previewLoading && (
                                        <div className="absolute inset-0 z-[1] flex flex-col items-center justify-center gap-3 bg-zinc-950/90 px-6 text-center">
                                            <div className="h-9 w-9 animate-pulse rounded-full bg-white/10" />
                                            <div className="h-3 w-40 max-w-full rounded bg-white/10" />
                                            <div className="h-3 w-28 max-w-full rounded bg-white/5" />
                                            <p className="text-xs text-white/55">Loading source preview…</p>
                                        </div>
                                    )}
                                    {showDecodeLoading && (
                                        <div className="absolute inset-0 z-[1] flex flex-col items-center justify-center gap-3 bg-zinc-950/90 px-6 text-center">
                                            <ArrowPathIcon className="h-8 w-8 shrink-0 animate-spin text-white/40" aria-hidden />
                                            <p className="text-xs text-white/60">Preparing image for crop…</p>
                                            <p className="max-w-xs text-[11px] leading-snug text-white/40">
                                                Large sources can take a moment on first open.
                                            </p>
                                        </div>
                                    )}
                                    {showDecodeError && (
                                        <div className="absolute inset-0 z-[1] flex flex-col items-center justify-center gap-3 bg-zinc-950 px-6 text-center">
                                            <p className="text-sm font-medium text-white/90">Couldn&apos;t load preview</p>
                                            <p className="max-w-xs text-xs leading-relaxed text-white/50">
                                                The image URL failed to load. Check your connection or close and reopen
                                                after thumbnails finish.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (!imageSrc) {
                                                        return
                                                    }
                                                    setSourceDecode('loading')
                                                    setNaturalSize({ width: 0, height: 0 })
                                                    const img = new Image()
                                                    img.onload = () => {
                                                        const d = img.decode?.()
                                                        const done = () => {
                                                            const nw = img.naturalWidth
                                                            const nh = img.naturalHeight
                                                            if (!nw || !nh) {
                                                                setSourceDecode('error')
                                                                return
                                                            }
                                                            setNaturalSize({ width: nw, height: nh })
                                                            setSourceDecode('ready')
                                                        }
                                                        if (d && typeof d.then === 'function') {
                                                            d.then(done).catch(done)
                                                        } else {
                                                            done()
                                                        }
                                                    }
                                                    img.onerror = () => setSourceDecode('error')
                                                    img.src = ''
                                                    requestAnimationFrame(() => {
                                                        img.src = imageSrc
                                                    })
                                                }}
                                                className="mt-1 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-white/15"
                                            >
                                                Retry
                                            </button>
                                        </div>
                                    )}
                                    {showMissing && (
                                        <div className="flex min-h-[260px] flex-col items-center justify-center gap-3 bg-zinc-950 px-6 text-center">
                                            <p className="text-sm font-medium text-white/90">Preview unavailable</p>
                                            <p className="max-w-xs text-xs leading-relaxed text-white/50">
                                                We couldn&apos;t load a large source image for this asset. Close and try
                                                again after thumbnails finish, or pick another file.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={onClose}
                                                className="mt-1 rounded-lg border border-white/15 bg-white/10 px-4 py-2 text-xs font-medium text-white transition-colors hover:bg-white/15"
                                            >
                                                Close
                                            </button>
                                        </div>
                                    )}
                                    {showCropper && (
                                        <ReactCrop
                                            key={`${imageSrc}-${aspectLockId}`}
                                            crop={crop}
                                            aspect={aspectLock}
                                            onChange={(_, percentCrop) => setCrop(percentCrop)}
                                            ruleOfThirds
                                            minWidth={16}
                                            minHeight={16}
                                            className="studio-react-crop mx-auto max-w-full [&_.ReactCrop__crop-selection]:border-2 [&_.ReactCrop__crop-selection]:border-white [&_.ReactCrop__drag-handle]:bg-white"
                                        >
                                            <img
                                                ref={imgRef}
                                                src={imageSrc}
                                                alt=""
                                                className="block h-auto max-w-full select-none"
                                                style={{ maxHeight: `${previewMaxPx}px` }}
                                                loading="eager"
                                                fetchPriority="high"
                                                draggable={false}
                                                onDoubleClick={handlePoiDoubleClick}
                                            />
                                        </ReactCrop>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="shrink-0 border-t border-gray-100 bg-white px-4 py-4 md:px-8 md:py-5">
                        {saveError && (
                            <div
                                className="mb-4 rounded-xl border border-red-100 bg-red-50/90 px-4 py-3 text-sm text-red-800"
                                role="alert"
                            >
                                {saveError}
                            </div>
                        )}
                        <div className="space-y-3">
                            <div className="rounded-xl border border-gray-100 bg-gray-50/90 px-4 py-3 shadow-sm">
                                <p className="mb-2 text-xs font-medium text-gray-700">Aspect lock (optional)</p>
                                <div className="flex flex-wrap gap-1.5">
                                    {ASPECT_LOCKS.map((p) => (
                                        <button
                                            key={p.id}
                                            type="button"
                                            disabled={!showCropper || saving}
                                            onClick={() => setAspectLockId(p.id)}
                                            className={`rounded-lg border px-2.5 py-1 text-[11px] font-medium transition-colors disabled:opacity-40 ${
                                                aspectLockId === p.id
                                                    ? 'border-indigo-500 bg-indigo-50 text-indigo-800'
                                                    : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50'
                                            }`}
                                        >
                                            {p.label}
                                        </button>
                                    ))}
                                </div>
                                <p className="mt-2 text-[11px] leading-snug text-gray-500">
                                    <span className="font-medium text-gray-600">Free</span> lets each side move
                                    independently. Lock a ratio when you need a consistent shape (e.g. social).
                                </p>
                            </div>
                            <div className="rounded-xl border border-gray-100 bg-gray-50/90 px-4 py-3 shadow-sm">
                                <label className="mb-2 flex items-baseline justify-between gap-3 text-xs font-medium text-gray-700">
                                    <span>Preview size</span>
                                    <span className="font-normal tabular-nums text-gray-500">{zoom.toFixed(2)}×</span>
                                </label>
                                <input
                                    type="range"
                                    min={1}
                                    max={3}
                                    step={0.05}
                                    value={zoom}
                                    onChange={(e) => setZoom(parseFloat(e.target.value))}
                                    disabled={!showCropper || saving}
                                    className="h-2 w-full cursor-pointer accent-indigo-600 disabled:opacity-40"
                                />
                                <p className="mt-2 text-[11px] leading-snug text-gray-500">
                                    Larger preview makes fine edge adjustments easier (does not change output
                                    resolution). Double-click the image for optional focal point.
                                </p>
                            </div>
                            <div className="rounded-xl border border-gray-100 bg-gray-50/90 px-4 py-3 shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-xs font-medium text-gray-700">Focal point (optional)</p>
                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            disabled={!showCropper || saving}
                                            onClick={setPoiFromCropCenter}
                                            className="rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                                        >
                                            Use crop center
                                        </button>
                                        <button
                                            type="button"
                                            disabled={!showCropper || saving || !poiNorm}
                                            onClick={() => setPoiNorm(null)}
                                            className="rounded-lg border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-40"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                </div>
                                <p className="mt-1 text-[11px] leading-snug text-gray-500">
                                    Double-click the image to set POI on the full source (0–100% from top-left). Leave
                                    cleared to omit POI from the job.
                                </p>
                                {poiNorm && (
                                    <p className="mt-2 text-[11px] font-medium tabular-nums text-indigo-800">
                                        {Math.round(poiNorm.x * 100)}% horizontal · {Math.round(poiNorm.y * 100)}%
                                        vertical
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="mt-5 flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 pt-4">
                            <button
                                type="button"
                                onClick={onClose}
                                disabled={saving}
                                className="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-800 shadow-sm transition-colors hover:bg-gray-50 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => void handleSave()}
                                disabled={saving || !showCropper}
                                className="inline-flex min-w-[10.5rem] items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {saving && (
                                    <ArrowPathIcon className="h-4 w-4 shrink-0 animate-spin" aria-hidden />
                                )}
                                {saving ? 'Saving…' : 'Save Studio View'}
                            </button>
                        </div>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    )
}
