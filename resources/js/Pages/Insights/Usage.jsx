import { Link } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
import {
    ServerIcon,
    CloudArrowDownIcon,
    SparklesIcon,
    ArrowRightIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'

export default function AnalyticsUsage({
    stats = {},
    ai_usage = null,
    ai_monthly_cap_alert = null,
    plan = {},
}) {
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

    const getUsagePercent = (current, limit) => {
        if (!limit || isUnlimited(limit)) return 0
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
                                    Monthly allowance for{' '}
                                    <span className="font-medium">
                                        {ai_monthly_cap_alert.features.map((f) => (f === 'tagging' ? 'AI tagging' : 'AI suggestions')).join(' and ')}
                                    </span>{' '}
                                    is exhausted for this billing period.
                                </p>
                                {ai_monthly_cap_alert.reset_hint && (
                                    <p className="mt-2 text-sm text-amber-900/80">{ai_monthly_cap_alert.reset_hint}</p>
                                )}
                            </div>
                        </div>
                    </section>
                )}
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
                                    {stats.storage_limit_mb && !isUnlimited(stats.storage_limit_mb) && (
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className={`h-2 rounded-full transition-all ${
                                                    getUsagePercent(stats.storage_mb, stats.storage_limit_mb) >= 90
                                                        ? 'bg-red-500'
                                                        : getUsagePercent(stats.storage_mb, stats.storage_limit_mb) >= 75
                                                          ? 'bg-amber-500'
                                                          : 'bg-indigo-600'
                                                }`}
                                                style={{
                                                    width: `${getUsagePercent(stats.storage_mb, stats.storage_limit_mb)}%`,
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
                                    {stats.downloads_limit && !isUnlimited(stats.downloads_limit) && (
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className={`h-2 rounded-full transition-all ${
                                                    getUsagePercent(stats.downloads, stats.downloads_limit) >= 90
                                                        ? 'bg-red-500'
                                                        : getUsagePercent(stats.downloads, stats.downloads_limit) >= 75
                                                          ? 'bg-amber-500'
                                                          : 'bg-indigo-600'
                                                }`}
                                                style={{
                                                    width: `${getUsagePercent(stats.downloads, stats.downloads_limit)}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                {ai_usage && (
                    <section>
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4 flex items-center">
                            <SparklesIcon className="h-5 w-5 mr-2 text-gray-400" />
                            AI Usage
                        </h2>
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                                <div>
                                    <h3 className="text-sm font-medium text-gray-700">Tagging</h3>
                                    <p className="mt-1 text-xl font-semibold text-gray-900">
                                        {ai_usage.tagging?.usage?.toLocaleString() ?? 0}
                                        {ai_usage.tagging?.is_unlimited
                                            ? ' (Unlimited)'
                                            : ai_usage.tagging?.cap
                                              ? ` / ${ai_usage.tagging.cap}`
                                              : ''}
                                    </p>
                                    {ai_usage.tagging?.cap && !ai_usage.tagging?.is_unlimited && (
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className="h-2 rounded-full bg-indigo-600 transition-all"
                                                style={{
                                                    width: `${Math.min(
                                                        100,
                                                        ((ai_usage.tagging?.usage ?? 0) / ai_usage.tagging.cap) * 100
                                                    )}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <h3 className="text-sm font-medium text-gray-700">Suggestions</h3>
                                    <p className="mt-1 text-xl font-semibold text-gray-900">
                                        {ai_usage.suggestions?.usage?.toLocaleString() ?? 0}
                                        {ai_usage.suggestions?.is_unlimited
                                            ? ' (Unlimited)'
                                            : ai_usage.suggestions?.cap
                                              ? ` / ${ai_usage.suggestions.cap}`
                                              : ''}
                                    </p>
                                    {ai_usage.suggestions?.cap && !ai_usage.suggestions?.is_unlimited && (
                                        <div className="mt-2 w-full bg-gray-200 rounded-full h-2">
                                            <div
                                                className="h-2 rounded-full bg-indigo-600 transition-all"
                                                style={{
                                                    width: `${Math.min(
                                                        100,
                                                        ((ai_usage.suggestions?.usage ?? 0) /
                                                            ai_usage.suggestions.cap) *
                                                            100
                                                    )}%`,
                                                }}
                                            />
                                        </div>
                                    )}
                                </div>
                            </div>
                            <div className="mt-4">
                                <Link
                                    href="/app/company"
                                    className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500"
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
