/** Quick range presets for AI cost reports (must match AICostReportingService::RANGE_PRESETS). */
export const REPORT_RANGE_PRESETS = [
    { id: '24h', label: '24 hours' },
    { id: '7d', label: '7 days' },
    { id: '14d', label: '2 weeks' },
    { id: '30d', label: '30 days' },
    { id: '90d', label: '3 months' },
    { id: '6m', label: '6 months' },
    { id: '12m', label: '12 months' },
]

/**
 * Merge filter patch for AI reports: presets clear calendar bounds; custom dates clear preset.
 * @param {Record<string, string|undefined|null>} prev
 * @param {Record<string, string|undefined|null>} patch
 */
export function mergeAiReportFilters(prev, patch) {
    const m = { ...prev, ...patch }
    if (patch.range_preset != null && patch.range_preset !== '') {
        delete m.start_date
        delete m.end_date
    }
    if ('start_date' in patch || 'end_date' in patch) {
        delete m.range_preset
    }
    return m
}

/** Drop empty values for Inertia GET query params. */
export function serializeAiReportFilters(f) {
    const out = {}
    if (!f) return out
    for (const [k, v] of Object.entries(f)) {
        if (v === '' || v === null || v === undefined) continue
        out[k] = v
    }
    return out
}

/**
 * @param {{ range_preset?: string|null, range_start?: string, range_end?: string }|null|undefined} meta
 * @param {Record<string, string|undefined>} filters fallback when meta missing
 */
export function formatAiReportRangeSubtitle(meta, filters) {
    if (meta?.range_start && meta?.range_end) {
        const a = new Date(meta.range_start)
        const b = new Date(meta.range_end)
        const opts = { month: 'short', day: 'numeric', year: 'numeric' }
        const preset = REPORT_RANGE_PRESETS.find((p) => p.id === meta.range_preset)
        const rangeBit = `${a.toLocaleString(undefined, { ...opts, hour: undefined, minute: undefined })} – ${b.toLocaleString(undefined, { ...opts, hour: undefined, minute: undefined })}`
        if (meta.range_preset === '24h') {
            return `${preset?.label ?? '24 hours'} (${a.toLocaleString()} – ${b.toLocaleString()})`
        }
        if (preset) {
            return `${preset.label} · ${rangeBit}`
        }
        return rangeBit
    }
    if (filters?.start_date && filters?.end_date) {
        return `${filters.start_date} – ${filters.end_date}`
    }
    return null
}
