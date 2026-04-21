/**
 * Studio Creative Set — axis-aware quick selection for “Selected versions” sync.
 * Uses variant.axis from generation ({ scene, color, format, combination_key }).
 */

/** @param {unknown} axis */
function readSceneRef(axis) {
    if (!axis || typeof axis !== 'object') {
        return null
    }
    const scene = /** @type {Record<string, unknown>} */ (axis).scene
    if (!scene || typeof scene !== 'object') {
        return null
    }
    const id = scene.id != null ? String(scene.id).trim() : ''
    const label = scene.label != null ? String(scene.label).trim() : ''
    if (!id && !label) {
        return null
    }
    return { id, label }
}

/** @param {unknown} axis */
function readFormatRef(axis) {
    if (!axis || typeof axis !== 'object') {
        return null
    }
    const format = /** @type {Record<string, unknown>} */ (axis).format
    if (!format || typeof format !== 'object') {
        return null
    }
    const id = format.id != null ? String(format.id).trim() : ''
    const label = format.label != null ? String(format.label).trim() : ''
    const w = format.width != null ? Number(format.width) : NaN
    const h = format.height != null ? Number(format.height) : NaN
    if (!id && !label) {
        return null
    }
    return { id, label, width: Number.isFinite(w) ? w : null, height: Number.isFinite(h) ? h : null }
}

function readColorRef(axis) {
    if (!axis || typeof axis !== 'object') {
        return null
    }
    const color = /** @type {Record<string, unknown>} */ (axis).color
    if (!color || typeof color !== 'object') {
        return null
    }
    const id = color.id != null ? String(color.id).trim() : ''
    const label = color.label != null ? String(color.label).trim() : ''
    if (!id && !label) {
        return null
    }
    return { id, label }
}

/**
 * @param {{ id: string, label: string }} a
 * @param {{ id: string, label: string }} b
 */
function axisRefsEqual(a, b) {
    if (a.id && b.id) {
        return a.id === b.id
    }
    if (a.label && b.label) {
        return a.label === b.label
    }
    return false
}

/**
 * @param {{ id: string, label: string, width: number | null, height: number | null }} a
 * @param {{ id: string, label: string, width: number | null, height: number | null }} b
 */
function formatRefsEqual(a, b) {
    if (a.id && b.id) {
        return a.id === b.id
    }
    if (a.width != null && a.height != null && b.width != null && b.height != null) {
        return a.width === b.width && a.height === b.height
    }
    if (a.label && b.label) {
        return a.label === b.label
    }
    return false
}

/**
 * @param {Array<{ composition_id: string, axis?: Record<string, unknown> }>} variants
 * @param {string} activeCompositionId
 * @returns {{ ids: string[], ref: { id: string, label: string } | null, disabled: 'none' | 'no_active_variant' | 'missing_axis' | 'no_matches' }}
 */
export function buildSameSceneSelection(variants, activeCompositionId) {
    const active = variants.find((v) => v.composition_id === activeCompositionId)
    if (!active) {
        return { ids: [], ref: null, disabled: 'no_active_variant' }
    }
    const ref = readSceneRef(active.axis)
    if (!ref) {
        return { ids: [], ref: null, disabled: 'missing_axis' }
    }
    const ids = []
    for (const v of variants) {
        if (v.composition_id === activeCompositionId) {
            continue
        }
        const other = readSceneRef(v.axis)
        if (other && axisRefsEqual(ref, other)) {
            ids.push(v.composition_id)
        }
    }
    if (ids.length === 0) {
        return { ids: [], ref, disabled: 'no_matches' }
    }
    return { ids, ref, disabled: 'none' }
}

/**
 * @param {Array<{ composition_id: string, axis?: Record<string, unknown> }>} variants
 * @param {string} activeCompositionId
 * @returns {{ ids: string[], ref: { id: string, label: string } | null, disabled: 'none' | 'no_active_variant' | 'missing_axis' | 'no_matches' }}
 */
export function buildSameColorSelection(variants, activeCompositionId) {
    const active = variants.find((v) => v.composition_id === activeCompositionId)
    if (!active) {
        return { ids: [], ref: null, disabled: 'no_active_variant' }
    }
    const ref = readColorRef(active.axis)
    if (!ref) {
        return { ids: [], ref: null, disabled: 'missing_axis' }
    }
    const ids = []
    for (const v of variants) {
        if (v.composition_id === activeCompositionId) {
            continue
        }
        const other = readColorRef(v.axis)
        if (other && axisRefsEqual(ref, other)) {
            ids.push(v.composition_id)
        }
    }
    if (ids.length === 0) {
        return { ids: [], ref, disabled: 'no_matches' }
    }
    return { ids, ref, disabled: 'none' }
}

/**
 * @param {Array<{ composition_id: string, axis?: Record<string, unknown> }>} variants
 * @param {string} activeCompositionId
 * @returns {{ ids: string[], ref: { id: string, label: string, width: number | null, height: number | null } | null, disabled: 'none' | 'no_active_variant' | 'missing_axis' | 'no_matches' }}
 */
export function buildSameFormatSelection(variants, activeCompositionId) {
    const active = variants.find((v) => v.composition_id === activeCompositionId)
    if (!active) {
        return { ids: [], ref: null, disabled: 'no_active_variant' }
    }
    const ref = readFormatRef(active.axis)
    if (!ref) {
        return { ids: [], ref: null, disabled: 'missing_axis' }
    }
    const ids = []
    for (const v of variants) {
        if (v.composition_id === activeCompositionId) {
            continue
        }
        const other = readFormatRef(v.axis)
        if (other && formatRefsEqual(ref, other)) {
            ids.push(v.composition_id)
        }
    }
    if (ids.length === 0) {
        return { ids: [], ref, disabled: 'no_matches' }
    }
    return { ids, ref, disabled: 'none' }
}
