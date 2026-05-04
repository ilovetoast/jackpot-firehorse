import { useCallback, useEffect, useRef, useState } from 'react'
import {
    XMarkIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    ArrowDownTrayIcon,
    DocumentIcon,
    ArrowsPointingOutIcon,
    Square2StackIcon,
} from '@heroicons/react/24/outline'
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion'
import { contrastTextOnPrimary } from '../../utils/contrastTextOnPrimary'
import { saveUrlAsDownload } from '../../utils/singleAssetDownload'

function isImageMime(mime) {
    if (!mime || typeof mime !== 'string') return false
    return mime.toLowerCase().startsWith('image/')
}

function formatBytes(n) {
    if (n == null || Number.isNaN(Number(n))) return '—'
    const v = Number(n)
    if (v < 0) return '—'
    if (v < 1024) return `${v} B`
    if (v < 1024 * 1024) return `${(v / 1024).toFixed(1)} KB`
    return `${(v / (1024 * 1024)).toFixed(1)} MB`
}

function formatDimensions(asset) {
    const w = asset?.width != null ? Number(asset.width) : null
    const h = asset?.height != null ? Number(asset.height) : null
    if (w > 0 && h > 0) {
        return `${w.toLocaleString()} × ${h.toLocaleString()} px`
    }
    return '—'
}

/** Basename for client-side save when Content-Disposition is missing. */
function suggestedDownloadName(asset) {
    const raw = asset?.original_filename || asset?.title || 'download'
    const base = String(raw).split(/[/\\]/).pop() || 'download'
    return base.replace(/["\\]/g, '_').slice(0, 200) || 'download'
}

function absoluteUrl(href) {
    if (!href || typeof href !== 'string') return ''
    try {
        return new URL(href, window.location.origin).href
    } catch {
        return href
    }
}

/** +1 = stepped “next” (incl. last → first), -1 = stepped “prev”, 0 = unknown / first paint */
function useLightboxNavDirection(index, total) {
    const prevIndexRef = useRef(index)
    const [direction, setDirection] = useState(0)

    useEffect(() => {
        const prev = prevIndexRef.current
        const t = Math.max(1, total)
        if (prev === index) return
        let d = 0
        if ((prev + 1) % t === index) {
            d = 1
        } else if ((prev - 1 + t) % t === index) {
            d = -1
        }
        setDirection(d)
        prevIndexRef.current = index
    }, [index, total])

    return direction
}

export default function PublicShareAssetLightbox({
    asset,
    index,
    total,
    primaryHex,
    downloadsEnabled,
    /** When set, show control to include this file in the gallery “Download selected” ZIP. */
    onToggleZipSelection,
    zipSelectionIncludesAsset = false,
    zipSelectionCount = 0,
    onClose,
    onPrev,
    onNext,
}) {
    const reduceMotion = useReducedMotion()
    const navDirection = useLightboxNavDirection(index, total)
    const { color: onPrimary } = contrastTextOnPrimary(primaryHex)
    /** Grid uses small thumb; lightbox requests large → medium → small from the server. */
    const gridThumb = asset?.final_thumbnail_url || asset?.thumbnail_url
    const lightboxThumb =
        typeof asset?.thumbnail_url_lightbox === 'string' && asset.thumbnail_url_lightbox.trim()
            ? asset.thumbnail_url_lightbox.trim()
            : null
    const previewSrc = lightboxThumb || gridThumb
    const isImage = isImageMime(asset?.mime_type)
    const showLargePreview = isImage && previewSrc
    const processing =
        !previewSrc && (asset?.thumbnail_status === 'pending' || !asset?.thumbnail_status)
    const [downloadBusy, setDownloadBusy] = useState(false)
    const [fullscreenOpen, setFullscreenOpen] = useState(false)
    const [fullscreenDownloadBusy, setFullscreenDownloadBusy] = useState(false)

    const slidePx = reduceMotion ? 0 : 28
    const previewTransition = reduceMotion
        ? { duration: 0.12 }
        : { duration: 0.32, ease: [0.22, 1, 0.36, 1] }

    const previewVariants = {
        initial: (dir) => ({
            opacity: 0,
            x: dir === 0 ? 0 : dir * slidePx,
            scale: reduceMotion ? 1 : 0.985,
            filter: reduceMotion ? 'none' : 'blur(6px)',
        }),
        animate: {
            opacity: 1,
            x: 0,
            scale: 1,
            filter: 'blur(0px)',
        },
        exit: (dir) => ({
            opacity: 0,
            x: dir === 0 ? 0 : dir * -slidePx,
            scale: reduceMotion ? 1 : 0.985,
            filter: reduceMotion ? 'none' : 'blur(6px)',
        }),
    }

    const metaVariants = {
        initial: { opacity: 0, y: reduceMotion ? 0 : 6 },
        animate: { opacity: 1, y: 0 },
        exit: { opacity: 0, y: reduceMotion ? 0 : -4 },
    }

    const handleDownload = useCallback(async () => {
        if (!asset?.download_url || downloadBusy) return
        setDownloadBusy(true)
        try {
            await saveUrlAsDownload(absoluteUrl(asset.download_url), suggestedDownloadName(asset))
        } catch {
            window.alert('Download failed. Please try again.')
        } finally {
            setDownloadBusy(false)
        }
    }, [asset?.download_url, asset?.original_filename, asset?.title, downloadBusy])

    const handleFullscreenDownload = useCallback(async () => {
        if (!asset?.download_url || fullscreenDownloadBusy) return
        setFullscreenDownloadBusy(true)
        try {
            await saveUrlAsDownload(absoluteUrl(asset.download_url), suggestedDownloadName(asset))
        } catch {
            window.alert('Download failed. Please try again.')
        } finally {
            setFullscreenDownloadBusy(false)
        }
    }, [asset?.download_url, asset?.original_filename, asset?.title, fullscreenDownloadBusy])

    const onKeyDown = useCallback(
        (e) => {
            if (e.key === 'Escape') {
                e.preventDefault()
                if (fullscreenOpen) {
                    setFullscreenOpen(false)
                } else {
                    onClose()
                }
            } else if (!fullscreenOpen && e.key === 'ArrowLeft') {
                e.preventDefault()
                onPrev()
            } else if (!fullscreenOpen && e.key === 'ArrowRight') {
                e.preventDefault()
                onNext()
            }
        },
        [fullscreenOpen, onClose, onPrev, onNext]
    )

    useEffect(() => {
        window.addEventListener('keydown', onKeyDown)
        return () => window.removeEventListener('keydown', onKeyDown)
    }, [onKeyDown])

    useEffect(() => {
        if (typeof document === 'undefined') return undefined
        if (fullscreenOpen) {
            const prev = document.body.style.overflow
            document.body.style.overflow = 'hidden'
            return () => {
                document.body.style.overflow = prev
            }
        }
        return undefined
    }, [fullscreenOpen])

    useEffect(() => {
        setFullscreenOpen(false)
    }, [asset?.id])

    if (!asset) return null

    const title = asset.title || asset.original_filename || 'Untitled'
    const ext = (asset.file_extension || '').toUpperCase() || 'FILE'
    const dimensionsLabel = formatDimensions(asset)
    const sizeLabel = formatBytes(asset?.size_bytes)

    const fullscreenImageSrc = previewSrc

    const navSpring = { type: 'spring', stiffness: 420, damping: 28 }

    const renderPreviewBody = () => {
        if (showLargePreview) {
            return (
                <img
                    src={previewSrc}
                    alt=""
                    className="max-h-[min(78vh,960px)] w-full max-w-full object-contain rounded-lg shadow-lg"
                />
            )
        }
        if (processing) {
            return (
                <div className="flex max-w-sm flex-col items-center gap-3 text-center text-white/80">
                    <div className="h-20 w-20 animate-pulse rounded-xl bg-white/10" />
                    <p className="text-sm">Preview still processing</p>
                </div>
            )
        }
        if (previewSrc) {
            return (
                <img
                    src={previewSrc}
                    alt=""
                    className="max-h-[min(78vh,960px)] w-full max-w-full object-contain rounded-lg shadow-lg"
                />
            )
        }
        return (
            <div className="flex flex-col items-center gap-2 text-white/70">
                <DocumentIcon className="h-16 w-16 opacity-80" aria-hidden />
                <span className="text-sm uppercase tracking-wide">{ext}</span>
            </div>
        )
    }

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="public-lightbox-title">
            <button type="button" className="absolute inset-0 bg-black/75 backdrop-blur-sm" aria-label="Close preview" onClick={onClose} />
            <div className="relative z-10 flex w-full max-w-5xl max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-white/15 bg-zinc-950/95 shadow-2xl shadow-black/60">
                <div className="flex items-center justify-between gap-2 border-b border-white/10 px-4 py-3">
                    <h2 id="public-lightbox-title" className="min-w-0 flex-1 truncate text-sm font-semibold text-white">
                        {title}
                    </h2>
                    <div className="flex shrink-0 items-center gap-0.5">
                        {fullscreenImageSrc && isImage ? (
                            <button
                                type="button"
                                onClick={() => setFullscreenOpen(true)}
                                className="rounded-lg p-2 text-white/70 hover:bg-white/10 hover:text-white"
                                aria-label="View fullscreen"
                                title="Fullscreen"
                            >
                                <ArrowsPointingOutIcon className="h-5 w-5" />
                            </button>
                        ) : null}
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-2 text-white/70 hover:bg-white/10 hover:text-white"
                            aria-label="Close"
                        >
                            <XMarkIcon className="h-5 w-5" />
                        </button>
                    </div>
                </div>
                <div className="min-h-0 flex-1 overflow-auto flex flex-col lg:flex-row">
                    <div className="relative flex min-h-[min(50vh,420px)] flex-1 items-center justify-center overflow-hidden bg-black/40 p-3 sm:p-5 lg:min-h-[min(62vh,520px)]">
                        <AnimatePresence initial={false} custom={navDirection} mode="wait">
                            <motion.div
                                key={asset.id}
                                role="presentation"
                                custom={navDirection}
                                variants={previewVariants}
                                initial="initial"
                                animate="animate"
                                exit="exit"
                                transition={previewTransition}
                                className="flex w-full items-center justify-center"
                            >
                                {renderPreviewBody()}
                            </motion.div>
                        </AnimatePresence>
                    </div>
                    <div className="relative w-full shrink-0 overflow-hidden border-t border-white/10 p-4 lg:w-72 lg:border-l lg:border-t-0">
                        <AnimatePresence initial={false} mode="wait">
                            <motion.div
                                key={asset.id}
                                variants={metaVariants}
                                initial="initial"
                                animate="animate"
                                exit="exit"
                                transition={reduceMotion ? { duration: 0.12 } : { duration: 0.22, ease: [0.22, 1, 0.36, 1] }}
                            >
                                <p className="text-xs font-medium uppercase tracking-wider text-white/45">File type</p>
                                <p className="mt-1 text-sm text-white/90">{asset.mime_type || ext}</p>
                                <p className="mt-4 text-xs font-medium uppercase tracking-wider text-white/45">Dimensions</p>
                                <p className="mt-1 text-sm text-white/90">{dimensionsLabel}</p>
                                <p className="mt-4 text-xs font-medium uppercase tracking-wider text-white/45">Size</p>
                                <p className="mt-1 text-sm text-white/90">{sizeLabel}</p>
                                {downloadsEnabled && asset.download_url ? (
                                    <>
                                        <button
                                            type="button"
                                            disabled={downloadBusy}
                                            onClick={handleDownload}
                                            className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-lg transition hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-60"
                                            style={{ backgroundColor: primaryHex || '#6366f1', color: onPrimary }}
                                        >
                                            <ArrowDownTrayIcon className="h-4 w-4 shrink-0" />
                                            {downloadBusy ? 'Preparing…' : 'Download this file'}
                                        </button>
                                        {typeof onToggleZipSelection === 'function' ? (
                                            <>
                                                <button
                                                    type="button"
                                                    onClick={onToggleZipSelection}
                                                    className={`mt-2 inline-flex w-full items-center justify-center gap-2 rounded-lg border px-4 py-2.5 text-sm font-semibold transition ${
                                                        zipSelectionIncludesAsset
                                                            ? 'border-white/35 bg-white/10 text-white hover:bg-white/15'
                                                            : 'border-white/20 bg-transparent text-white/90 hover:bg-white/10'
                                                    }`}
                                                >
                                                    <Square2StackIcon className="h-4 w-4 shrink-0 opacity-90" aria-hidden />
                                                    {zipSelectionIncludesAsset
                                                        ? 'Remove from multi-file download'
                                                        : 'Include in multi-file download'}
                                                </button>
                                                <p className="mt-2 text-[11px] leading-snug text-white/45">
                                                    {zipSelectionIncludesAsset
                                                        ? 'This file is in your selection. Close the preview and choose Download selected to get one ZIP.'
                                                        : 'Adds this file to your gallery checkmarks so you can download several originals together as one ZIP.'}
                                                    {zipSelectionCount > 0 ? (
                                                        <span className="mt-1 block text-white/55">
                                                            {zipSelectionCount} file{zipSelectionCount !== 1 ? 's' : ''} selected
                                                            in the gallery{zipSelectionIncludesAsset ? ' (including this one).' : '.'}
                                                        </span>
                                                    ) : null}
                                                </p>
                                            </>
                                        ) : null}
                                    </>
                                ) : null}
                            </motion.div>
                        </AnimatePresence>
                    </div>
                </div>
                <div className="flex items-center justify-between gap-2 border-t border-white/10 px-2 py-2 sm:px-4">
                    <motion.button
                        type="button"
                        onClick={onPrev}
                        disabled={total <= 1}
                        whileHover={total <= 1 ? undefined : { scale: 1.04, backgroundColor: 'rgba(255,255,255,0.08)' }}
                        whileTap={total <= 1 ? undefined : { scale: 0.94 }}
                        transition={navSpring}
                        className="group inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent"
                    >
                        <ChevronLeftIcon className="h-5 w-5 shrink-0 transition-transform duration-200 ease-out group-hover:-translate-x-0.5 group-disabled:translate-x-0" />
                        Previous
                    </motion.button>
                    <motion.span
                        key={`${asset?.id}-${index}`}
                        initial={reduceMotion ? undefined : { opacity: 0.35, scale: 0.92 }}
                        animate={{ opacity: 1, scale: 1 }}
                        transition={reduceMotion ? { duration: 0.1 } : { type: 'spring', stiffness: 500, damping: 32 }}
                        className="text-xs text-white/50 tabular-nums"
                    >
                        {index + 1} / {total}
                    </motion.span>
                    <motion.button
                        type="button"
                        onClick={onNext}
                        disabled={total <= 1}
                        whileHover={total <= 1 ? undefined : { scale: 1.04, backgroundColor: 'rgba(255,255,255,0.08)' }}
                        whileTap={total <= 1 ? undefined : { scale: 0.94 }}
                        transition={navSpring}
                        className="group inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-30 disabled:hover:bg-transparent"
                    >
                        Next
                        <ChevronRightIcon className="h-5 w-5 shrink-0 transition-transform duration-200 ease-out group-hover:translate-x-0.5 group-disabled:translate-x-0" />
                    </motion.button>
                </div>
            </div>

            {fullscreenOpen && fullscreenImageSrc ? (
                <div
                    className="fixed inset-0 z-[70] flex flex-col bg-black/95"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="public-fullscreen-lightbox-title"
                >
                    <button
                        type="button"
                        className="absolute inset-0 z-0 cursor-default"
                        aria-hidden
                        tabIndex={-1}
                        onClick={() => setFullscreenOpen(false)}
                    />
                    <div className="relative z-10 flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3 sm:px-5">
                        <h2
                            id="public-fullscreen-lightbox-title"
                            className="min-w-0 flex-1 truncate text-sm font-semibold text-white sm:text-base"
                        >
                            {title}
                        </h2>
                        <div className="flex shrink-0 items-center gap-2">
                            {downloadsEnabled && asset.download_url ? (
                                <button
                                    type="button"
                                    disabled={fullscreenDownloadBusy}
                                    onClick={(e) => {
                                        e.stopPropagation()
                                        handleFullscreenDownload()
                                    }}
                                    className="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold shadow-md transition hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-60"
                                    style={{ backgroundColor: primaryHex || '#6366f1', color: onPrimary }}
                                >
                                    <ArrowDownTrayIcon className="h-4 w-4 shrink-0" />
                                    {fullscreenDownloadBusy ? 'Preparing…' : 'Quick download'}
                                </button>
                            ) : null}
                            <button
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation()
                                    setFullscreenOpen(false)
                                }}
                                className="rounded-lg p-2 text-white/80 hover:bg-white/10 hover:text-white"
                                aria-label="Exit fullscreen"
                            >
                                <XMarkIcon className="h-6 w-6" />
                            </button>
                        </div>
                    </div>
                    <div className="relative z-10 flex min-h-0 flex-1 items-center justify-center overflow-hidden p-4 sm:p-6">
                        <AnimatePresence initial={false} custom={navDirection} mode="wait">
                            <motion.img
                                key={asset.id}
                                src={fullscreenImageSrc}
                                alt=""
                                custom={navDirection}
                                variants={previewVariants}
                                initial="initial"
                                animate="animate"
                                exit="exit"
                                transition={previewTransition}
                                className="max-h-[calc(100vh-7.5rem)] max-w-[calc(100vw-2rem)] object-contain"
                            />
                        </AnimatePresence>
                    </div>
                </div>
            ) : null}
        </div>
    )
}
