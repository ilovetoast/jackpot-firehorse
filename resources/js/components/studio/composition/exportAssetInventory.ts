import type { BrandContext, DocumentModel, Layer } from '../../../Pages/Editor/documentModel'
import {
    isGenerativeImageLayer,
    isImageLayer,
    isTextLayer,
    isVideoLayer,
} from '../../../Pages/Editor/documentModel'
import { formatCssFontFamilyStack, resolveCanvasFontFamily } from '../../../Pages/Editor/editorBrandFonts'

const SUPPORTED_LAYER_TYPES = new Set([
    'image',
    'text',
    'generative_image',
    'fill',
    'mask',
    'video',
])

export type RasterSourceEntry = { layerId: string; url: string; kind: 'image' | 'generative' | 'video' }

export function collectVisibleRasterSources(layers: readonly Layer[]): RasterSourceEntry[] {
    const out: RasterSourceEntry[] = []
    for (const layer of layers) {
        if (!layer.visible) {
            continue
        }
        if (isImageLayer(layer) && layer.src) {
            out.push({ layerId: layer.id, url: layer.src, kind: 'image' })
        }
        if (isGenerativeImageLayer(layer) && layer.resultSrc) {
            out.push({ layerId: layer.id, url: layer.resultSrc, kind: 'generative' })
        }
        if (isVideoLayer(layer) && layer.src) {
            out.push({ layerId: layer.id, url: layer.src, kind: 'video' })
        }
    }
    return out
}

export function countVisibleTextLayers(layers: readonly Layer[]): number {
    return layers.reduce((n, l) => n + (l.visible && isTextLayer(l) ? 1 : 0), 0)
}

export function countLayersByType(layers: readonly Layer[]): Record<string, number> {
    const c: Record<string, number> = {}
    for (const l of layers) {
        const t = (l as Layer).type ?? 'unknown'
        c[t] = (c[t] ?? 0) + 1
    }
    return c
}

export function listUnsupportedLayerTypes(layers: readonly Layer[]): string[] {
    const u = new Set<string>()
    for (const l of layers) {
        const t = (l as { type?: string }).type ?? 'unknown'
        if (!SUPPORTED_LAYER_TYPES.has(t)) {
            u.add(t)
        }
    }
    return [...u]
}

/**
 * After brand stylesheets / FontFace registration, verify each visible text layer's computed stack.
 * Does not mutate the document; failures are surfaced to the export bridge.
 */
export function verifyCanvasFontsForVisibleText(
    doc: DocumentModel,
    brandContext: BrandContext | null,
): Array<{ family?: string; layerId: string; reason: string }> {
    if (typeof document === 'undefined' || typeof document.fonts?.check !== 'function') {
        return []
    }
    const failed: Array<{ family?: string; layerId: string; reason: string }> = []
    for (const layer of doc.layers) {
        if (!layer.visible || !isTextLayer(layer)) {
            continue
        }
        const resolved = resolveCanvasFontFamily(brandContext, layer.style.fontFamily)
        const stack = formatCssFontFamilyStack(resolved)
        const weight = String(layer.style.fontWeight ?? 400)
        const spec = `${weight} ${layer.style.fontSize}px ${stack}`
        try {
            if (!document.fonts.check(spec)) {
                failed.push({
                    family: stack,
                    layerId: layer.id,
                    reason: 'document.fonts.check returned false for visible text layer',
                })
            }
        } catch (e) {
            failed.push({
                family: stack,
                layerId: layer.id,
                reason: e instanceof Error ? e.message : 'document.fonts.check threw',
            })
        }
    }
    return failed
}

export function preloadRasterEntry(
    entry: RasterSourceEntry,
    timeoutMs: number,
): Promise<{ ok: true } | { ok: false; reason: string }> {
    if (entry.kind === 'video') {
        return new Promise((resolve) => {
            const v = document.createElement('video')
            let settled = false
            const finish = (r: { ok: true } | { ok: false; reason: string }) => {
                if (settled) {
                    return
                }
                settled = true
                window.clearTimeout(tid)
                v.removeAttribute('src')
                v.load()
                resolve(r)
            }
            const tid = window.setTimeout(() => finish({ ok: false, reason: 'timeout' }), timeoutMs)
            v.preload = 'metadata'
            v.muted = true
            v.playsInline = true
            v.onloadedmetadata = () => finish({ ok: true })
            v.onerror = () => finish({ ok: false, reason: 'video_error' })
            v.src = entry.url
        })
    }
    return new Promise((resolve) => {
        const img = new Image()
        let settled = false
        const finish = (r: { ok: true } | { ok: false; reason: string }) => {
            if (settled) {
                return
            }
            settled = true
            window.clearTimeout(tid)
            resolve(r)
        }
        const tid = window.setTimeout(() => finish({ ok: false, reason: 'timeout' }), timeoutMs)
        img.onload = () => finish({ ok: true })
        img.onerror = () => finish({ ok: false, reason: 'image_error' })
        img.src = entry.url
    })
}
