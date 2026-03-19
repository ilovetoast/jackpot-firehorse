import { useState, useEffect } from 'react'
import { Link } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import PendingAiSuggestionsModal from '../../Components/PendingAiSuggestionsModal'
import {
    FolderIcon,
    ServerIcon,
    CloudArrowDownIcon,
    RectangleGroupIcon,
    BoltIcon,
    SparklesIcon,
    ChartBarIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ShieldCheckIcon,
    ArrowRightIcon,
} from '@heroicons/react/24/outline'

export default function AnalyticsOverview({
    stats = {},
    ai_usage = null,
    metadata_overview = {},
    metadata_coverage = {},
    ai_effectiveness = {},
    rights_risk = {},
    plan = {},
}) {
    const [suggestionsModalOpen, setSuggestionsModalOpen] = useState(false)

    // Deep link: open suggestions modal when ?open=suggestions in URL
    useEffect(() => {
        try {
            const params = new URLSearchParams(window.location.search)
            if (params.get('open') === 'suggestions') {
                setSuggestionsModalOpen(true)
                // Remove open=suggestions from URL without reload
                params.delete('open')
                const cleanSearch = params.toString()
                const cleanUrl = window.location.pathname + (cleanSearch ? `?${cleanSearch}` : '')
                window.history.replaceState({}, '', cleanUrl)
            }
        } catch {
            // ignore
        }
    }, [])
    const formatStorage = (mb) => {
        if (!mb || mb === 0) return '0 MB'
        if (mb < 1) return `${(mb * 1024).toFixed(2)} KB`
        if (mb < 1024) return `${mb.toFixed(2)} MB`
        return `${(mb / 1024).toFixed(2)} GB`
    }

    const isUnlimited = (limit) => !limit || limit >= 999999 || limit === Number.MAX_SAFE_INTEGER

    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimited(limitMB)) return `${current} of Unlimited`
        return `${current} / ${formatStorage(limitMB)}`
    }

    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimited(limit)) return `${(current ?? 0).toLocaleString()} of Unlimited`
        return `${(current ?? 0).toLocaleString()} / ${limit.toLocaleString()}`
    }

    const StatCard = ({ icon: Icon, title, value, subtext, formatValue = (v) => v, href }) => {
        const content = (
            <div className="flex items-center gap-4">
                <div className="flex-shrink-0 rounded-lg bg-gray-100 p-3">
                    <Icon className="h-6 w-6 text-gray-600" aria-hidden="true" />
                </div>
                <div className="min-w-0 flex-1">
                    <dt className="text-sm font-medium text-gray-500 truncate">{title}</dt>
                    <dd className="mt-0.5">
                        <span className="text-2xl font-semibold tracking-tight text-gray-900">
                            {formatValue(value)}
                        </span>
                    </dd>
                    {subtext && <p className="mt-0.5 text-xs text-gray-500">{subtext}</p>}
                </div>
                {href && (
                    <ArrowRightIcon className="h-5 w-5 text-gray-400 flex-shrink-0" aria-hidden="true" />
                )}
            </div>
        )

        const cardClass =
            'overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200 transition-all duration-200 hover:shadow-md hover:scale-[1.02]'

        if (href) {
            return (
                <Link href={href} className={`block ${cardClass}`}>
                    {content}
                </Link>
            )
        }
        return <div className={cardClass}>{content}</div>
    }

    const overview = metadata_overview
    const coverage = metadata_coverage
    const lowestCoverage = coverage?.lowest_coverage_fields?.slice(0, 5) ?? []

    return (
        <InsightsLayout title="Insights Overview" activeSection="overview">
            <div className="space-y-8 animate-fadeInUp-d1">
                {/* Top metric cards */}
                <section>
                    <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                        Brand Totals
                    </h2>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        <StatCard
                            icon={FolderIcon}
                            title="Total Assets"
                            value={stats.total_assets ?? 0}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        <StatCard
                            icon={ServerIcon}
                            title="Storage"
                            value={stats.storage_mb ?? 0}
                            subtext={
                                stats.storage_limit_mb
                                    ? formatStorageWithLimit(stats.storage_mb, stats.storage_limit_mb)
                                    : formatStorage(stats.storage_mb ?? 0) + ' used'
                            }
                            formatValue={formatStorage}
                        />
                        <StatCard
                            icon={CloudArrowDownIcon}
                            title="Downloads (this month)"
                            value={stats.downloads ?? 0}
                            subtext={
                                stats.downloads_limit
                                    ? formatDownloadsWithLimit(stats.downloads, stats.downloads_limit)
                                    : null
                            }
                            formatValue={(v) => v.toLocaleString()}
                        />
                        <StatCard
                            icon={RectangleGroupIcon}
                            title="Collections"
                            value={stats.collections ?? 0}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        <StatCard
                            icon={BoltIcon}
                            title="Executions"
                            value={stats.executions ?? 0}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        {ai_usage && (
                            <StatCard
                                icon={SparklesIcon}
                                title="AI Usage"
                                value={
                                    ai_usage.tagging?.usage ?? 0
                                }
                                subtext={
                                    ai_usage.tagging?.is_unlimited
                                        ? 'Unlimited'
                                        : ai_usage.tagging?.cap
                                          ? `${ai_usage.tagging.usage ?? 0} / ${ai_usage.tagging.cap} tagging`
                                          : null
                                }
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                    </div>
                </section>

                {/* Metadata Health Summary */}
                <section>
                    <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4 flex items-center">
                        <ChartBarIcon className="h-5 w-5 mr-2 text-gray-400" />
                        Metadata Health Summary
                    </h2>
                    <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <p className="text-sm font-medium text-gray-500">Completeness</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {overview.completeness_percentage?.toFixed(1) ?? '0'}%
                                </p>
                                <p className="mt-0.5 text-xs text-gray-500">
                                    {overview.assets_with_metadata?.toLocaleString() ?? 0} of{' '}
                                    {overview.total_assets?.toLocaleString() ?? 0} assets
                                </p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-500">Avg Metadata per Asset</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {overview.avg_metadata_per_asset?.toFixed(1) ?? '0'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm font-medium text-gray-500">Total Metadata Values</p>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {overview.total_metadata_values?.toLocaleString() ?? '0'}
                                </p>
                            </div>
                            <div>
                                <Link
                                    href="/app/insights/metadata"
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                >
                                    View full metadata insights
                                    <ArrowRightIcon className="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                        {lowestCoverage.length > 0 && (
                            <div className="mt-6 pt-6 border-t border-gray-200">
                                <h3 className="text-sm font-semibold text-gray-900 mb-3">
                                    Fields with Lowest Coverage
                                </h3>
                                <div className="space-y-2">
                                    {lowestCoverage.map((field, idx) => (
                                        <div
                                            key={field.field_key ?? idx}
                                            className="flex items-center justify-between text-sm"
                                        >
                                            <span className="text-gray-700">{field.field_label}</span>
                                            <span className="text-gray-500 tabular-nums">
                                                {field.coverage_percentage}%
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </section>

                {/* AI Suggestion Effectiveness (preview) */}
                {(ai_effectiveness?.total_suggestions > 0 || ai_effectiveness?.approved_suggestions > 0) && (
                    <section>
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4 flex items-center">
                            <SparklesIcon className="h-5 w-5 mr-2 text-gray-400" />
                            AI Suggestion Effectiveness
                        </h2>
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Total Suggestions</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        {ai_effectiveness.total_suggestions?.toLocaleString() ?? 0}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Approved</p>
                                    <p className="mt-1 text-2xl font-semibold text-green-600">
                                        {ai_effectiveness.approved_suggestions?.toLocaleString() ?? 0}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Acceptance Rate</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        {ai_effectiveness.acceptance_rate?.toFixed(1) ?? 0}%
                                    </p>
                                </div>
                                <div>
                                    <Link
                                        href="/app/insights/metadata"
                                        className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                    >
                                        View details
                                        <ArrowRightIcon className="h-4 w-4" />
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </section>
                )}

                {/* Rights & Risk Indicators */}
                {(rights_risk?.expired_count > 0 ||
                    rights_risk?.expiring_30_days > 0 ||
                    rights_risk?.expiring_60_days > 0 ||
                    rights_risk?.expiring_90_days > 0) && (
                    <section>
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4 flex items-center">
                            <ShieldCheckIcon className="h-5 w-5 mr-2 text-gray-400" />
                            Rights & Risk Indicators
                        </h2>
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                                {rights_risk.expired_count > 0 && (
                                    <div className="flex items-center gap-3">
                                        <ExclamationTriangleIcon className="h-8 w-8 text-red-500 flex-shrink-0" />
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Expired</p>
                                            <p className="text-xl font-semibold text-red-600">
                                                {rights_risk.expired_count} assets
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {rights_risk.expiring_30_days > 0 && (
                                    <div className="flex items-center gap-3">
                                        <ExclamationTriangleIcon className="h-8 w-8 text-amber-500 flex-shrink-0" />
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Expiring in 30 days</p>
                                            <p className="text-xl font-semibold text-amber-600">
                                                {rights_risk.expiring_30_days} assets
                                            </p>
                                        </div>
                                    </div>
                                )}
                                {rights_risk.expiring_60_days > 0 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-500">Expiring in 60 days</p>
                                        <p className="text-xl font-semibold text-gray-900">
                                            {rights_risk.expiring_60_days} assets
                                        </p>
                                    </div>
                                )}
                                {rights_risk.expiring_90_days > 0 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-500">Expiring in 90 days</p>
                                        <p className="text-xl font-semibold text-gray-900">
                                            {rights_risk.expiring_90_days} assets
                                        </p>
                                    </div>
                                )}
                            </div>
                            <div className="mt-4">
                                <Link
                                    href="/app/insights/metadata"
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500"
                                >
                                    View rights details
                                    <ArrowRightIcon className="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    </section>
                )}
            </div>

            {/* AI suggestions review modal — opened via ?open=suggestions deep link */}
            {suggestionsModalOpen && (
                <PendingAiSuggestionsModal
                    isOpen={suggestionsModalOpen}
                    onClose={() => setSuggestionsModalOpen(false)}
                />
            )}
        </InsightsLayout>
    )
}
