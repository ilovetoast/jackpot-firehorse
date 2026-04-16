/**
 * Helpers for dashboards that receive AiUsageService::getUsageStatus()
 * merged with augmentAiUsageDashboardPayload() (thumbnail_enhancement, video_ai).
 */

export function isUnifiedAiCreditsPayload(aiUsage) {
    return aiUsage != null && typeof aiUsage.credits_used === 'number'
}

export const AI_FEATURE_LABELS = {
    tagging: 'Auto-tagging',
    suggestions: 'Metadata suggestions',
    brand_research: 'Brand research',
    insights: 'Insights',
    generative_editor_images: 'Generative images',
    generative_editor_edits: 'Generative edits',
    video_insights: 'Video insights',
    pdf_extraction: 'PDF extraction',
    presentation_preview: 'Presentation preview (drawer AI)',
}

export function sortedPerFeatureEntries(perFeature) {
    if (!perFeature || typeof perFeature !== 'object') return []
    return Object.entries(perFeature)
        .map(([key, row]) => ({ key, ...row }))
        .filter((r) => (r.calls ?? 0) > 0 || (r.credits_used ?? 0) > 0)
        .sort((a, b) => (b.credits_used ?? 0) - (a.credits_used ?? 0))
}

export function formatAiCreditsSubtext(aiUsage) {
    if (!isUnifiedAiCreditsPayload(aiUsage)) return ''
    const used = aiUsage.credits_used ?? 0
    if (aiUsage.is_unlimited) {
        return `${used.toLocaleString()} credits used · unlimited plan`
    }
    const cap = aiUsage.credits_cap ?? 0
    const wl = aiUsage.warning_level ?? 0
    let hint = ''
    if (wl >= 100) hint = ' · at monthly limit'
    else if (wl >= 90) hint = ' · 90%+ of pool'
    else if (wl >= 80) hint = ' · 80%+ of pool'
    return `${used.toLocaleString()} / ${cap.toLocaleString()} credits this month${hint}`
}

/**
 * Studio “enhanced” thumbnail jobs: local compositing only (no provider API) — operational counts only.
 * Provider-backed presentation previews debit credits under “Presentation preview” in the pool above.
 */
export function formatThumbnailEnhancementSubtext(metrics) {
    if (!metrics) return ''
    const count = metrics.count ?? 0
    const detail =
        count === 0
            ? 'No completed runs this month'
            : `${metrics.success_rate ?? '—'}% success · avg ${metrics.avg_duration_ms != null ? `${Math.round(metrics.avg_duration_ms)} ms` : '—'}` +
              (metrics.p95_duration_ms != null ? ` · p95 ${Math.round(metrics.p95_duration_ms)} ms` : '') +
              ((metrics.skipped_count ?? 0) > 0 ? ` · ${metrics.skipped_count} skipped` : '')
    return `Studio compositing (no API credits) · ${detail}`
}

export function formatAiMonthlyCapAlertFeatures(features) {
    if (!features?.length) return ''
    if (features.includes('credits')) {
        return 'monthly AI credit pool'
    }
    return features
        .map((f) => {
            if (f === 'tagging') return 'AI tagging'
            if (f === 'suggestions') return 'AI suggestions'
            return f
        })
        .join(' and ')
}
