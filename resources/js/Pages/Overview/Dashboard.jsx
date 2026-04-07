import { usePage, Link } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import BrandAvatar from '../../Components/BrandAvatar'
import ActivityActorAvatar from '../../Components/ActivityActorAvatar'
import { isUnlimitedCount, isUnlimitedStorageMB } from '../../utils/planLimitDisplay'
import PendingAiSuggestionsTile from '../../Components/PendingAiSuggestionsTile'
import PendingMetadataTile from '../../Components/PendingMetadataTile'
import {
    FolderIcon,
    CloudArrowDownIcon,
    ServerIcon,
    ArrowUpIcon,
    ArrowDownIcon,
    SparklesIcon,
    RectangleGroupIcon,
    BoltIcon,
    DocumentCheckIcon,
    ClipboardDocumentCheckIcon,
    EyeIcon,
    ArrowTrendingUpIcon,
    ArrowLeftIcon,
} from '@heroicons/react/24/outline'

export default function Dashboard({
    auth,
    tenant,
    brand,
    plan,
    stats = null,
    is_manager = false,
    ai_usage = null,
    pending_ai_suggestions = null,
    pending_metadata_approvals_count = 0,
    unpublished_assets_count = 0,
    pending_assets_count = 0,
    most_viewed_assets = [],
    most_downloaded_assets = [],
    most_trending_assets = [],
    recent_activity = null,
    widget_visibility = {},
}) {
    const { auth: authFromPage } = usePage().props
    const activeBrand = brand ?? authFromPage?.activeBrand ?? auth?.activeBrand

    const formatStorage = (mb) => {
        if (!mb || mb === 0) return '0 MB'
        if (mb < 1) return `${(mb * 1024).toFixed(2)} KB`
        if (mb < 1024) return `${mb.toFixed(2)} MB`
        return `${(mb / 1024).toFixed(2)} GB`
    }

    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimitedStorageMB(limitMB)) return `${current} of Unlimited`
        return `${current} / ${formatStorage(limitMB)}`
    }

    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimitedCount(limit)) return `${current.toLocaleString()} of Unlimited`
        return `${current.toLocaleString()} / ${limit.toLocaleString()}`
    }

    const formatChange = (change) => {
        const isPositive = change >= 0
        return (
            <span className={`text-sm font-medium ${isPositive ? 'text-green-600' : 'text-red-600'}`}>
                {change >= 0 ? '+' : ''}{change.toFixed(2)}%
            </span>
        )
    }

    const totalPendingSuggestions = pending_ai_suggestions?.total ?? 0

    const StatCard = ({ icon: Icon, title, value, change, subtext, formatValue = (v) => v }) => (
        <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
            <div className="flex items-center">
                <div className="flex-shrink-0">
                    <Icon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                </div>
                <div className="ml-5 w-0 flex-1">
                    <dt className="text-sm font-medium text-gray-500 truncate">{title}</dt>
                    <dd className="mt-1 flex items-baseline">
                        <span className="text-2xl font-semibold tracking-tight text-gray-900">
                            {formatValue(value)}
                        </span>
                        {change !== undefined && change !== 0 && (
                            <span className="ml-2 flex items-baseline text-sm font-semibold">
                                {change >= 0 ? (
                                    <ArrowUpIcon className="h-4 w-4 text-green-500 mr-0.5" aria-hidden="true" />
                                ) : (
                                    <ArrowDownIcon className="h-4 w-4 text-red-500 mr-0.5" aria-hidden="true" />
                                )}
                                {formatChange(change)}
                            </span>
                        )}
                    </dd>
                    {subtext && <p className="mt-1 text-xs text-gray-500">{subtext}</p>}
                </div>
            </div>
        </div>
    )

    const AssetRow = ({ asset, metric, metricLabel }) => {
        const thumb = asset.final_thumbnail_url || asset.preview_thumbnail_url || asset.thumbnail_url
        return (
            <div className="flex items-center gap-3 py-3 border-b border-gray-100 last:border-0">
                <div className="w-10 h-10 rounded-lg bg-gray-100 overflow-hidden flex-shrink-0">
                    {thumb ? (
                        <img src={thumb} alt={asset.title} className="w-full h-full object-cover" />
                    ) : (
                        <div className="w-full h-full flex items-center justify-center text-gray-400">
                            <FolderIcon className="w-5 h-5" />
                        </div>
                    )}
                </div>
                <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium text-gray-900 truncate">{asset.title}</p>
                    {asset.category?.name && (
                        <p className="text-xs text-gray-400">{asset.category.name}</p>
                    )}
                </div>
                <span className="text-sm font-medium text-gray-500 tabular-nums">
                    {metric.toLocaleString()} {metricLabel}
                </span>
            </div>
        )
    }

    return (
        <div className="min-h-full bg-gray-50">
            <AppHead title="Brand Dashboard" />
            <AppNav brand={authFromPage?.activeBrand || auth?.activeBrand} tenant={tenant} />

            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                {/* Header */}
                <div className="mb-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-4">
                        {activeBrand && (
                            <BrandAvatar
                                logoPath={activeBrand.logo_path}
                                iconBgColor={activeBrand.icon_bg_color}
                                name={activeBrand.name}
                                primaryColor={activeBrand.primary_color}
                                size="lg"
                            />
                        )}
                        <div>
                            <h1 className="text-2xl font-semibold text-gray-900">
                                {activeBrand?.name || 'Brand'} Dashboard
                            </h1>
                            <p className="text-sm text-gray-500 mt-1">
                                Detailed metrics and activity
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        {plan?.name && plan?.show_badge && (
                            <span className="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-indigo-100 text-indigo-800">
                                {plan.name} Plan
                            </span>
                        )}
                        <Link
                            href="/app/overview"
                            className="inline-flex items-center gap-1.5 rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            <ArrowLeftIcon className="h-4 w-4" />
                            Back to Overview
                        </Link>
                    </div>
                </div>

                {/* Core metrics */}
                <div className="mb-8">
                    <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                        Brand Totals
                    </h2>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {widget_visibility.total_assets !== false && (
                            <StatCard
                                icon={FolderIcon}
                                title="Total Assets"
                                value={stats?.total_assets?.value ?? 0}
                                change={stats?.total_assets?.change}
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                        {widget_visibility.storage !== false && (
                            <StatCard
                                icon={ServerIcon}
                                title="Storage"
                                value={stats?.storage_mb?.value ?? 0}
                                change={stats?.storage_mb?.change}
                                subtext={stats?.storage_mb?.limit
                                    ? formatStorageWithLimit(stats.storage_mb.value, stats.storage_mb.limit)
                                    : formatStorage(stats?.storage_mb?.value ?? 0) + ' used'}
                                formatValue={formatStorage}
                            />
                        )}
                        {widget_visibility.download_links !== false && (
                            <StatCard
                                icon={CloudArrowDownIcon}
                                title="Download Links (this month)"
                                value={stats?.download_links?.value ?? 0}
                                change={stats?.download_links?.change}
                                subtext={stats?.download_links?.limit
                                    ? formatDownloadsWithLimit(stats.download_links.value, stats.download_links.limit)
                                    : null}
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                    </div>
                </div>

                {/* Secondary metrics */}
                <div className="mb-8">
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard
                            icon={RectangleGroupIcon}
                            title="Collections"
                            value={stats?.collections_count ?? 0}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        <StatCard
                            icon={BoltIcon}
                            title="Executions"
                            value={stats?.executions_count ?? 0}
                            formatValue={(v) => v.toLocaleString()}
                        />
                        {widget_visibility.pending_ai_suggestions !== false && totalPendingSuggestions > 0 && (
                            <StatCard
                                icon={SparklesIcon}
                                title="Pending AI Suggestions"
                                value={totalPendingSuggestions}
                                subtext={`${pending_ai_suggestions?.metadata_candidates ?? 0} metadata, ${pending_ai_suggestions?.tag_candidates ?? 0} tags`}
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                        {widget_visibility.pending_metadata_approvals !== false && pending_metadata_approvals_count > 0 && (
                            <StatCard
                                icon={ClipboardDocumentCheckIcon}
                                title="Pending Approvals"
                                value={pending_metadata_approvals_count}
                                subtext="Metadata awaiting review"
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                        {widget_visibility.pending_asset_approvals !== false && pending_assets_count > 0 && (
                            <StatCard
                                icon={DocumentCheckIcon}
                                title="Pending Asset Approvals"
                                value={pending_assets_count}
                                subtext="Assets awaiting review"
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                        {unpublished_assets_count > 0 && is_manager && (
                            <StatCard
                                icon={DocumentCheckIcon}
                                title="Unpublished Assets"
                                value={unpublished_assets_count}
                                subtext="Waiting to be published"
                                formatValue={(v) => v.toLocaleString()}
                            />
                        )}
                    </div>
                </div>

                {/* AI Usage */}
                {ai_usage && (
                    <div className="mb-8">
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                            AI Usage
                        </h2>
                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            <StatCard
                                icon={SparklesIcon}
                                title="AI Tagging"
                                value={ai_usage.tagging.usage}
                                subtext={ai_usage.tagging.is_unlimited
                                    ? 'Unlimited'
                                    : `${ai_usage.tagging.remaining ?? 0} remaining this month`}
                                formatValue={(v) => ai_usage.tagging.is_unlimited
                                    ? `${v.toLocaleString()}`
                                    : `${v.toLocaleString()} of ${ai_usage.tagging.cap.toLocaleString()}`}
                            />
                            <StatCard
                                icon={SparklesIcon}
                                title="AI Suggestions"
                                value={ai_usage.suggestions.usage}
                                subtext={ai_usage.suggestions.is_unlimited
                                    ? 'Unlimited'
                                    : `${ai_usage.suggestions.remaining ?? 0} remaining this month`}
                                formatValue={(v) => ai_usage.suggestions.is_unlimited
                                    ? `${v.toLocaleString()}`
                                    : `${v.toLocaleString()} of ${ai_usage.suggestions.cap.toLocaleString()}`}
                            />
                            {ai_usage.thumbnail_enhancement && (
                                <StatCard
                                    icon={SparklesIcon}
                                    title="Thumbnail enhancement"
                                    value={ai_usage.thumbnail_enhancement.count ?? 0}
                                    subtext={
                                        (ai_usage.thumbnail_enhancement.count ?? 0) === 0
                                            ? 'No completed runs this month'
                                            : `${ai_usage.thumbnail_enhancement.success_rate ?? '—'}% success rate · avg ${ai_usage.thumbnail_enhancement.avg_duration_ms != null ? `${Math.round(ai_usage.thumbnail_enhancement.avg_duration_ms)} ms` : '—'}` +
                                              (ai_usage.thumbnail_enhancement.p95_duration_ms != null
                                                  ? ` · p95 ${Math.round(ai_usage.thumbnail_enhancement.p95_duration_ms)} ms`
                                                  : '') +
                                              ((ai_usage.thumbnail_enhancement.skipped_count ?? 0) > 0
                                                  ? ` · ${ai_usage.thumbnail_enhancement.skipped_count} skipped (guardrails)`
                                                  : '')
                                    }
                                    formatValue={(v) => v.toLocaleString()}
                                />
                            )}
                        </div>
                    </div>
                )}

                {/* Review widgets — interactive tiles for accepting AI suggestions and metadata */}
                {(totalPendingSuggestions > 0 || pending_metadata_approvals_count > 0) && (
                    <div className="mb-8">
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                            Review Queue
                        </h2>
                        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <PendingAiSuggestionsTile pendingCount={totalPendingSuggestions} />
                            <PendingMetadataTile pendingCount={pending_metadata_approvals_count} />
                        </div>
                    </div>
                )}

                {/* Top assets grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                    {/* Most viewed */}
                    {widget_visibility.most_viewed !== false && most_viewed_assets?.length > 0 && (
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="flex items-center gap-2 mb-4">
                                <EyeIcon className="h-5 w-5 text-gray-400" />
                                <h3 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">
                                    Most Viewed
                                </h3>
                            </div>
                            <div>
                                {most_viewed_assets.slice(0, 8).map((asset) => (
                                    <AssetRow
                                        key={asset.id}
                                        asset={asset}
                                        metric={asset.view_count}
                                        metricLabel="views"
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Most downloaded */}
                    {widget_visibility.most_downloaded !== false && most_downloaded_assets?.length > 0 && (
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="flex items-center gap-2 mb-4">
                                <CloudArrowDownIcon className="h-5 w-5 text-gray-400" />
                                <h3 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">
                                    Most Downloaded
                                </h3>
                            </div>
                            <div>
                                {most_downloaded_assets.slice(0, 8).map((asset) => (
                                    <AssetRow
                                        key={asset.id}
                                        asset={asset}
                                        metric={asset.download_count}
                                        metricLabel="downloads"
                                    />
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Trending */}
                    {widget_visibility.most_trending !== false && most_trending_assets?.length > 0 && (
                        <div className="bg-white rounded-lg shadow border border-gray-200 p-6">
                            <div className="flex items-center gap-2 mb-4">
                                <ArrowTrendingUpIcon className="h-5 w-5 text-gray-400" />
                                <h3 className="text-sm font-semibold text-gray-700 uppercase tracking-wide">
                                    Trending
                                </h3>
                            </div>
                            <div>
                                {most_trending_assets.slice(0, 8).map((asset) => (
                                    <AssetRow
                                        key={asset.id}
                                        asset={asset}
                                        metric={parseFloat(asset.trending_score?.toFixed(1) ?? 0)}
                                        metricLabel="score"
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Recent activity */}
                {recent_activity && recent_activity.length > 0 && (
                    <div className="mb-8">
                        <h2 className="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">
                            Recent Activity
                        </h2>
                        <div className="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                            <ul className="divide-y divide-gray-100">
                                {recent_activity.map((event) => (
                                    <li key={event.id} className="px-6 py-4">
                                        <div className="flex items-center gap-4">
                                            <ActivityActorAvatar actor={event.actor} size="sm" />
                                            <div className="min-w-0 flex-1">
                                                <p className="text-sm text-gray-900">
                                                    <span className="font-medium">{event.actor?.name}</span>
                                                    {' '}
                                                    <span className="text-gray-500">{event.event_type_label?.toLowerCase()}</span>
                                                    {' '}
                                                    <span className="font-medium">{event.subject?.name}</span>
                                                </p>
                                            </div>
                                            <span className="text-xs text-gray-400 flex-shrink-0">
                                                {event.created_at_human}
                                            </span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}
            </main>

            <AppFooter />
        </div>
    )
}
