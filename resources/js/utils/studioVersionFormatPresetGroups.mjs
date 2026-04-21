/**
 * Group curated Studio format presets for the Generate Versions modal.
 * @param {{
 *   preset_formats: Array<{ id: string; group?: string | null }>
 *   format_group_order?: string[] | null
 *   format_group_labels?: Record<string, string> | null
 * }} presets
 * @returns {{ group: string; heading: string; formats: typeof presets.preset_formats }[]}
 */
export function buildFormatPresetGroups(presets) {
    const formats = presets.preset_formats ?? []
    const order = presets.format_group_order?.length
        ? presets.format_group_order
        : ['social', 'marketplace', 'web', 'presentation', 'other']
    const labels = presets.format_group_labels ?? {}

    /** @type {Map<string, typeof formats>} */
    const map = new Map()
    for (const f of formats) {
        const g = (f.group && String(f.group).trim()) || 'other'
        if (!map.has(g)) {
            map.set(g, [])
        }
        map.get(g).push(f)
    }

    const seen = new Set()
    const out = []
    for (const g of order) {
        const list = map.get(g)
        if (list?.length) {
            seen.add(g)
            out.push({
                group: g,
                heading: labels[g] ?? humanizeGroupId(g),
                formats: list,
            })
        }
    }
    for (const [g, list] of map) {
        if (!seen.has(g) && list.length) {
            out.push({
                group: g,
                heading: labels[g] ?? humanizeGroupId(g),
                formats: list,
            })
        }
    }
    return out
}

function humanizeGroupId(id) {
    const s = String(id).replace(/_/g, ' ')
    return s.charAt(0).toUpperCase() + s.slice(1)
}

/**
 * Presets flagged `recommended: true` (quick picks at top of modal).
 * @param {Array<{ id: string; recommended?: boolean }>} formats
 */
export function recommendedPresetFormats(formats) {
    const rec = formats.filter((f) => f.recommended === true)
    if (rec.length > 0) {
        return rec
    }
    return []
}

/**
 * Recommended row first (no duplicate chips), then grouped sections for the Generate Versions modal.
 * @param {{
 *   preset_formats?: Array<Record<string, unknown>>
 *   format_group_order?: string[] | null
 *   format_group_labels?: Record<string, string> | null
 * }} presets
 */
export function formatSectionsForGenerateModal(presets) {
    const formats = presets.preset_formats ?? []
    const recommended = recommendedPresetFormats(formats)
    const recommendedIds = new Set(recommended.map((f) => f.id))
    const groups = buildFormatPresetGroups(presets)
        .map((section) => ({
            ...section,
            formats: section.formats.filter((f) => !recommendedIds.has(f.id)),
        }))
        .filter((s) => s.formats.length > 0)
    return { recommended, groups }
}
