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

/**
 * Same-origin binary stream (not {@code assets.view}, which returns Inertia HTML unless JSON Accept).
 * @see EditorAssetBridgeController::file
 */
function fontBinaryApiUrl(assetId) {
    if (typeof route === 'function') {
        try {
            return route('api.editor.assets.file', { asset: assetId })
        } catch {
            /* fall through */
        }
    }
    return `/app/api/assets/${assetId}/file`
}

/**
 * Fallback when API bridge rejects (e.g. brand context): {@code assets.view} with {@code font_inline=1}
 * returns raw bytes from {@see AssetController::view}.
 */
function fontInlineViewUrl(assetId) {
    const base =
        typeof route === 'function'
            ? (() => {
                  try {
                      return route('assets.view', { asset: assetId })
                  } catch {
                      return `/app/assets/${assetId}/view`
                  }
              })()
            : `/app/assets/${assetId}/view`
    const sep = base.includes('?') ? '&' : '?'
    return `${base}${sep}font_inline=1`
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

    const loadFromArrayBuffer = (buffer) => {
        const face = new FontFace(family, buffer)
        return face.load().then((loaded) => {
            document.fonts.add(loaded)
            return loaded
        })
    }

    const tryUrlFontFace = () => {
        const url = fontInlineViewUrl(id)
        const fmt = cssFormatForAsset(asset)
        const src = `url("${url}") format("${fmt}")`
        return new FontFace(family, src)
            .load()
            .then((face) => {
                document.fonts.add(face)
                return face
            })
    }

    const p = fetch(fontBinaryApiUrl(id), { credentials: 'same-origin' })
        .then(async (res) => {
            if (!res.ok) {
                throw new Error(`font api ${res.status}`)
            }
            return res.arrayBuffer()
        })
        .then((buf) => loadFromArrayBuffer(buf))
        .catch(() => tryUrlFontFace())
        .catch((err) => {
            fontFaceLoadByAssetId.delete(id)
            throw err
        })
    fontFaceLoadByAssetId.set(id, p)
    return p
}

/**
 * Specimen preview for uploaded font files: loads bytes via same-origin
 * {@code GET /app/api/assets/{id}/file}, with fallbacks to {@code assets.view?font_inline=1} then URL+format.
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
        : 'flex h-full min-h-0 w-full min-w-0 flex-col items-stretch overflow-hidden rounded-xl bg-gradient-to-b from-white via-slate-50 to-sky-100/75 px-3 py-2.5 text-center text-zinc-800 ring-1 ring-slate-200/90 shadow-[inset_0_1px_0_rgba(255,255,255,0.85)] sm:px-4 sm:py-3'

    const badge = isLightbox
        ? 'rounded-full bg-violet-500/25 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-100'
        : 'self-center rounded-full bg-violet-100 px-2 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-violet-800 sm:text-[10px]'

    const muted = isLightbox ? 'text-white/65' : 'text-slate-600'
    const specimenMuted = isLightbox ? 'text-white/55' : 'text-slate-500'
    const errColor = isLightbox ? 'text-amber-200' : 'text-amber-700'

    const ff = ready && family ? `"${family}", ui-sans-serif, system-ui, sans-serif` : 'ui-sans-serif, system-ui, sans-serif'

    const helperBlurb = disableFontLoad
        ? 'Preview uses your system fonts in this view.'
        : isLightbox
          ? 'Loads your uploaded file for this preview only (same as download — stays in your browser).'
          : 'Preview loads in your browser only (same as download).'

    return (
        <div className={shell}>
            <div className={`flex shrink-0 flex-col items-center gap-1 ${isLightbox ? '' : 'gap-0.5'}`}>
                <span className={badge}>Font file</span>
                <p
                    className={`max-w-full text-pretty ${isLightbox ? 'mt-3 max-w-md text-xs' : 'text-[10px] leading-snug sm:text-[11px]'} ${muted}`}
                >
                    {helperBlurb}
                </p>
            </div>

            {loading && !disableFontLoad && (
                <div
                    className={`flex shrink-0 items-center justify-center gap-1.5 ${isLightbox ? `mt-4 text-sm ${specimenMuted}` : `mt-1.5 text-[11px] ${specimenMuted}`}`}
                >
                    <ArrowPathIcon className={`shrink-0 animate-spin ${isLightbox ? 'h-5 w-5' : 'h-3.5 w-3.5'}`} aria-hidden />
                    Loading…
                </div>
            )}

            <div
                className={`flex min-h-0 flex-1 flex-col items-center justify-center overflow-y-auto overflow-x-hidden ${isLightbox ? 'gap-0' : 'gap-0.5 py-1'}`}
            >
                <span
                    className={`font-semibold leading-none tracking-tight ${isLightbox ? 'mt-6 text-7xl text-white' : 'text-3xl sm:text-4xl'}`}
                    style={{ fontFamily: ff }}
                >
                    Aa
                </span>
                <p
                    className={`max-w-full truncate px-0.5 font-medium ${isLightbox ? 'mt-5 max-w-[95%] text-lg text-white/95' : 'text-xs text-slate-700 sm:text-sm'}`}
                    style={{ fontFamily: ff }}
                    title={title}
                >
                    {title}
                </p>
                <p
                    className={`w-full max-w-full px-0.5 ${isLightbox ? 'mt-4 max-w-lg text-sm leading-relaxed' : 'text-[10px] leading-tight sm:text-[11px] sm:leading-snug'} ${specimenMuted}`}
                    style={{ fontFamily: ff }}
                >
                    <span className={isLightbox ? '' : 'break-words [overflow-wrap:anywhere]'}>
                        The quick brown fox jumps over the lazy dog. 0123456789
                    </span>
                </p>
            </div>

            {error && (
                <p
                    className={`max-w-full shrink-0 text-pretty ${isLightbox ? `mt-4 max-w-sm text-xs ${errColor}` : `mt-1 text-[10px] leading-snug ${errColor}`}`}
                >
                    Could not load this font for preview (format or permissions). You can still download the original file.
                </p>
            )}
        </div>
    )
}
