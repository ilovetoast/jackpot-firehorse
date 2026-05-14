import { Link, router, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import InsightsLayout from '../../layouts/InsightsLayout'
import StorageInsightPanel from '../../Components/insights/StorageInsightPanel'
import { isUnlimitedCount, isUnlimitedStorageMB } from '../../utils/planLimitDisplay'
import {
    AI_FEATURE_LABELS,
    formatAiCreditsSubtext,
    formatAiMonthlyCapAlertFeatures,
    isUnifiedAiCreditsPayload,
    sortedPerFeatureEntries,
} from '../../utils/aiCreditsUsageDisplay'
import {
    ServerIcon,
    CloudArrowDownIcon,
    SparklesIcon,
    ArrowRightIcon,
    ExclamationTriangleIcon,
    EyeIcon,
    ChartBarIcon,
    UserIcon,
    CalendarDaysIcon,
} from '@heroicons/react/24/outline'

const emptyEngagement = {
    totals: { views: 0, download_events: 0, download_packages: 0, uploads_finalized: 0 },
    top_assets: [],
    top_uploaders: [],
}

function InsightsTopAssetThumbnail({ url }) {
    const [failed, setFailed] = useState(false)
    if (!url || failed) {
        return <div className="h-10 w-10 shrink-0 rounded bg-gray-100" aria-hidden />
    }
    return (
        <img
            src={url}
            alt=""
            className="h-10 w-10 shrink-0 rounded bg-gray-100 object-cover"
            loading="lazy"
            onError={() => setFailed(true)}
        />
    )
}

export default function AnalyticsUsage({
    stats = {},
    ai_usage = null,
    ai_monthly_cap_alert = null,
    plan = {},
    asset_engagement = emptyEngagement,
    engagement_range = { preset: 'this_month', start_date: '', end_date: '', label: '' },
    storage_insight = null,
}) {
    const page = usePage()
    const range = page.props.engagement_range ?? engagement_range
    const engagement = page.props.asset_engagement ?? asset_engagement
    const storageInsight = page.props.storage_insight ?? storage_insight
    const [customStart, setCustomStart] = useState(range.start_date)
    const [customEnd, setCustomEnd] = useState(range.end_date)

    useEffect(() => {
        setCustomStart(range.start_date)
        setCustomEnd(range.end_date)
    }, [range.start_date, range.end_date])

    const applyPreset = (preset) => {
        router.get(
            route('insights.usage'),
            { range: preset },
            { preserveScroll: true, replace: true }
        )
    }

    const applyCustomRange = () => {
        if (!customStart || !customEnd) return
        router.get(
            route('insights.usage'),
            { range: 'custom', start_date: customStart, end_date: customEnd },
            { preserveScroll: true, replace: true }
        )
    }
    const formatStorage = (mb) => {
        if (!mb || mb === 0) return '0 MB'
        if (mb < 1) return `${(mb * 1024).toFixed(2)} KB`
        if (mb < 1024) return `${mb.toFixed(2)} MB`
        if (mb >= 1048576) return `${(mb / 1048576).toFixed(2)} TB`
        return `${(mb / 1024).toFixed(2)} GB`
    }

    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimitedStorageMB(limitMB)) return `${current} of Unlimited`
        return `${current} / ${formatStorage(limitMB)}`
    }

    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimitedCount(limit)) return `${(current ?? 0).toLocaleString()} of Unlimited`
        return `${(current ?? 0).toLocaleString()} / ${limit.toLocaleString()}`
    }

    const getStorageUsagePercent = (current, limit) => {
        if (!limit || isUnlimitedStorageMB(limit)) return 0
        return Math.min(100, Math.round(((current ?? 0) / limit) * 100))
    }

    const getDownloadUsagePercent = (current, limit) => {
        if (!limit || isUnlimitedCount(limit)) return 0
        return Math.min(100, Math.round(((current ?? 0) / limit) * 100))
    }

    return (
        <InsightsLayout title="Usage" activeSection="usage">
            <div className="space-y-8">
                {ai_monthly_cap_alert?.features?.length > 0 && (
                    <section
                        className="rounded-xl border border-amber-300 bg-amber-50 p-4 sm:p-5"
                        role="status"
                    >
                        <div className="flex gap-3">
                            <ExclamationTriangleIcon className="h-6 w-6 flex-shrink-0 text-amber-600" aria-hidden />
                            <div className="min-w-0">
                                <h2 className="text-base font-semibold text-amber-900">Monthly AI limit reached</h2>
                                <p className="mt-1 text-sm text-amber-950/90">
                                    Your{' '}
                                    <span className="font-medium">{formatAiMonthlyCapAlertFeatures(ai_monthly_cap_alert.features)}</span>{' '}
                                    has been exhausted for this billing period.
                                </p>
                                {ai_monthly_cap_alert.reset_hint && (
                                    <p className="mt-2 text-sm text-amber-900/80">{ai_monthly_cap_alert.reset_hint}</p>
                                )}
                            </div>
                        </div>
                    </section>
                )}
                <section aria-labelledby="asset-activity-heading">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-4">
                        <div>
                            <h2
                                id="asset-activity-heading"
                                className="text-sm font-semibold text-gray-700 uppercase tracking-wide flex items-center gap-2"
                            >
                                <ChartBarIcon className="h-5 w-5 text-gray-400" aria-hidden />
                                Asset activity
                            </h2>
                            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600">
                                <span>{range.label}</span>
                                {range.preset === 'custom' && (
                                    <span className="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-800">
                                        Custom range
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div>
                                <span className="sr-only">Reporting period</span>
                                <div className="inline-flex rounded-lg border border-gray-300 bg-white p-0.5 shadow-sm">
                                    {[
                                        { id: 'last_7', label: '7d' },
                                        { id: 'last_30', label: '30d' },
                                        { id: 'this_month', label: 'Month' },
                                    ].map((p) => (
                                        <button
                                            key={p.id}
                                            type="button"
                                            onClick={() => applyPreset(p.id)}
                                            className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                                range.preset === p.id
                                                    ? 'bg-violet-600 text-white shadow'
                                                    : 'text-gray-700 hover:bg-gray-50'
                                            }`}
                                        >
                                            {p.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="flex flex-wrap items-end gap-2">
                                <CalendarDaysIcon className="hidden h-5 w-5 text-gray-400 sm:block mb-2" aria-hidden />
                                <div>
                                    <label htmlFor="usage-custom-start" className="block text-xs font-medium text-gray-600">
                                        From
                                    </label>
                                    <input
                                        id="usage-custom-start"
                                        type="date"
                                        value={customStart}
                                        onChange={(e) => setCustomStart(e.target.value)}
                                        className="mt-0.5 rounded-md border border-gray-300 px-2 py-1.5 text-sm shadow-sm"
                                    />
                                </div>
                                <div>
                                    <label htmlFor="usage-custom-end" className="block text-xs font-medium text-gray-600">
                                        To
                                    </label>
                                    <input
                                        id="usage-custom-end"
                                        type="date"
                                        value={customEnd}
                                        onChange={(e) => setCustomEnd(e.target.value)}
                                        className="mt-0.5 rounded-md border border-gray-300 px-2 py-1.5 text-sm shadow-sm"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={applyCustomRange}
                                    className="rounded-md bg-white px-3 py-2 text-sm font-medium text-violet-700 ring-1 ring-inset ring-violet-200 hover:bg-violet-50"
                                >
                                    Apply range
                                </button>
                            </div>
                        </div>
                    </div>
                    <p className="mb-4 text-xs text-gray-500">
                        {
                            "Views and per-asset downloads are tracked when teammates use the library (in-app metrics). Share links counts ready download packages that include this brand's assets. Uploads counts completed uploads."
                        }
                    </p>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4 mb-6">
                        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div className="flex items-center gap-2 text-gray-500 text-xs font-medium uppercase tracking-wide">
                                <EyeIcon className="h-4 w-4" aria-hidden />
                                Views
                            </div>
                            <p className="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">
                                {(engagement.totals?.views ?? 0).toLocaleString()}
                            </p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div className="flex items-center gap-2 text-gray-500 text-xs font-medium uppercase tracking-wide">
                                <CloudArrowDownIcon className="h-4 w-4" aria-hidden />
                                Asset downloads
                            </div>
                            <p className="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">
                                {(engagement.totals?.download_events ?? 0).toLocaleString()}
                            </p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div className="flex items-center gap-2 text-gray-500 text-xs font-medium uppercase tracking-wide">
                                <CloudArrowDownIcon className="h-4 w-4" aria-hidden />
                                Share links created
                            </div>
                            <p className="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">
                                {(engagement.totals?.download_packages ?? 0).toLocaleString()}
                            </p>
                        </div>
                        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div className="flex items-center gap-2 text-gray-500 text-xs font-medium uppercase tracking-wide">
                                <UserIcon className="h-4 w-4" aria-hidden />
                                Uploads completed
                            </div>
                            <p className="mt-1 text-2xl font-semibold text-gray-900 tabular-nums">
                                {(engagement.totals?.uploads_finalized ?? 0).toLocaleString()}
                            </p>
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                            <div className="border-b border-gray-100 px-4 py-3">
                                <h3 className="text-sm font-semibold text-gray-900">Top assets</h3>
                                <p className="text-xs text-gray-500">By views + tracked downloads in this period</p>
                            </div>
                            {engagement.top_assets?.length ? (
                                <ul className="divide-y divide-gray-100">
                                    {engagement.top_assets.map((row) => (
                                        <li key={row.asset_id} className="flex items-center gap-3 px-4 py-3">
                                            <InsightsTopAssetThumbnail url={row.thumbnail_url} />
                                            <div className="min-w-0 flex-1">
                                                <Link
                                                    href={row.asset_url}
                                                    className="truncate font-medium text-violet-700 hover:text-violet-600 text-sm"
                                                >
                                                    {row.title}
                                                </Link>
                                                <div className="mt-0.5 flex gap-3 text-xs text-gray-500 tabular-nums">
                                                    <span>{row.views} views</span>
                                                    <span>{row.download_events} dl</span>
                                                </div>
                                            </div>
                                            <span className="text-sm font-semibold text-gray-900 tabular-nums">
                                                {row.engagement}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="px-4 py-8 text-center text-sm text-gray-500">
                                    No view or download activity in this range yet.
                                </p>
                            )}
                        </div>
                        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow">
                            <div className="border-b border-gray-100 px-4 py-3">
                                <h3 className="text-sm font-semibold text-gray-900">Most uploads</h3>
                                <p className="text-xs text-gray-500">Completed uploads in this period</p>
                            </div>
                            {engagement.top_uploaders?.length ? (
                                <ul className="divide-y divide-gray-100">
                                    {engagement.top_uploaders.map((row) => (
                                        <li
                                            key={row.user_id}
                                            className="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                                        >
                                            <span className="truncate font-medium text-gray-900">{row.name}</span>
                                            <span className="tabular-nums text-gray-700">{row.uploads}</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="px-4 py-8 text-center text-sm text-gray-500">
                                    No completed uploads in this range yet.
                                </p>
                            )}
                        </div>
                    </div>
                </section>
                <section>
                    <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                        Storage & Downloads
                    </h2>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                            <div className="flex items-center gap-4">
                                <div className="flex-shrink-0 rounded-lg bg-gray-100 p-3">
                                    <ServerIcon className="h-6 w-6 text-gray-600" aria-hidden="true" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <dt className="text-sm font-medium text-gray-500">Storage</dt>
                                    <dd className="mt-0.5 text-2xl font-semibold text-gray-900">
                                        {formatStorageWithLimit(stats.storage_mb ?? 0, stats.storage_limit_mb)}
                                    </dd>
                                    {stats.storage_limit_mb && !isUnlimitedStorageMB(stats.storage_limit_mb) && (
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className={`h-2 rounded-full transition-all ${
                                                    getStorageUsagePercent(stats.storage_mb, stats.storage_limit_mb) >= 90
                                                        ? 'bg-red-500'
                                                        : getStorageUsagePercent(stats.storage_mb, stats.storage_limit_mb) >= 75
                                                          ? 'bg-amber-500'
                                                          : 'bg-violet-600'
                                                }`}
                                                style={{
                                                    width: `${getStorageUsagePercent(stats.storage_mb, stats.storage_limit_mb)}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                            <div className="flex items-center gap-4">
                                <div className="flex-shrink-0 rounded-lg bg-gray-100 p-3">
                                    <CloudArrowDownIcon className="h-6 w-6 text-gray-600" aria-hidden="true" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <dt className="text-sm font-medium text-gray-500">Downloads (this month)</dt>
                                    <dd className="mt-0.5 text-2xl font-semibold text-gray-900">
                                        {formatDownloadsWithLimit(stats.downloads, stats.downloads_limit)}
                                    </dd>
                                    {stats.downloads_limit && !isUnlimitedCount(stats.downloads_limit) && (
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className={`h-2 rounded-full transition-all ${
                                                    getDownloadUsagePercent(stats.downloads, stats.downloads_limit) >= 90
                                                        ? 'bg-red-500'
                                                        : getDownloadUsagePercent(stats.downloads, stats.downloads_limit) >= 75
                                                          ? 'bg-amber-500'
                                                          : 'bg-violet-600'
                                                }`}
                                                style={{
                                                    width: `${getDownloadUsagePercent(stats.downloads, stats.downloads_limit)}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="mt-5">
                        <StorageInsightPanel storage_insight={storageInsight} formatStorage={formatStorage} />
                    </div>
                </section>

                {ai_usage && isUnifiedAiCreditsPayload(ai_usage) && (
                    <section>
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4 flex items-center">
                            <SparklesIcon className="h-5 w-5 mr-2 text-gray-400" />
                            AI credits
                        </h2>
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="mb-6">
                                <h3 className="text-sm font-medium text-gray-700">Monthly pool</h3>
                                <p className="mt-1 text-2xl font-semibold text-gray-900">
                                    {(ai_usage.credits_used ?? 0).toLocaleString()}
                                    {!ai_usage.is_unlimited && (
                                        <span className="text-lg font-normal text-gray-500">
                                            {' '}
                                            / {(ai_usage.credits_cap ?? 0).toLocaleString()}
                                        </span>
                                    )}
                                </p>
                                <p className="mt-1 text-sm text-gray-600">{formatAiCreditsSubtext(ai_usage)}</p>
                                {!ai_usage.is_unlimited && (ai_usage.credits_cap ?? 0) > 0 && (
                                    <div className="mt-3 w-full max-w-md bg-gray-200 rounded-full h-2">
                                        <div
                                            className={`h-2 rounded-full transition-all ${
                                                (ai_usage.warning_level ?? 0) >= 100
                                                    ? 'bg-red-500'
                                                    : (ai_usage.warning_level ?? 0) >= 90
                                                      ? 'bg-orange-500'
                                                      : (ai_usage.warning_level ?? 0) >= 80
                                                        ? 'bg-amber-500'
                                                        : 'bg-violet-600'
                                            }`}
                                            style={{
                                                width: `${Math.min(100, ai_usage.credits_percentage ?? 0)}%`,
                                            }}
                                        />
                                    </div>
                                )}
                            </div>
                            {sortedPerFeatureEntries(ai_usage.per_feature).length > 0 && (
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900 mb-3">Usage by feature</h3>
                                    <div className="overflow-x-auto rounded-lg border border-gray-200">
                                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className="px-4 py-2 text-left font-medium text-gray-600">Feature</th>
                                                    <th className="px-4 py-2 text-right font-medium text-gray-600">Calls</th>
                                                    <th className="px-4 py-2 text-right font-medium text-gray-600">Credits</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-100">
                                                {sortedPerFeatureEntries(ai_usage.per_feature).map((row) => (
                                                    <tr key={row.key}>
                                                        <td className="px-4 py-2 text-gray-900">
                                                            {AI_FEATURE_LABELS[row.key] ?? row.key}
                                                        </td>
                                                        <td className="px-4 py-2 text-right tabular-nums text-gray-700">
                                                            {(row.calls ?? 0).toLocaleString()}
                                                        </td>
                                                        <td className="px-4 py-2 text-right tabular-nums text-gray-900 font-medium">
                                                            {(row.credits_used ?? 0).toLocaleString()}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                            <div className="mt-4">
                                <Link
                                    href="/app/company"
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-violet-600 hover:text-violet-500"
                                >
                                    Manage plan & usage
                                    <ArrowRightIcon className="h-4 w-4" />
                                </Link>
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </InsightsLayout>
    )
}
