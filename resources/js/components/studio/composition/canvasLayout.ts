import type { GenerativeImageLayer, ImageLayer, Layer, VideoLayer } from '../../../Pages/Editor/documentModel'

/** CSS object-fit for canvas `<img>` / `<video>` — Tailwind class names (JIT-safe) + canonical value. */
export function canvasImageObjectFit(
    fit: ImageLayer['fit'] | GenerativeImageLayer['fit'] | VideoLayer['fit'] | undefined,
): { className: 'object-contain' | 'object-cover' | 'object-fill'; value: 'contain' | 'cover' | 'fill' } {
    if (fit === 'contain') {
        return { className: 'object-contain', value: 'contain' }
    }
    if (fit === 'fill') {
        return { className: 'object-fill', value: 'fill' }
    }
    return { className: 'object-cover', value: 'cover' }
}

/** Paint order: ascending z (same as editor canvas). */
export function sortLayersForCanvas(layers: Layer[]): Layer[] {
    return [...layers].sort((a, b) => {
        const za = Number(a.z)
        const zb = Number(b.z)
        const d = (Number.isFinite(za) ? za : 0) - (Number.isFinite(zb) ? zb : 0)
        return d !== 0 ? d : a.id.localeCompare(b.id)
    })
}
