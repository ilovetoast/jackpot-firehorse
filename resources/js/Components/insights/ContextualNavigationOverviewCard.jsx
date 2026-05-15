import { Link } from '@inertiajs/react'
import { AdjustmentsHorizontalIcon, ArrowRightIcon } from '@heroicons/react/24/outline'
import {
    RECOMMENDATION_OVERVIEW_BLURB,
    recommendationLabel,
} from '../../utils/contextualNavigationRecommendations'

/**
 * Phase 6 — Overview surface for Contextual Navigation Intelligence.
 *
 * Renders nothing when feature is disabled or there are no pending
 * recommendations. Stays intentionally lightweight: 1 header + up to
 * 4 single-line "actionable summaries" → "View all" link to Insights/
 * Review filtered to the contextual tab.
 */

export default function ContextualNavigationOverviewCard({ summary }) {
    if (!summary || !summary.total_pending) return null
    const top = Array.isArray(summary.top) ? summary.top.slice(0, 4) : []
    const total = Number(summary.total_pending) || 0

    return (
        <section
            className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5"
            aria-labelledby="contextual-nav-overview-heading"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-2">
                    <AdjustmentsHorizontalIcon className="h-5 w-5 text-slate-500" aria-hidden />
                    <h2 id="contextual-nav-overview-heading" className="text-sm font-semibold text-slate-900">
                        Contextual navigation
                    </h2>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                        {total} pending
                    </span>
                </div>
                <Link
                    href="/app/insights/review?tab=contextual"
                    className="inline-flex items-center gap-1 text-xs font-medium text-slate-700 hover:text-slate-900"
                >
                    Review
                    <ArrowRightIcon className="h-3.5 w-3.5" aria-hidden />
                </Link>
            </div>
            {top.length > 0 && (
                <ul className="mt-3 space-y-2">
                    {top.map((item) => {
                        const blurb = recommendationLabel(
                            item.recommendation_type,
                            RECOMMENDATION_OVERVIEW_BLURB,
                        ) || 'Contextual recommendation'
                        return (
                            <li key={item.id} className="flex items-start gap-2 text-sm">
                                <span className="mt-0.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400" aria-hidden />
                                <div className="min-w-0">
                                    <span className="font-medium text-slate-800">{blurb}</span>
                                    {(item.field_label || item.folder_name) && (
                                        <span className="text-slate-500">
                                            {item.field_label ? ` · ${item.field_label}` : ''}
                                            {item.folder_name ? ` in ${item.folder_name}` : ''}
                                        </span>
                                    )}
                                    {item.reason_summary && (
                                        <p className="text-xs text-slate-500">{item.reason_summary}</p>
                                    )}
                                </div>
                            </li>
                        )
                    })}
                </ul>
            )}
        </section>
    )
}
