import { useEffect, useMemo, useState } from 'react'
import { ArrowPathIcon } from '@heroicons/react/24/outline'

/** Uploaded library font (not Google Fonts virtual row). */
export function isUploadedFontFileAsset(a) {
    if (!a?.id || a.is_virtual_google_font) {
        return false
    }
    const mime = String(a.mime_type || '').toLowerCase()
    const ext = String(a.file_extension || a.original_filename?.split('.').pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
    return mime.startsWith('font/') || ['woff2', 'woff', 'ttf', 'otf', 'eot'].includes(ext)
}

export function uploadedFontFaceFamilyName(assetId) {
    return `ja-upload-${String(assetId).replace(/[^a-zA-Z0-9-]/g, '')}`
}

function cssFormatForAsset(asset) {
    const mime = String(asset?.mime_type || '').toLowerCase()
    if (mime.includes('woff2')) {
        return 'woff2'
    }
    if (mime.includes('woff') && !mime.includes('woff2')) {
        return 'woff'
    }
    if (mime.includes('ttf') || mime.includes('font-sfnt')) {
        return 'truetype'
    }
    if (mime.includes('otf') || mime.includes('opentype')) {
        return 'opentype'
    }
    const ext = String(asset?.file_extension || asset?.original_filename?.split('.').pop() || '')
        .toLowerCase()
        .replace(/^\./, '')
    if (ext === 'woff2') {
        return 'woff2'
    }
    if (ext === 'woff') {
        return 'woff'
    }
    if (ext === 'ttf') {
        return 'truetype'
    }
    if (ext === 'otf') {
        return 'opentype'
    }
    if (ext === 'eot') {
        return 'embedded-opentype'
    }
    return 'opentype'
}

const fontFaceLoadByAssetId = new Map()

function fontViewUrl(assetId) {
    if (typeof route === 'function') {
        try {
            return route('assets.view', { asset: assetId })
        } catch {
            /* fall through */
        }
    }
    return `/app/assets/${assetId}/view`
}

function ensureFontFaceLoaded(asset) {
    const id = asset?.id
    if (!id) {
        return Promise.reject(new Error('no asset id'))
    }
    const existing = fontFaceLoadByAssetId.get(id)
    if (existing) {
        return existing
    }
    const family = uploadedFontFaceFamilyName(id)
    const url = fontViewUrl(id)
    const fmt = cssFormatForAsset(asset)
    const src = `url("${url}") format("${fmt}")`
    const p = new FontFace(family, src)
        .load()
        .then((face) => {
            document.fonts.add(face)
            return face
        })
        .catch((err) => {
            fontFaceLoadByAssetId.delete(id)
            throw err
        })
    fontFaceLoadByAssetId.set(id, p)
    return p
}

/**
 * Specimen preview for uploaded font files (loads face from authenticated `assets.view` URL).
 * @param {'drawer'|'lightbox'} variant
 */
export default function UploadedFontSpecimenPreview({
    asset,
    variant = 'drawer',
    disableFontLoad = false,
}) {
    const [ready, setReady] = useState(false)
    const [error, setError] = useState(false)
    const [loading, setLoading] = useState(!disableFontLoad)

    const family = useMemo(() => (asset?.id ? uploadedFontFaceFamilyName(asset.id) : ''), [asset?.id])

    const title = asset?.title || asset?.original_filename || 'Font'

    useEffect(() => {
        if (!asset?.id || disableFontLoad) {
            setReady(false)
            setError(false)
            setLoading(false)
            return
        }
        let cancelled = false
        setLoading(true)
        setError(false)
        setReady(false)
        ensureFontFaceLoaded(asset)
            .then(() => {
                if (!cancelled) {
                    setReady(true)
                    setLoading(false)
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setError(true)
                    setLoading(false)
                }
            })
        return () => {
            cancelled = true
        }
    }, [asset?.id, asset?.mime_type, asset?.file_extension, asset?.original_filename, disableFontLoad])

    const isLightbox = variant === 'lightbox'
    const shell = isLightbox
        ? 'flex max-h-full w-full max-w-2xl flex-col items-center justify-center rounded-xl bg-gradient-to-br from-slate-800 to-slate-900 p-8 text-center text-white'
        : 'flex h-full min-h-[14rem] w-full flex-col items-center justify-center rounded-xl bg-gradient-to-b from-white via-slate-50 to-sky-100/75 p-6 text-center text-zinc-800 ring-1 ring-slate-200/90 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)]'

    const badge = isLightbox
        ? 'rounded-full bg-violet-500/25 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-100'
        : 'rounded-full bg-violet-100 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800'

    const muted = isLightbox ? 'text-white/65' : 'text-slate-600'
    const specimenMuted = isLightbox ? 'text-white/55' : 'text-slate-500'
    const errColor = isLightbox ? 'text-amber-200' : 'text-amber-700'

    const ff = ready && family ? `"${family}", ui-sans-serif, system-ui, sans-serif` : 'ui-sans-serif, system-ui, sans-serif'

    return (
        <div className={shell}>
            <span className={badge}>Font file</span>
            <p className={`mt-3 max-w-md text-xs ${muted}`}>
                {disableFontLoad
                    ? 'Preview uses your system fonts in this view.'
                    : 'Loads your uploaded file for this preview only (same as download — stays in your browser).'}
            </p>

            {loading && !disableFontLoad && (
                <div className={`mt-4 flex items-center gap-2 text-sm ${specimenMuted}`}>
                    <ArrowPathIcon className="h-5 w-5 shrink-0 animate-spin" aria-hidden />
                    Loading font…
                </div>
            )}

            <span
                className={`mt-6 font-semibold leading-none tracking-tight ${isLightbox ? 'text-7xl text-white' : 'text-6xl'}`}
                style={{ fontFamily: ff }}
            >
                Aa
            </span>
            <p
                className={`mt-5 max-w-[95%] text-lg font-medium ${isLightbox ? 'text-white/95' : 'text-slate-700'}`}
                style={{ fontFamily: ff }}
            >
                {title}
            </p>
            <p
                className={`mt-4 max-w-lg text-sm leading-relaxed ${specimenMuted}`}
                style={{ fontFamily: ff }}
            >
                The quick brown fox jumps over the lazy dog. 0123456789
            </p>

            {error && (
                <p className={`mt-4 max-w-sm text-xs ${errColor}`}>
                    Could not load this font for preview (format or permissions). You can still download the original file.
                </p>
            )}
        </div>
    )
}
