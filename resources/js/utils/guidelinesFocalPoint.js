/**
 * @param {{ x?: number, y?: number }|null|undefined} fp — normalized 0–1 from asset metadata
 * @returns {{ objectPosition: string }|undefined}
 */
export function guidelinesFocalPointStyle(fp) {
    if (!fp || typeof fp.x !== 'number' || typeof fp.y !== 'number') return undefined
    const x = Math.min(100, Math.max(0, fp.x * 100))
    const y = Math.min(100, Math.max(0, fp.y * 100))
    return { objectPosition: `${x}% ${y}%` }
}

/** Same CSS mapping as {@link guidelinesFocalPointStyle} — use for grids, pickers, and any `object-fit: cover` preview. */
export const focalPointObjectPositionStyle = guidelinesFocalPointStyle

/**
 * Resolves a focal point for thumbnails: optional top-level {@code focal_point} (builder / API),
 * then guidelines-only override, then library/AI/manual {@code metadata.focal_point}
 * (matches PHP {@code GuidelinesFocalPoint::fromAsset} when metadata-only).
 *
 * @param {{ metadata?: Record<string, unknown>, focal_point?: { x?: number, y?: number } }|null|undefined} entity — asset or asset-shaped object
 */
export function effectiveFocalPointFromAsset(entity) {
    if (!entity || typeof entity !== 'object') return null
    const top = entity.focal_point
    if (top && typeof top === 'object' && typeof top.x === 'number' && typeof top.y === 'number') {
        return { x: top.x, y: top.y }
    }
    const m = entity.metadata
    if (!m || typeof m !== 'object') return null
    const g = m.guidelines_focal_point
    if (g && typeof g === 'object' && typeof g.x === 'number' && typeof g.y === 'number') {
        return { x: g.x, y: g.y }
    }
    const f = m.focal_point
    if (f && typeof f === 'object' && typeof f.x === 'number' && typeof f.y === 'number') {
        return { x: f.x, y: f.y }
    }
    return null
}

/**
 * Merge inline `style` with optional focal `objectPosition` (focal wins on overlap).
 * @param {Record<string, unknown>|undefined|null} base
 * @param {{ objectPosition?: string }|undefined|null} focalStyle
 */
export function mergeImageStyle(base, focalStyle) {
    if (!focalStyle?.objectPosition) return base || undefined
    if (!base || typeof base !== 'object') return { ...focalStyle }
    return { ...base, ...focalStyle }
}
