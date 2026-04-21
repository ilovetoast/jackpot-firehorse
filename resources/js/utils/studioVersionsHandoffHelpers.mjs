/**
 * @param {string[]} compositionIds
 * @param {Array<{ composition_id: string, sort_order: number }>} variants
 */
export function sortCompositionIdsByVariantSortOrder(compositionIds, variants) {
    const orderById = new Map(variants.map((v) => [String(v.composition_id), v.sort_order]))
    const uniq = [...new Set(compositionIds.map((id) => String(id)))]
    return uniq.sort((a, b) => (orderById.get(a) ?? 9999) - (orderById.get(b) ?? 9999))
}

/**
 * Hero first (when present), then alternates in stable sort order.
 * @param {string | null | undefined} heroCompositionId
 * @param {string[]} alternateCompositionIds
 * @param {Array<{ composition_id: string, sort_order: number }>} variants
 */
export function mergeHeroAndAlternatesForExport(heroCompositionId, alternateCompositionIds, variants) {
    const hero = heroCompositionId != null && heroCompositionId !== '' ? String(heroCompositionId) : null
    const alts = [...new Set((alternateCompositionIds ?? []).map((id) => String(id)).filter((id) => id !== hero))]
    const combined = hero ? [hero, ...alts] : alts
    return sortCompositionIdsByVariantSortOrder(combined, variants)
}
