import { useCallback, useEffect, useRef, useState } from 'react'
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react'
import axios from 'axios'

function clamp01(v) {
    return Math.min(1, Math.max(0, v))
}

/**
 * Map click to normalized coords (matches object-fit: cover preview in this modal).
 *
 * @param {React.MouseEvent<HTMLImageElement>} e
 * @param {HTMLImageElement} img
 * @returns {{ x: number, y: number }|null}
 */
function normalizedPointFromImageClick(e, img) {
    const rect = img.getBoundingClientRect()
    const x = (e.clientX - rect.left) / rect.width
    const y = (e.clientY - rect.top) / rect.height
    return { x: clamp01(x), y: clamp01(y) }
}

/**
 * Modal: click image to set guidelines focal point (saved on asset metadata).
 *
 * @param {object} props
 * @param {boolean} props.open
 * @param {() => void} props.onClose
 * @param {string|null|undefined} props.imageUrl
 * @param {{ x: number, y: number }|null|undefined} props.initialFocal
 * @param {string} props.brandId
 * @param {string} props.assetId
 * @param {(fp: { x: number, y: number }|null) => void} props.onSaved
 */
export default function GuidelinesFocalPointModal({ open, onClose, imageUrl, initialFocal, brandId, assetId, onSaved }) {
    const imgRef = useRef(null)
    const [point, setPoint] = useState(null)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState(null)

    useEffect(() => {
        if (!open) return undefined
        setError(null)
        setSaving(false)
        setPoint(
            initialFocal && typeof initialFocal.x === 'number' && typeof initialFocal.y === 'number'
                ? { x: initialFocal.x, y: initialFocal.y }
                : { x: 0.5, y: 0.35 },
        )
        return undefined
    }, [open, initialFocal, imageUrl])

    const handleImgClick = useCallback((e) => {
        const img = imgRef.current
        if (!img) return
        const p = normalizedPointFromImageClick(e, img)
        if (p) setPoint(p)
    }, [])

    const handleSave = useCallback(async () => {
        if (!point || !assetId || !brandId) return
        setSaving(true)
        setError(null)
        try {
            const res = await axios.patch(
                route('brands.brand-dna.builder.asset-guidelines-focal-point', { brand: brandId, asset: assetId }),
                { x: point.x, y: point.y },
            )
            const fp = res.data?.focal_point ?? point
            onSaved?.(fp)
            onClose()
        } catch (err) {
            const msg = err?.response?.data?.message || err?.message || 'Could not save focal point'
            setError(typeof msg === 'string' ? msg : 'Could not save focal point')
        } finally {
            setSaving(false)
        }
    }, [point, assetId, brandId, onSaved, onClose])

    const handleClear = useCallback(async () => {
        if (!assetId || !brandId) return
        setSaving(true)
        setError(null)
        try {
            await axios.patch(
                route('brands.brand-dna.builder.asset-guidelines-focal-point', { brand: brandId, asset: assetId }),
                { clear: true },
            )
            onSaved?.(null)
            onClose()
        } catch (err) {
            const msg = err?.response?.data?.message || err?.message || 'Could not clear focal point'
            setError(typeof msg === 'string' ? msg : 'Could not clear focal point')
        } finally {
            setSaving(false)
        }
    }, [assetId, brandId, onSaved, onClose])

    return (
        <Dialog open={open} onClose={saving ? () => {} : onClose} className="relative z-[80]">
            <div className="fixed inset-0 bg-black/70" aria-hidden="true" />
            <div className="fixed inset-0 flex items-center justify-center p-4">
                <DialogPanel className="w-full max-w-lg rounded-2xl bg-[#111] border border-white/10 shadow-2xl p-5 text-white">
                    <DialogTitle className="text-lg font-semibold text-white/95">Focal point for guidelines</DialogTitle>
                    <p className="mt-1 text-sm text-white/55">
                        Click the important area (e.g. faces). Guidelines will crop toward this point when using fill
                        layouts.
                    </p>
                    <div className="mt-4 relative rounded-xl overflow-hidden bg-black/40 border border-white/10 aspect-[4/3] cursor-crosshair">
                        {imageUrl ? (
                            <img
                                ref={imgRef}
                                src={imageUrl}
                                alt=""
                                className="w-full h-full object-cover select-none"
                                onClick={handleImgClick}
                                draggable={false}
                            />
                        ) : (
                            <div className="w-full h-full flex items-center justify-center text-white/40 text-sm">No preview</div>
                        )}
                        {point && (
                            <div
                                className="pointer-events-none absolute w-4 h-4 -ml-2 -mt-2 rounded-full border-2 border-white shadow-lg bg-cyan-400/40"
                                style={{
                                    left: `${point.x * 100}%`,
                                    top: `${point.y * 100}%`,
                                }}
                            />
                        )}
                    </div>
                    {point && (
                        <p className="mt-2 text-xs text-white/40 font-mono">
                            {Math.round(point.x * 100)}% · {Math.round(point.y * 100)}%
                        </p>
                    )}
                    {error && <p className="mt-2 text-sm text-red-400">{error}</p>}
                    <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
                        <button
                            type="button"
                            onClick={handleClear}
                            disabled={saving}
                            className="px-3 py-2 text-sm text-white/50 hover:text-white/80 disabled:opacity-40"
                        >
                            Clear
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={saving}
                            className="px-4 py-2 rounded-lg border border-white/15 text-sm text-white/80 hover:bg-white/5 disabled:opacity-40"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving || !point}
                            className="px-4 py-2 rounded-lg text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40"
                        >
                            {saving ? 'Saving…' : 'Save'}
                        </button>
                    </div>
                </DialogPanel>
            </div>
        </Dialog>
    )
}
