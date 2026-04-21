/** Safe single path segment for zip / download names. */
export function sanitizeExportSegment(raw, maxLen = 44) {
    const s = String(raw ?? '')
        .trim()
        .replace(/[^a-z0-9-_]+/gi, '_')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '')
    if (!s) {
        return 'untitled'
    }
    return s.length > maxLen ? s.slice(0, maxLen) : s
}

export function zeroPadSequence(index1Based, totalCount) {
    const w = Math.max(2, String(Math.max(1, totalCount)).length)
    return String(index1Based).padStart(w, '0')
}

/**
 * Zip entry name for one raster. Sequence reflects export order (hero first when reordered upstream).
 */
export function studioHandoffVersionRasterFilename(p) {
    const seq = zeroPadSequence(p.index1Based, p.totalCount)
    const lab = sanitizeExportSegment(p.label || `version-${p.index1Based}`, 36)
    const id = String(p.compositionId).replace(/[^a-z0-9]/gi, '')
    const tail = id.length >= 6 ? id.slice(-6) : id || 'id'
    const heroMatch =
        p.heroCompositionId != null &&
        p.heroCompositionId !== '' &&
        String(p.compositionId) === String(p.heroCompositionId)
    const role = heroMatch ? 'HERO' : 'alt'
    return `${seq}_${role}_${lab}_${tail}.${p.ext}`
}

/**
 * Top-level bundle download name.
 */
export function studioHandoffBundleZipFilename(p) {
    const setPart = sanitizeExportSegment(p.setName || 'versions-set', 32)
    const idPart = sanitizeExportSegment(String(p.setId ?? 'set').replace(/[^a-z0-9-_]+/gi, '_'), 16)
    const kind = p.rasterKind === 'jpeg' ? 'jpg' : 'png'
    const stamp = sanitizeExportSegment(p.stamp, 24)
    return `Studio-Versions_${setPart}_${idPart}_${kind}_${stamp}.zip`
}

/**
 * When hero is in the set, move it to the front; preserve relative order of other ids.
 * @param {string[]} sortedIds already in deterministic order (e.g. variant sort_order)
 * @param {string | null | undefined} heroCompositionId
 */
export function orderExportCompositionIdsHeroFirst(sortedIds, heroCompositionId) {
    if (heroCompositionId == null || heroCompositionId === '') {
        return [...sortedIds]
    }
    const hero = String(heroCompositionId)
    const asStrings = sortedIds.map((id) => String(id))
    if (!asStrings.includes(hero)) {
        return [...sortedIds]
    }
    const heroEntry = sortedIds.find((id) => String(id) === hero)
    const rest = sortedIds.filter((id) => String(id) !== hero)
    return heroEntry != null ? [heroEntry, ...rest] : [...sortedIds]
}
