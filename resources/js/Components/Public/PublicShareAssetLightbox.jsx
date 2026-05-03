import { useCallback, useEffect, useState } from 'react'
import { XMarkIcon, ChevronLeftIcon, ChevronRightIcon, ArrowDownTrayIcon, DocumentIcon } from '@heroicons/react/24/outline'
import { contrastTextOnPrimary } from '../../utils/contrastTextOnPrimary'
import { saveUrlAsDownload } from '../../utils/singleAssetDownload'

function isImageMime(mime) {
    if (!mime || typeof mime !== 'string') return false
    return mime.toLowerCase().startsWith('image/')
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

export default function PublicShareAssetLightbox({
    asset,
    index,
    total,
    primaryHex,
    downloadsEnabled,
    onClose,
    onPrev,
    onNext,
}) {
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

    const onKeyDown = useCallback(
        (e) => {
            if (e.key === 'Escape') {
                e.preventDefault()
                onClose()
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault()
                onPrev()
            } else if (e.key === 'ArrowRight') {
                e.preventDefault()
                onNext()
            }
        },
        [onClose, onPrev, onNext]
    )

    useEffect(() => {
        window.addEventListener('keydown', onKeyDown)
        return () => window.removeEventListener('keydown', onKeyDown)
    }, [onKeyDown])

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

    if (!asset) return null

    const title = asset.title || asset.original_filename || 'Untitled'
    const ext = (asset.file_extension || '').toUpperCase() || 'FILE'

    return (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="public-lightbox-title">
            <button type="button" className="absolute inset-0 bg-black/75 backdrop-blur-sm" aria-label="Close preview" onClick={onClose} />
            <div className="relative z-10 flex w-full max-w-5xl max-h-[90vh] flex-col overflow-hidden rounded-2xl border border-white/15 bg-zinc-950/95 shadow-2xl shadow-black/60">
                <div className="flex items-center justify-between gap-2 border-b border-white/10 px-4 py-3">
                    <h2 id="public-lightbox-title" className="min-w-0 flex-1 truncate text-sm font-semibold text-white">
                        {title}
                    </h2>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-2 text-white/70 hover:bg-white/10 hover:text-white"
                        aria-label="Close"
                    >
                        <XMarkIcon className="h-5 w-5" />
                    </button>
                </div>
                <div className="min-h-0 flex-1 overflow-auto flex flex-col lg:flex-row">
                    <div className="flex min-h-[min(50vh,420px)] flex-1 items-center justify-center bg-black/40 p-3 sm:p-5 lg:min-h-[min(62vh,520px)]">
                        {showLargePreview ? (
                            <img
                                src={previewSrc}
                                alt=""
                                className="max-h-[min(78vh,960px)] w-full max-w-full object-contain rounded-lg shadow-lg"
                            />
                        ) : processing ? (
                            <div className="flex max-w-sm flex-col items-center gap-3 text-center text-white/80">
                                <div className="h-20 w-20 animate-pulse rounded-xl bg-white/10" />
                                <p className="text-sm">Preview still processing</p>
                            </div>
                        ) : previewSrc ? (
                            <img
                                src={previewSrc}
                                alt=""
                                className="max-h-[min(78vh,960px)] w-full max-w-full object-contain rounded-lg shadow-lg"
                            />
                        ) : (
                            <div className="flex flex-col items-center gap-2 text-white/70">
                                <DocumentIcon className="h-16 w-16 opacity-80" aria-hidden />
                                <span className="text-sm uppercase tracking-wide">{ext}</span>
                            </div>
                        )}
                    </div>
                    <div className="w-full shrink-0 border-t border-white/10 p-4 lg:w-72 lg:border-l lg:border-t-0">
                        <p className="text-xs font-medium uppercase tracking-wider text-white/45">File type</p>
                        <p className="mt-1 text-sm text-white/90">{asset.mime_type || ext}</p>
                        {downloadsEnabled && asset.download_url ? (
                            <button
                                type="button"
                                disabled={downloadBusy}
                                onClick={handleDownload}
                                className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-lg transition hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-60"
                                style={{ backgroundColor: primaryHex || '#6366f1', color: onPrimary }}
                            >
                                <ArrowDownTrayIcon className="h-4 w-4 shrink-0" />
                                {downloadBusy ? 'Preparing…' : 'Download'}
                            </button>
                        ) : null}
                    </div>
                </div>
                <div className="flex items-center justify-between gap-2 border-t border-white/10 px-2 py-2 sm:px-4">
                    <button
                        type="button"
                        onClick={onPrev}
                        disabled={total <= 1}
                        className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-30"
                    >
                        <ChevronLeftIcon className="h-5 w-5" />
                        Previous
                    </button>
                    <span className="text-xs text-white/50">
                        {index + 1} / {total}
                    </span>
                    <button
                        type="button"
                        onClick={onNext}
                        disabled={total <= 1}
                        className="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium text-white/80 hover:bg-white/10 disabled:opacity-30"
                    >
                        Next
                        <ChevronRightIcon className="h-5 w-5" />
                    </button>
                </div>
            </div>
        </div>
    )
}
