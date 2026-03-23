/**
 * Loads brand typography for the generative editor.
 *
 * - Stylesheets (Google Fonts CSS, etc.): <link rel="stylesheet"> — public HTTPS only.
 * - Licensed / uploaded binaries: FontFace API + GET /app/api/assets/{id}/file (same-origin, session cookie).
 *   Asset IDs come only from server-built brand context (Brand DNA), not user input.
 */
import type { BrandContext } from './documentModel'

const loadedStylesheetUrls = new Set<string>()
const loadedFontFaceKeys = new Set<string>()

const isDev = typeof import.meta !== 'undefined' && import.meta.env?.DEV === true

function logBrandFont(...args: unknown[]): void {
    const force =
        typeof window !== 'undefined' && window.localStorage?.getItem('debugBrandFonts') === '1'
    if (isDev || force) {
        console.info('[EditorBrandFonts]', ...args)
    }
}

function fontFaceKey(assetId: number, weight: string, style: string): string {
    return `${assetId}:${weight}:${style}`
}

function isLikelyBinaryFontUrl(url: string): boolean {
    if (/\/assets\/\d+\/download/i.test(url)) {
        return true
    }
    const u = url.toLowerCase()
    return ['.woff2', '.woff', '.otf', '.ttf', '.eot'].some((ext) => u.split('?')[0].endsWith(ext))
}

function isLikelyStylesheetUrl(url: string): boolean {
    if (!url || isLikelyBinaryFontUrl(url)) {
        return false
    }
    const u = url.toLowerCase()
    return (
        u.includes('fonts.googleapis.com') ||
        u.includes('fonts.bunny.net') ||
        u.includes('/css2?') ||
        u.includes('/css?') ||
        u.endsWith('.css')
    )
}

function injectStylesheet(url: string): Promise<void> {
    if (!url || loadedStylesheetUrls.has(url)) {
        return Promise.resolve()
    }
    loadedStylesheetUrls.add(url)
    return new Promise((resolve) => {
        const link = document.createElement('link')
        link.href = url
        link.rel = 'stylesheet'
        link.crossOrigin = 'anonymous'
        link.onload = () => resolve()
        link.onerror = () => resolve()
        document.head.appendChild(link)
    })
}

async function injectFontFaceFromAsset(
    family: string,
    assetId: number,
    weight: string,
    style: string
): Promise<void> {
    const key = fontFaceKey(assetId, weight, style)
    if (loadedFontFaceKeys.has(key)) {
        logBrandFont('skip (already loaded)', { family, assetId, weight, style })
        return
    }
    loadedFontFaceKeys.add(key)

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content
    const url = `/app/api/assets/${assetId}/file`
    logBrandFont('fetch font bytes', { url, family, weight, style })
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'font/woff2,font/woff,font/ttf,application/octet-stream,*/*',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
        },
    })
    if (!res.ok) {
        logBrandFont('fetch failed', { url, status: res.status, family })
        loadedFontFaceKeys.delete(key)
        return
    }
    const buf = await res.arrayBuffer()
    if (buf.byteLength === 0) {
        logBrandFont('empty response', { url, family })
        loadedFontFaceKeys.delete(key)
        return
    }

    try {
        const face = new FontFace(family, buf, {
            weight: weight || '400',
            style: style || 'normal',
        })
        await face.load()
        document.fonts.add(face)
        logBrandFont('FontFace registered', { family, bytes: buf.byteLength })
    } catch (e) {
        logBrandFont('FontFace load error', { family, error: e })
        loadedFontFaceKeys.delete(key)
    }
}

/**
 * Loads stylesheets + registered font files for the active brand.
 * Safe to call repeatedly (idempotent).
 */
export async function loadEditorBrandTypography(typography: BrandContext['typography'] | undefined): Promise<void> {
    if (!typography) {
        logBrandFont('load skipped: no typography on brand context')
        return
    }

    const sheetList = typography.stylesheet_urls?.length
        ? typography.stylesheet_urls
        : (typography.font_urls ?? []).filter((u) => u && isLikelyStylesheetUrl(u))

    const faces = typography.font_face_sources ?? []
    logBrandFont('loadEditorBrandTypography', {
        stylesheets: sheetList.length,
        fontFaceSources: faces.length,
        canvasPrimary: typography.canvas_primary_font_family ?? null,
    })
    if (faces.length === 0) {
        logBrandFont(
            'no font_face_sources — check brand DNA font file URLs match /assets/{id}/download or /file; licensed fonts will not fetch'
        )
    }

    const sheetTasks = sheetList.map((u) => injectStylesheet(u))
    const faceTasks = faces.map((f) =>
        injectFontFaceFromAsset(f.family, f.asset_id, f.weight ?? '400', f.style ?? 'normal')
    )

    await Promise.all([...sheetTasks, ...faceTasks])
    logBrandFont('loadEditorBrandTypography done')
}

function firstFontFamilyToken(fontFamily: string): string {
    return fontFamily.split(',')[0].trim().replace(/^["']|["']$/g, '')
}

/**
 * Quote a single family name when needed so CSS `font-family` matches {@link FontFace} registration.
 * Unquoted `RBNo3.1 Bold` is parsed as two families and never matches one face.
 */
export function quoteCssFontFamilyName(name: string): string {
    const n = name.trim().replace(/^["']|["']$/g, '')
    if (!n) {
        return name
    }
    if (/^[a-zA-Z][a-zA-Z0-9_-]*$/.test(n)) {
        return n
    }
    return `"${n.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
}

/** Apply quoting to the first family in a stack; keep fallbacks after the first comma as-is. */
export function formatCssFontFamilyStack(stack: string): string {
    const comma = stack.indexOf(',')
    const head = comma >= 0 ? stack.slice(0, comma).trim() : stack.trim()
    const tail = comma >= 0 ? stack.slice(comma) : ''
    if (!head) {
        return stack
    }
    const quoted = quoteCssFontFamilyName(head)
    return tail ? `${quoted}${tail}` : quoted
}

function fontFamilyTokensMatch(a: string, b: string): boolean {
    return firstFontFamilyToken(a).toLowerCase() === firstFontFamilyToken(b).toLowerCase()
}

/** Match short labels (e.g. "rbno") to registered names (e.g. "RBNo3.1 Bold"). */
function familiesLooselyMatch(candidate: string, faceFamily: string): boolean {
    const a = candidate.toLowerCase()
    const b = faceFamily.toLowerCase()
    if (a === b) {
        return true
    }
    if (a.includes(b) || b.includes(a)) {
        return true
    }
    const strip = (s: string) =>
        s
            .replace(/\b(bold|medium|light|regular|italic|black|heavy|thin|book|semibold|extrabold)\b/gi, '')
            .replace(/\s+/g, ' ')
            .trim()
    const a2 = strip(a)
    const b2 = strip(b)
    return Boolean(a2 && b2 && (a2.includes(b2) || b2.includes(a2)))
}

/**
 * Map stored CSS font-family (primary/secondary labels, short names) to the exact family name
 * used when registering {@link BrandContext.typography.font_face_sources} via FontFace API.
 * If they differ (e.g. primary "rbno" vs face "RBNo2.1"), the browser would otherwise fall back to a system serif.
 */
export function resolveCanvasFontFamily(
    brand: BrandContext | null | undefined,
    cssFontFamily: string
): string {
    const faces = brand?.typography?.font_face_sources
    if (!faces?.length) {
        return cssFontFamily
    }

    const first = firstFontFamilyToken(cssFontFamily)
    if (!first) {
        return cssFontFamily
    }

    const comma = cssFontFamily.indexOf(',')
    const rest = comma >= 0 ? cssFontFamily.slice(comma) : ''

    const byExact = faces.find(
        (f) => firstFontFamilyToken(f.family).toLowerCase() === first.toLowerCase()
    )
    if (byExact) {
        return `${byExact.family}${rest}`
    }

    const primary = brand?.typography?.primary_font?.trim()
    const canvasPrimary = brand?.typography?.canvas_primary_font_family?.trim()
    if (primary && canvasPrimary && fontFamilyTokensMatch(cssFontFamily, primary)) {
        const face = faces.find(
            (f) => firstFontFamilyToken(f.family).toLowerCase() === canvasPrimary.toLowerCase()
        )
        if (face) {
            return `${face.family}${rest}`
        }
    }

    const tryResolve = (label: string | null | undefined): string | null => {
        if (!label?.trim()) {
            return null
        }
        if (!fontFamilyTokensMatch(cssFontFamily, label)) {
            return null
        }
        const tok = firstFontFamilyToken(label)
        const face = faces.find(
            (f) =>
                f.family.toLowerCase().includes(tok.toLowerCase()) ||
                tok.toLowerCase().includes(firstFontFamilyToken(f.family).toLowerCase())
        )
        return face ? `${face.family}${rest}` : null
    }

    const fromLabels =
        tryResolve(canvasPrimary) ??
        tryResolve(brand?.typography?.primary_font) ??
        tryResolve(brand?.typography?.secondary_font)
    if (fromLabels) {
        return fromLabels
    }

    const loose = faces.find((f) => familiesLooselyMatch(first, f.family))
    if (loose) {
        return `${loose.family}${rest}`
    }

    return cssFontFamily
}

/** Ask the browser to paint with this face after FontFace / @font-face registration (avoids fallback flash). */
export async function ensureCanvasFontLoaded(
    cssFontFamily: string,
    fontSizePx: number,
    fontWeight: number | string
): Promise<void> {
    if (typeof document === 'undefined' || !document.fonts?.load) {
        return
    }
    const fam = firstFontFamilyToken(cssFontFamily)
    if (!fam) {
        return
    }
    const w = fontWeight ?? 400
    const famQuoted = quoteCssFontFamilyName(fam)
    try {
        await document.fonts.load(`${w} ${fontSizePx}px ${famQuoted}`)
    } catch {
        /* ignore */
    }
    await document.fonts.ready
}
