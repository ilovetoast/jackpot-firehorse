/**
 * Map BrandReadinessService task.action to an /app path (used after company+brand switch).
 */
export function resolveReadinessTaskPath(action, actions = {}) {
    const g = actions.guidelines_builder_path || ''
    const assets = actions.assets_path || '/app/assets'
    const refs = actions.reference_materials_path || assets

    const map = {
        guidelines_identity: g ? `${g}#identity` : assets,
        guidelines_typography: g ? `${g}#typography` : assets,
        guidelines_photography: g ? `${g}#visual` : assets,
        assets: assets,
        assets_upload: assets,
        references: refs,
        references_promote: refs,
    }

    return map[action] || assets
}

export function effortGlyph(effort) {
    if (effort === 'low') {
        return '⚡'
    }
    if (effort === 'high') {
        return '🔥'
    }
    return '⏱'
}
