import { Link } from '@inertiajs/react'
import {
    ArrowDownTrayIcon,
    ChartBarIcon,
    CloudArrowUpIcon,
    ShieldCheckIcon,
    StarIcon,
    UserGroupIcon,
} from '@heroicons/react/24/outline'

function KpiCard({ icon: Icon, title, value, subtext, formatValue = (v) => v }) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white px-4 py-5 shadow sm:p-6">
            <div className="flex items-center gap-4">
                <div className="flex-shrink-0 rounded-lg bg-gray-100 p-3">
                    <Icon className="h-6 w-6 text-gray-600" aria-hidden="true" />
                </div>
                <div className="min-w-0 flex-1">
                    <dt className="truncate text-sm font-medium text-gray-500">{title}</dt>
                    <dd className="mt-0.5">
                        <span className="text-2xl font-semibold tracking-tight text-gray-900">
                            {formatValue(value)}
                        </span>
                    </dd>
                    {subtext ? <p className="mt-0.5 text-xs text-gray-500">{subtext}</p> : null}
                </div>
            </div>
        </div>
    )
}

function HighlightCard({ emoji, title, children }) {
    return (
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white px-4 py-5 shadow sm:p-6">
            <h3 className="text-sm font-semibold text-gray-900">
                <span className="mr-1.5" aria-hidden>
                    {emoji}
                </span>
                {title}
            </h3>
            <div className="mt-3 text-sm text-gray-700">{children}</div>
        </div>
    )
}

function assetViewHref(assetId) {
    if (!assetId) return '/app/assets'
    return typeof route === 'function' ? route('assets.view', { asset: assetId }) : `/app/assets/${assetId}/view`
}

/**
 * @param {{ insights: Record<string, unknown> }} props
 */
export default function CreatorInsights({ insights }) {
    if (!insights?.has_activity) {
        return (
            <section className="animate-fadeInUp-d1" aria-labelledby="insights-creator-heading">
                <h2
                    id="insights-creator-heading"
                    className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500"
                >
                    <UserGroupIcon className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
                    Creator performance
                </h2>
                <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-5 py-8 text-center sm:px-6">
                    <p className="text-sm font-medium text-slate-600">No creator activity yet</p>
                    <p className="mt-1 text-xs text-slate-500">
                        Creator uploads and targets will appear here once the program is in use.
                    </p>
                </div>
            </section>
        )
    }

    const approvalPct = Math.round((Number(insights.approval_rate) || 0) * 1000) / 10
    const avgRating = insights.avg_rating
    const top = insights.top_creator
    const mostActive = insights.most_active_creator
    const mostDl = insights.most_downloaded_asset
    const topRated = insights.highest_rated_asset

    return (
        <section className="animate-fadeInUp-d1" aria-labelledby="insights-creator-heading">
            <h2
                id="insights-creator-heading"
                className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-500"
            >
                <UserGroupIcon className="h-4 w-4 shrink-0 text-slate-400" aria-hidden />
                Creator performance
            </h2>

            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <KpiCard
                    icon={CloudArrowUpIcon}
                    title="Total Creator Uploads"
                    value={insights.total_uploads ?? 0}
                    formatValue={(v) => Number(v).toLocaleString()}
                />
                <KpiCard
                    icon={ShieldCheckIcon}
                    title="Approval Rate"
                    value={approvalPct}
                    subtext="Approved ÷ (approved + rejected)"
                    formatValue={(v) => `${v}%`}
                />
                <KpiCard
                    icon={ArrowDownTrayIcon}
                    title="Avg Downloads / Asset"
                    value={insights.avg_downloads_per_asset ?? 0}
                    formatValue={(v) => Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 })}
                />
                <KpiCard
                    icon={StarIcon}
                    title="Avg Rating"
                    value={avgRating != null ? avgRating : '—'}
                    subtext="Brand Intelligence score (where available)"
                    formatValue={(v) => (v === '—' ? '—' : Number(v).toFixed(1))}
                />
            </div>

            <div className="mt-6 grid grid-cols-1 gap-5 md:grid-cols-2">
                <HighlightCard emoji="🏆" title="Top Creator">
                    {top ? (
                        <p>
                            <span className="font-semibold text-gray-900">{top.name}</span>
                            <span className="text-gray-500"> — </span>
                            <span className="tabular-nums text-gray-800">
                                {Number(top.completion_percentage).toFixed(1)}% completion
                            </span>
                        </p>
                    ) : (
                        <p className="text-gray-500">No completion data yet.</p>
                    )}
                </HighlightCard>

                <HighlightCard emoji="📈" title="Most Downloaded Asset">
                    {mostDl ? (
                        <div>
                            <Link
                                href={assetViewHref(mostDl.asset_id)}
                                className="font-semibold text-violet-600 hover:text-violet-500"
                            >
                                {mostDl.title || 'Untitled'}
                            </Link>
                            <p className="mt-1 text-xs text-gray-500">
                                {Number(mostDl.download_count).toLocaleString()} downloads
                            </p>
                        </div>
                    ) : (
                        <p className="text-gray-500">No download events for creator uploads yet.</p>
                    )}
                </HighlightCard>

                <HighlightCard emoji="⭐" title="Highest Rated Asset">
                    {topRated ? (
                        <div>
                            <Link
                                href={assetViewHref(topRated.asset_id)}
                                className="font-semibold text-violet-600 hover:text-violet-500"
                            >
                                {topRated.title || 'Untitled'}
                            </Link>
                            <p className="mt-1 text-xs text-gray-500">
                                Score {Number(topRated.rating).toFixed(1)}
                            </p>
                        </div>
                    ) : (
                        <p className="text-gray-500">No Brand Intelligence scores for creator uploads yet.</p>
                    )}
                </HighlightCard>

                <HighlightCard emoji="🚀" title="Most Active Creator">
                    {mostActive ? (
                        <p>
                            <span className="font-semibold text-gray-900">{mostActive.name}</span>
                            <span className="text-gray-500"> — </span>
                            <span className="tabular-nums text-gray-800">
                                {Number(mostActive.upload_count).toLocaleString()} uploads
                            </span>
                        </p>
                    ) : (
                        <p className="text-gray-500">No uploads yet.</p>
                    )}
                </HighlightCard>
            </div>

            <div className="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white px-4 py-5 shadow sm:p-6">
                    <div className="flex items-center gap-4">
                        <div className="flex-shrink-0 rounded-lg bg-amber-50 p-3">
                            <UserGroupIcon className="h-6 w-6 text-amber-700" aria-hidden="true" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-gray-500">Creators Behind</p>
                            <p className="mt-0.5 text-2xl font-semibold text-gray-900">
                                {Number(insights.creators_behind ?? 0).toLocaleString()}
                            </p>
                            <p className="mt-0.5 text-xs text-gray-500">Below 50% of upload target (current period)</p>
                        </div>
                    </div>
                </div>
                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white px-4 py-5 shadow sm:p-6">
                    <div className="flex items-center gap-4">
                        <div className="flex-shrink-0 rounded-lg bg-violet-50 p-3 ring-1 ring-violet-100/80">
                            <ChartBarIcon className="h-6 w-6 text-violet-700" aria-hidden="true" />
                        </div>
                        <div>
                            <p className="text-sm font-medium text-gray-500">On Track %</p>
                            <p className="mt-0.5 text-2xl font-semibold text-gray-900">
                                {Number(insights.creators_on_track_percentage ?? 0).toFixed(1)}%
                            </p>
                            <p className="mt-0.5 text-xs text-gray-500">Creators at 50%+ or complete vs target</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    )
}
