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
