/**
 * Cartesian combination keys for Studio version generation (color × scene × format).
 * Mirrors backend {@link CreativeSetGenerationPlanner} segment format: c:id, s:id, f:id.
 */
export function combinationKeys(colorIds, sceneIds, formatIds) {
    const cList = colorIds.length > 0 ? colorIds : [null]
    const sList = sceneIds.length > 0 ? sceneIds : [null]
    const fList = formatIds.length > 0 ? formatIds : [null]
    const keys = []
    for (const c of cList) {
        for (const s of sList) {
            for (const f of fList) {
                if (c === null && s === null && f === null) {
                    continue
                }
                const parts = []
                if (c !== null) {
                    parts.push(`c:${c}`)
                }
                if (s !== null) {
                    parts.push(`s:${s}`)
                }
                if (f !== null) {
                    parts.push(`f:${f}`)
                }
                if (parts.length > 0) {
                    keys.push(parts.join('|'))
                }
            }
        }
    }
    return keys
}

/**
 * @param {string} key
 * @param {{ preset_colors: { id: string, label: string }[], preset_scenes: { id: string, label: string }[], preset_formats?: { id: string, label: string }[] }} presets
 */
export function labelForCombinationKey(key, presets) {
    const colorById = Object.fromEntries(presets.preset_colors.map((c) => [c.id, c.label]))
    const sceneById = Object.fromEntries(presets.preset_scenes.map((s) => [s.id, s.label]))
    const formatById = Object.fromEntries((presets.preset_formats ?? []).map((f) => [f.id, f.label]))
    const parts = []
    for (const part of key.split('|')) {
        const p = part.trim()
        if (p.startsWith('c:')) {
            const id = p.slice(2)
            parts.push(colorById[id] ?? id)
        }
        if (p.startsWith('s:')) {
            const id = p.slice(2)
            parts.push(sceneById[id] ?? id)
        }
        if (p.startsWith('f:')) {
            const id = p.slice(2)
            parts.push(formatById[id] ?? id)
        }
    }
    return parts.length ? parts.join(' · ') : key
}
