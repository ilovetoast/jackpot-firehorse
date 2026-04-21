/**
 * “Next new version” navigation for Studio Versions rail.
 * @param {Array<{ composition_id: string, sort_order: number }>} sortedVariants
 * @param {string | null} currentCompositionId
 * @param {string[]} newcomerCompositionIds
 * @param {Set<string> | string[]} viewedNewcomerCompositionIds
 */
export function nextNewCompositionId(
    sortedVariants,
    currentCompositionId,
    newcomerCompositionIds,
    viewedNewcomerCompositionIds,
) {
    const viewed =
        viewedNewcomerCompositionIds instanceof Set
            ? viewedNewcomerCompositionIds
            : new Set(viewedNewcomerCompositionIds)
    const pool = newcomerCompositionIds.filter((id) => !viewed.has(id))
    const usePool = pool.length > 0 ? pool : newcomerCompositionIds
    if (usePool.length === 0) {
        return null
    }
    const useSet = new Set(usePool)
    const ordered = sortedVariants.filter((v) => useSet.has(v.composition_id))
    if (ordered.length === 0) {
        return usePool[0] ?? null
    }
    if (!currentCompositionId) {
        return ordered[0].composition_id
    }
    const idx = ordered.findIndex((v) => v.composition_id === currentCompositionId)
    if (idx === -1) {
        return ordered[0].composition_id
    }
    return ordered[(idx + 1) % ordered.length].composition_id
}

/**
 * First tile to scroll into view (prefer first unviewed newcomer in sort order).
 */
export function firstScrollTargetCompositionId(sortedVariants, newcomerCompositionIds, viewedNewcomerCompositionIds) {
    const viewed =
        viewedNewcomerCompositionIds instanceof Set
            ? viewedNewcomerCompositionIds
            : new Set(viewedNewcomerCompositionIds)
    const unviewed = newcomerCompositionIds.filter((id) => !viewed.has(id))
    const pool = unviewed.length > 0 ? unviewed : newcomerCompositionIds
    if (pool.length === 0) {
        return null
    }
    const set = new Set(pool)
    const hit = sortedVariants.find((v) => set.has(v.composition_id))
    return hit?.composition_id ?? pool[0]
}
