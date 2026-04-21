/**
 * Helpers for Studio Versions rail (base variant, chips, low-count hints).
 * @param {Array<{ composition_id: string, sort_order: number, axis?: Record<string, unknown> }>} variants
 */
export function getBaseCompositionId(variants) {
    if (!Array.isArray(variants) || variants.length === 0) {
        return null
    }
    const sorted = [...variants].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0))
    return sorted[0]?.composition_id ?? null
}

/** @param {unknown} axis */
export function variantHasAxisMetadata(axis) {
    if (!axis || typeof axis !== 'object') {
        return false
    }
    const a = /** @type {Record<string, unknown>} */ (axis)
    return Boolean(a.color || a.scene || a.format)
}

/**
 * Short labels for micro-chips (max 3).
 * @param {unknown} axis
 * @returns {string[]}
 */
export function getVariantAxisChipTexts(axis) {
    if (!axis || typeof axis !== 'object') {
        return []
    }
    const a = /** @type {Record<string, unknown>} */ (axis)
    const out = []
    const color = a.color
    if (color && typeof color === 'object') {
        const label = /** @type {Record<string, unknown>} */ (color).label
        if (label != null && String(label).trim()) {
            out.push(String(label).trim())
        }
    }
    const scene = a.scene
    if (scene && typeof scene === 'object') {
        const label = /** @type {Record<string, unknown>} */ (scene).label
        if (label != null && String(label).trim()) {
            out.push(String(label).trim())
        }
    }
    const format = a.format
    if (format && typeof format === 'object') {
        const f = /** @type {Record<string, unknown>} */ (format)
        const label = f.label != null ? String(f.label).trim() : ''
        const w = f.width
        const h = f.height
        if (label) {
            out.push(label)
        } else if (w != null && h != null) {
            out.push(`${w}×${h}`)
        }
    }
    return out.slice(0, 3)
}

/**
 * @param {number} variantCount
 */
export function shouldShowVersionHints(variantCount) {
    return variantCount > 0 && variantCount <= 2
}
