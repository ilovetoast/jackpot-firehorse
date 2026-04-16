import { Link } from '@inertiajs/react'
import InsightsLayout from '../../layouts/InsightsLayout'
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
                                                          : 'bg-indigo-600'
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
                                                          : 'bg-indigo-600'
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
                                                        : 'bg-indigo-600'
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
