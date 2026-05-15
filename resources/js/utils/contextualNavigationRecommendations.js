// Phase 6 — single source of truth for Contextual Navigation
// recommendation_type metadata used across:
//
//   • Components/insights/ContextualNavigationReviewTab.jsx
//   • Components/insights/ContextualNavigationOverviewCard.jsx
//   • Components/Metadata/FolderSchemaHelp.jsx (inline manage hints)
//
// Backend defines the canonical type strings on
// `App\Models\ContextualNavigationRecommendation` (TYPE_* constants).
// Every type emitted by `ContextualNavigationRecommender::deriveRecommendationTypes`
// MUST appear here. Types defined in the model but never emitted (e.g.
// future warnings) are intentionally excluded so we don't ship dead UI.

/** Long-form label for the Insights → Review list (inside the type pill). */
export const RECOMMENDATION_REVIEW_LABEL = {
    suggest_quick_filter: 'Suggested quick filter',
    suggest_pin_quick_filter: 'Suggested pin',
    suggest_unpin_quick_filter: 'Suggested unpin',
    suggest_disable_quick_filter: 'Suggested disable',
    suggest_move_to_overflow: 'Move to overflow',
    warn_high_cardinality: 'High cardinality',
    warn_low_navigation_value: 'Low navigation value',
    warn_metadata_fragmentation: 'Metadata fragmentation',
    warn_low_coverage: 'Low coverage',
}

/** Short, action-oriented blurb for Overview "top recommendations" bullets. */
export const RECOMMENDATION_OVERVIEW_BLURB = {
    suggest_quick_filter: 'Suggested quick filter',
    suggest_pin_quick_filter: 'Strong pin candidate',
    suggest_unpin_quick_filter: 'Pinned filter underperforming',
    suggest_disable_quick_filter: 'Quick filter underperforming',
    suggest_move_to_overflow: 'Move to overflow',
    warn_high_cardinality: 'High-cardinality field',
    warn_low_navigation_value: 'Low narrowing power',
    warn_metadata_fragmentation: 'Metadata fragmentation',
    warn_low_coverage: 'Low coverage',
}

/** Compact chip label for inline Manage hints below a field's quick-filter row. */
export const RECOMMENDATION_HINT_LABEL = {
    suggest_quick_filter: 'Recommended quick filter',
    suggest_pin_quick_filter: 'Strong pin candidate',
    suggest_unpin_quick_filter: 'Unpin candidate',
    suggest_disable_quick_filter: 'Underperforming filter',
    suggest_move_to_overflow: 'Move to overflow',
    warn_high_cardinality: 'High cardinality',
    warn_low_navigation_value: 'Low narrowing power',
    warn_metadata_fragmentation: 'Fragmentation',
    warn_low_coverage: 'Low coverage',
}

/** Intent groups every type into one of three semantic buckets so consumers
 *  can derive their own palette (Tailwind classes, hex chips, plain text). */
export const RECOMMENDATION_INTENT = {
    suggest_quick_filter: 'positive',
    suggest_pin_quick_filter: 'positive',
    suggest_unpin_quick_filter: 'neutral',
    suggest_disable_quick_filter: 'neutral',
    suggest_move_to_overflow: 'neutral',
    warn_high_cardinality: 'warning',
    warn_low_navigation_value: 'warning',
    warn_metadata_fragmentation: 'warning',
    warn_low_coverage: 'warning',
}

/** Tailwind utility classes for the Insights/Review tab type-pill background. */
export function intentBadgeClasses(intent) {
    if (intent === 'positive') return 'bg-emerald-50 text-emerald-700 ring-emerald-200'
    if (intent === 'warning') return 'bg-amber-50 text-amber-800 ring-amber-200'
    return 'bg-slate-100 text-slate-700 ring-slate-200'
}

/** Hex tone used by the inline Manage chip (avoids Tailwind purge issues
 *  when the chip needs to render outside the Insights surface palette). */
export function intentChipTone(intent) {
    if (intent === 'positive') return { bg: '#ecfdf5', fg: '#065f46', border: '#a7f3d0' }
    if (intent === 'warning') return { bg: '#fffbeb', fg: '#92400e', border: '#fcd34d' }
    return { bg: '#f8fafc', fg: '#334155', border: '#e2e8f0' }
}

/** Convert 0..1 score → "NN%" string. Returns null when the input is null
 *  / undefined / NaN so callers can gate rendering instead of branching. */
export function formatRecommendationScore(score) {
    if (score == null) return null
    const numeric = Number(score)
    if (!Number.isFinite(numeric)) return null
    return `${Math.round(numeric * 100)}%`
}

/** Convenience: `RECOMMENDATION_REVIEW_LABEL[type]` with a graceful
 *  fallback that turns the raw enum string into something readable
 *  (`suggest_quick_filter` → `Suggest quick filter`). Used everywhere
 *  we want "show the type even if it's a new variant we forgot to map". */
export function recommendationLabel(type, source = RECOMMENDATION_REVIEW_LABEL) {
    if (!type) return ''
    return source[type] || prettifyType(type)
}

function prettifyType(type) {
    const cleaned = String(type).replace(/_/g, ' ').trim()
    return cleaned.charAt(0).toUpperCase() + cleaned.slice(1)
}
