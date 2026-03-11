import { usePage, Link } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import {
    FolderIcon,
    CloudArrowDownIcon,
    ServerIcon,
    ArrowUpIcon,
    ArrowDownIcon,
    SparklesIcon,
    ClockIcon,
    UserIcon,
    DocumentIcon,
    TagIcon,
    CogIcon,
    CheckCircleIcon,
    ArrowUpCircleIcon,
    BeakerIcon,
    ExclamationCircleIcon
} from '@heroicons/react/24/outline'
import AppFooter from '../../Components/AppFooter'
import AppNav from '../../Components/AppNav'
import Avatar from '../../Components/Avatar'
import BrandAvatar from '../../Components/BrandAvatar'
import PendingAiSuggestionsTile from '../../Components/PendingAiSuggestionsTile'
import PendingMetadataTile from '../../Components/PendingMetadataTile'
import PendingAssetTile from '../../Components/PendingAssetTile'
import AssetStatsCarousel from '../../Components/AssetStatsCarousel'
import OverviewTabs from '../../Components/Overview/OverviewTabs'

export default function Overview({
    auth,
    tenant,
    brand,
    plan,
    stats = null,
    most_viewed_assets = [],
    most_downloaded_assets = [],
    most_trending_assets = [],
    ai_usage = null,
    recent_activity = null,
    pending_ai_suggestions = null,
    unpublished_assets_count = 0,
    pending_metadata_approvals_count = 0,
    pending_assets_count = 0,
    contributor_pending_count = 0,
    contributor_rejected_count = 0,
    widget_visibility = {}
}) {
    const { auth: authFromPage } = usePage().props

    const defaultStats = {
        total_assets: { value: 0, change: 0, is_positive: true },
        storage_mb: { value: 0, change: 0, is_positive: true, limit: null },
        download_links: { value: 0, change: 0, is_positive: true, limit: null },
    }
    const dashboardStats = stats || defaultStats

    const showTotalAssets = widget_visibility.total_assets !== false
    const showStorage = widget_visibility.storage !== false
    const showDownloadLinks = widget_visibility.download_links !== false
    const showMostViewed = widget_visibility.most_viewed !== false
    const showMostDownloaded = widget_visibility.most_downloaded !== false
    const showMostTrending = widget_visibility.most_trending !== false
    const showPendingAiSuggestions = widget_visibility.pending_ai_suggestions !== false
    const showPendingMetadataApprovals = widget_visibility.pending_metadata_approvals !== false
    const showPendingAssetApprovals = widget_visibility.pending_asset_approvals !== false

    const formatStorage = (mb) => {
        if (mb < 1) return `${(mb * 1024).toFixed(2)} KB`
        if (mb < 1024) return `${mb.toFixed(2)} MB`
        return `${(mb / 1024).toFixed(2)} GB`
    }

    const isUnlimited = (limit) => !limit || limit >= 999999 || limit === Number.MAX_SAFE_INTEGER || limit === 2147483647

    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimited(limitMB)) return `${current} of Unlimited`
        return `${current} / ${formatStorage(limitMB)}`
    }

    const getStorageUsagePercentage = (currentMB, limitMB) => {
        if (!limitMB || isUnlimited(limitMB)) return 0
        return Math.min((currentMB / limitMB) * 100, 100)
    }

    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimited(limit)) return `${current.toLocaleString()} of Unlimited`
        return `${current.toLocaleString()} / ${limit.toLocaleString()}`
    }

    const getDownloadUsagePercentage = (current, limit) => {
        if (!limit || isUnlimited(limit)) return 0
        return Math.min((current / limit) * 100, 100)
    }

    const formatChange = (change, isPositive) => (
        <span className={`text-sm font-medium ${isPositive ? 'text-green-600' : 'text-red-600'}`}>
            {change >= 0 ? '+' : ''}{change.toFixed(2)}%
        </span>
    )

    const getActivityIcon = (eventType, actorType) => {
        const iconClass = 'h-5 w-5'
        if (eventType.includes('metadata')) return <TagIcon className={`${iconClass} text-blue-500`} />
        if (eventType.includes('uploaded') || eventType.includes('created')) return <ArrowUpCircleIcon className={`${iconClass} text-green-500`} />
        if (eventType.includes('ai') || eventType.includes('suggestions') || eventType.includes('tagging')) return <SparklesIcon className={`${iconClass} text-purple-500`} />
        if (eventType.includes('promoted') || eventType.includes('completed')) return <CheckCircleIcon className={`${iconClass} text-emerald-500`} />
        if (eventType.includes('agent') || eventType.includes('run')) return <BeakerIcon className={`${iconClass} text-indigo-500`} />
        if (actorType === 'user') return <UserIcon className={`${iconClass} text-gray-500`} />
        return <CogIcon className={`${iconClass} text-gray-400`} />
    }

    const getActivityLeftCell = (activity) => {
        const subjectType = activity.subject?.type ?? ''
        const isAssetSubject = subjectType.includes('Asset')
        if (isAssetSubject && activity.subject?.thumbnail_url) {
            return <img src={activity.subject.thumbnail_url} alt="" className="h-10 w-10 rounded-lg object-cover flex-shrink-0 bg-gray-100" />
        }
        if (activity.actor?.type === 'user' && (activity.actor?.avatar_url || activity.actor?.name)) {
            return (
                <Avatar
                    avatarUrl={activity.actor.avatar_url}
                    firstName={activity.actor.first_name ?? activity.actor.name?.split(' ')[0] ?? ''}
                    lastName={activity.actor.last_name ?? activity.actor.name?.split(' ').slice(1).join(' ') ?? ''}
                    email={activity.actor.email}
                    size="sm"
                />
            )
        }
        if (activity.company_name) {
            return (
                <div className="h-10 w-10 rounded-full bg-slate-100 flex items-center justify-center flex-shrink-0">
                    <span className="text-xs font-medium text-slate-700 px-1.5 truncate max-w-[4rem]" title={activity.company_name}>
                        {activity.company_name.charAt(0).toUpperCase()}
                    </span>
                </div>
            )
        }
        if (activity.brand) {
            return (
                <BrandAvatar
                    name={activity.brand.name}
                    logoPath={activity.brand.logo_path}
                    iconPath={activity.brand.icon_path}
                    icon={activity.brand.icon}
                    iconBgColor={activity.brand.icon_bg_color}
                    primaryColor={activity.brand.primary_color}
                    size="sm"
                />
            )
        }
        return (
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gray-50 group-hover:bg-white transition-colors">
                {getActivityIcon(activity.event_type, activity.actor?.type)}
            </div>
        )
    }

    const formatActivityDescription = (activity) => {
        const { subject, metadata } = activity
        const subjectDisplay = subject?.name && subject.name !== 'Unknown' ? subject.name : 'an asset'
        if (activity.event_type.includes('metadata_updated')) {
            const fieldName = metadata?.field_name || metadata?.metadata_field_name
            return fieldName ? `Updated ${fieldName} for ${subjectDisplay}` : `Updated metadata for ${subjectDisplay}`
        }
        if (activity.event_type.includes('promoted')) return `Published ${subjectDisplay}`
        if (activity.event_type.includes('ai_suggestions.generated')) {
            const fieldName = metadata?.field_name || metadata?.suggestion_type
            return fieldName ? `Generated ${fieldName} suggestions for ${subjectDisplay}` : `Generated AI suggestions for ${subjectDisplay}`
        }
        if (activity.event_type.includes('ai_metadata.generated')) return `Analyzed ${subjectDisplay} with AI`
        if (activity.event_type.includes('agent_run.completed')) {
            const agentType = metadata?.agent_type || metadata?.agent_name
            return agentType ? `Completed ${agentType} processing for ${subjectDisplay}` : `Completed AI analysis of ${subjectDisplay}`
        }
        if (activity.event_type.includes('uploaded')) return `Uploaded ${subjectDisplay}`
        if (activity.event_type.includes('deleted')) return `Deleted ${subjectDisplay}`
        if (activity.event_type.includes('created')) return `Created ${subjectDisplay}`
        if (activity.event_type.includes('updated')) return `Updated ${subjectDisplay}`
        if (activity.event_type.includes('tagged')) {
            const tagName = metadata?.tag_name
            return tagName ? `Added tag "${tagName}" to ${subjectDisplay}` : `Tagged ${subjectDisplay}`
        }
        return activity.description || `${activity.event_type_label} ${subjectDisplay}`
    }

    const activeBrand = brand ?? authFromPage?.activeBrand ?? auth?.activeBrand
    const brandName = activeBrand?.name

    return (
        <div className="min-h-full">
            <AppHead title="Overview" />
            <AppNav brand={authFromPage?.activeBrand || auth?.activeBrand} tenant={tenant} />

            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-6">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <Link href="/app" className="text-sm font-medium text-indigo-600 hover:text-indigo-900">
                                ← Company Overview
                            </Link>
                            <h1 className="text-2xl font-semibold text-gray-900 mt-2">Overview</h1>
                            {brandName && (
                                <div className="flex items-center gap-2 mt-1">
                                    {activeBrand && (
                                        <BrandAvatar
                                            logoPath={activeBrand.logo_path}
                                            iconPath={activeBrand.icon_path}
                                            icon={activeBrand.icon}
                                            iconBgColor={activeBrand.icon_bg_color}
                                            name={brandName}
                                            primaryColor={activeBrand.primary_color}
                                            size="lg"
                                        />
                                    )}
                                    <span className="text-sm text-gray-500">{brandName}</span>
                                </div>
                            )}
                        </div>
                        {plan?.show_badge && plan?.name && (
                            <span className="inline-flex w-fit items-center rounded-full px-3 py-1 text-sm font-medium bg-indigo-100 text-indigo-800">
                                {plan.name} Plan
                            </span>
                        )}
                    </div>
                </div>

                <OverviewTabs>
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {showTotalAssets && (
                            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <FolderIcon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dt className="text-sm font-medium text-gray-500 truncate">Total Assets</dt>
                                        <dd className="mt-1 flex items-baseline">
                                            <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                {dashboardStats.total_assets.value.toLocaleString()}
                                            </span>
                                            {dashboardStats.total_assets.change !== 0 && (
                                                <span className="ml-2 flex items-baseline text-sm font-semibold">
                                                    {dashboardStats.total_assets.is_positive ? (
                                                        <ArrowUpIcon className="h-4 w-4 text-green-500 mr-0.5" aria-hidden="true" />
                                                    ) : (
                                                        <ArrowDownIcon className="h-4 w-4 text-red-500 mr-0.5" aria-hidden="true" />
                                                    )}
                                                    {formatChange(dashboardStats.total_assets.change, dashboardStats.total_assets.is_positive)}
                                                </span>
                                            )}
                                        </dd>
                                        {dashboardStats.total_assets.change !== 0 && (
                                            <p className="mt-1 text-xs text-gray-500">vs last month</p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}

                        {showStorage && (
                            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <ServerIcon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dt className="text-sm font-medium text-gray-500 truncate">Storage</dt>
                                        <dd className="mt-1">
                                            <div className="flex items-baseline">
                                                <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                    {formatStorage(dashboardStats.storage_mb.value)}
                                                </span>
                                                {dashboardStats.storage_mb.change !== 0 && (
                                                    <span className="ml-2 flex items-baseline text-sm font-semibold">
                                                        {dashboardStats.storage_mb.is_positive ? (
                                                            <ArrowUpIcon className="h-4 w-4 text-green-500 mr-0.5" aria-hidden="true" />
                                                        ) : (
                                                            <ArrowDownIcon className="h-4 w-4 text-red-500 mr-0.5" aria-hidden="true" />
                                                        )}
                                                        {formatChange(dashboardStats.storage_mb.change, dashboardStats.storage_mb.is_positive)}
                                                    </span>
                                                )}
                                            </div>
                                            {dashboardStats.storage_mb.limit && (
                                                <p className="mt-1 text-xs text-gray-500">
                                                    {formatStorageWithLimit(dashboardStats.storage_mb.value, dashboardStats.storage_mb.limit)}
                                                </p>
                                            )}
                                            {dashboardStats.storage_mb.limit && !isUnlimited(dashboardStats.storage_mb.limit) && (
                                                <div className="mt-2">
                                                    <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                                                        <span>Usage</span>
                                                        <span>{getStorageUsagePercentage(dashboardStats.storage_mb.value, dashboardStats.storage_mb.limit).toFixed(1)}%</span>
                                                    </div>
                                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                                        <div
                                                            className={`h-2 rounded-full transition-all ${
                                                                getStorageUsagePercentage(dashboardStats.storage_mb.value, dashboardStats.storage_mb.limit) >= 90
                                                                    ? 'bg-red-500'
                                                                    : getStorageUsagePercentage(dashboardStats.storage_mb.value, dashboardStats.storage_mb.limit) >= 75
                                                                    ? 'bg-yellow-500'
                                                                    : 'bg-green-500'
                                                            }`}
                                                            style={{ width: `${getStorageUsagePercentage(dashboardStats.storage_mb.value, dashboardStats.storage_mb.limit)}%` }}
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                        </dd>
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                            <div className="flex items-center">
                                <div className="flex-shrink-0">
                                    <CloudArrowDownIcon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                                </div>
                                <div className="ml-5 w-0 flex-1">
                                    <dt className="text-sm font-medium text-gray-500 truncate">Download Links</dt>
                                    <dd className="mt-1">
                                        <div className="flex items-baseline">
                                            <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                {dashboardStats.download_links.value.toLocaleString()}
                                            </span>
                                            {dashboardStats.download_links.change !== 0 && (
                                                <span className="ml-2 flex items-baseline text-sm font-semibold">
                                                    {dashboardStats.download_links.is_positive ? (
                                                        <ArrowUpIcon className="h-4 w-4 text-green-500 mr-0.5" aria-hidden="true" />
                                                    ) : (
                                                        <ArrowDownIcon className="h-4 w-4 text-red-500 mr-0.5" aria-hidden="true" />
                                                    )}
                                                    {formatChange(dashboardStats.download_links.change, dashboardStats.download_links.is_positive)}
                                                </span>
                                            )}
                                        </div>
                                        {dashboardStats.download_links.limit && (
                                            <p className="mt-1 text-xs text-gray-500">
                                                {formatDownloadsWithLimit(dashboardStats.download_links.value, dashboardStats.download_links.limit)}
                                            </p>
                                        )}
                                        {dashboardStats.download_links.limit && !isUnlimited(dashboardStats.download_links.limit) && (
                                            <div className="mt-2">
                                                <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                                                    <span>Usage</span>
                                                    <span>{getDownloadUsagePercentage(dashboardStats.download_links.value, dashboardStats.download_links.limit).toFixed(1)}%</span>
                                                </div>
                                                <div className="w-full bg-gray-200 rounded-full h-2">
                                                    <div
                                                        className={`h-2 rounded-full transition-all ${
                                                            getDownloadUsagePercentage(dashboardStats.download_links.value, dashboardStats.download_links.limit) >= 90
                                                                ? 'bg-red-500'
                                                                : getDownloadUsagePercentage(dashboardStats.download_links.value, dashboardStats.download_links.limit) >= 75
                                                                ? 'bg-yellow-500'
                                                                : 'bg-green-500'
                                                        }`}
                                                        style={{ width: `${getDownloadUsagePercentage(dashboardStats.download_links.value, dashboardStats.download_links.limit)}%` }}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </dd>
                                </div>
                            </div>
                        </div>

                        {unpublished_assets_count > 0 && (
                            <Link
                                href="/app/assets?lifecycle=unpublished"
                                className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200 hover:border-yellow-300 hover:shadow-md transition-all cursor-pointer"
                            >
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <ExclamationCircleIcon className="h-6 w-6 text-yellow-500" aria-hidden="true" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dt className="text-sm font-medium text-gray-500 truncate">Waiting to be Published</dt>
                                        <dd className="mt-1">
                                            <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                {unpublished_assets_count.toLocaleString()}
                                            </span>
                                            <p className="mt-1 text-xs text-gray-500">Click to view unpublished assets</p>
                                        </dd>
                                    </div>
                                </div>
                            </Link>
                        )}

                        {showPendingAiSuggestions && pending_ai_suggestions && (
                            <PendingAiSuggestionsTile pendingCount={pending_ai_suggestions.total || 0} />
                        )}

                        {showPendingMetadataApprovals && (
                            <PendingMetadataTile pendingCount={pending_metadata_approvals_count || 0} />
                        )}

                        {showPendingAssetApprovals && pending_assets_count > 0 && (
                            <PendingAssetTile pendingCount={pending_assets_count || 0} />
                        )}

                        {(contributor_pending_count > 0 || contributor_rejected_count > 0) && (
                            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <DocumentIcon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dt className="text-sm font-medium text-gray-500 truncate">Your Assets</dt>
                                        <dd className="mt-1 space-y-1">
                                            {contributor_pending_count > 0 && (
                                                <Link href="/app/assets?lifecycle=pending_publication" className="text-sm text-gray-600 hover:text-indigo-600 cursor-pointer block">
                                                    <span className="font-semibold text-gray-900">{contributor_pending_count}</span> pending review
                                                </Link>
                                            )}
                                            {contributor_rejected_count > 0 && (
                                                <Link href="/app/assets?lifecycle=pending_publication" className="text-sm text-gray-600 hover:text-indigo-600 cursor-pointer block">
                                                    <span className="font-semibold text-gray-900">{contributor_rejected_count}</span> rejected
                                                </Link>
                                            )}
                                        </dd>
                                    </div>
                                </div>
                            </div>
                        )}

                        {ai_usage && (
                            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <SparklesIcon className="h-6 w-6 text-purple-400" aria-hidden="true" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dt className="text-sm font-medium text-gray-500 truncate">AI Tagging</dt>
                                        <dd className="mt-1">
                                            <div className="flex items-baseline">
                                                <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                    {ai_usage.tagging.usage.toLocaleString()}
                                                </span>
                                                {!ai_usage.tagging.is_unlimited && (
                                                    <span className="ml-2 text-sm text-gray-500">of {ai_usage.tagging.cap.toLocaleString()}</span>
                                                )}
                                            </div>
                                            {ai_usage.tagging.is_unlimited ? (
                                                <p className="mt-1 text-xs text-gray-500">Unlimited</p>
                                            ) : ai_usage.tagging.is_disabled ? (
                                                <p className="mt-1 text-xs text-gray-500">Disabled</p>
                                            ) : (
                                                <>
                                                    <p className="mt-1 text-xs text-gray-500">
                                                        {ai_usage.tagging.remaining !== null ? `${ai_usage.tagging.remaining.toLocaleString()} remaining this month` : 'N/A'}
                                                    </p>
                                                    <div className="mt-2">
                                                        <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                                                            <span>Usage</span>
                                                            <span>{ai_usage.tagging.percentage.toFixed(1)}%</span>
                                                        </div>
                                                        <div className="w-full bg-gray-200 rounded-full h-2">
                                                            <div
                                                                className={`h-2 rounded-full transition-all ${
                                                                    ai_usage.tagging.is_exceeded ? 'bg-red-500'
                                                                        : ai_usage.tagging.percentage >= 80 ? 'bg-yellow-500'
                                                                        : 'bg-purple-500'
                                                                }`}
                                                                style={{ width: `${Math.min(100, ai_usage.tagging.percentage)}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                </>
                                            )}
                                        </dd>
                                    </div>
                                </div>
                            </div>
                        )}

                        {ai_usage && (
                            <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <SparklesIcon className="h-6 w-6 text-indigo-400" aria-hidden="true" />
                                    </div>
                                    <div className="ml-5 w-0 flex-1">
                                        <dt className="text-sm font-medium text-gray-500 truncate">AI Suggestions</dt>
                                        <dd className="mt-1">
                                            <div className="flex items-baseline">
                                                <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                    {ai_usage.suggestions.usage.toLocaleString()}
                                                </span>
                                                {!ai_usage.suggestions.is_unlimited && (
                                                    <span className="ml-2 text-sm text-gray-500">of {ai_usage.suggestions.cap.toLocaleString()}</span>
                                                )}
                                            </div>
                                            {ai_usage.suggestions.is_unlimited ? (
                                                <p className="mt-1 text-xs text-gray-500">Unlimited</p>
                                            ) : ai_usage.suggestions.is_disabled ? (
                                                <p className="mt-1 text-xs text-gray-500">Disabled</p>
                                            ) : (
                                                <>
                                                    <p className="mt-1 text-xs text-gray-500">
                                                        {ai_usage.suggestions.remaining !== null ? `${ai_usage.suggestions.remaining.toLocaleString()} remaining this month` : 'N/A'}
                                                    </p>
                                                    <div className="mt-2">
                                                        <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                                                            <span>Usage</span>
                                                            <span>{ai_usage.suggestions.percentage.toFixed(1)}%</span>
                                                        </div>
                                                        <div className="w-full bg-gray-200 rounded-full h-2">
                                                            <div
                                                                className={`h-2 rounded-full transition-all ${
                                                                    ai_usage.suggestions.is_exceeded ? 'bg-red-500'
                                                                        : ai_usage.suggestions.percentage >= 80 ? 'bg-yellow-500'
                                                                        : 'bg-indigo-500'
                                                                }`}
                                                                style={{ width: `${Math.min(100, ai_usage.suggestions.percentage)}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                </>
                                            )}
                                        </dd>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    {(showMostViewed || showMostDownloaded || showMostTrending) && (
                        <div className="mt-8 -mx-4 sm:-mx-6 lg:-mx-8 overflow-visible">
                            <AssetStatsCarousel
                                mostViewedAssets={showMostViewed ? most_viewed_assets : []}
                                mostDownloadedAssets={showMostDownloaded ? most_downloaded_assets : []}
                                mostTrendingAssets={showMostTrending ? most_trending_assets : []}
                                maxItems={7}
                                viewAllLink="/app/assets"
                            />
                        </div>
                    )}

                    {recent_activity && (
                        <div className="mt-8">
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-4 py-5 sm:p-6">
                                    <div className="flex items-center justify-between mb-6">
                                        <h3 className="text-lg font-semibold leading-6 text-gray-900 flex items-center gap-2">
                                            <ClockIcon className="h-5 w-5 text-gray-400" />
                                            Recent Activity
                                        </h3>
                                        <Link
                                            href="/app/companies/activity"
                                            className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-900 transition-colors"
                                        >
                                            View All
                                            <ArrowUpIcon className="h-3 w-3 rotate-45" />
                                        </Link>
                                    </div>
                                    {recent_activity.length > 0 ? (
                                        <div className="space-y-4">
                                            {recent_activity.map((activity, index) => (
                                                <div
                                                    key={activity.id}
                                                    className="group relative flex items-start gap-4 p-4 rounded-xl border border-gray-100 hover:border-gray-200 hover:bg-gray-50/50 transition-all duration-200"
                                                >
                                                    <div className="flex-shrink-0">{getActivityLeftCell(activity)}</div>
                                                    <div className="flex-1 min-w-0">
                                                        <div className="flex items-start justify-between">
                                                            <div className="flex-1">
                                                                <p className="text-sm font-medium text-gray-900 leading-5">
                                                                    {formatActivityDescription(activity)}
                                                                </p>
                                                                <div className="mt-2 flex items-center gap-3 text-xs text-gray-500 flex-wrap">
                                                                    <span className="flex items-center gap-1">
                                                                        {activity.actor?.type === 'user' ? (
                                                                            <UserIcon className="h-3 w-3 flex-shrink-0" />
                                                                        ) : (
                                                                            <CogIcon className="h-3 w-3 flex-shrink-0" />
                                                                        )}
                                                                        {activity.company_name || activity.actor?.name}
                                                                    </span>
                                                                    {activity.brand && (
                                                                        <>
                                                                            <span>•</span>
                                                                            <span>{activity.brand.name}</span>
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </div>
                                                            <time className="flex-shrink-0 text-xs font-medium text-gray-500 bg-gray-100 px-2 py-1 rounded-full">
                                                                {activity.created_at_human}
                                                            </time>
                                                        </div>
                                                    </div>
                                                    {index < recent_activity.length - 1 && (
                                                        <div className="absolute left-8 top-14 h-4 w-px bg-gray-200"></div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-center py-12">
                                            <div className="mx-auto h-16 w-16 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                                                <ClockIcon className="h-8 w-8 text-gray-400" />
                                            </div>
                                            <h3 className="text-sm font-semibold text-gray-900">No recent activity</h3>
                                            <p className="mt-1 text-sm text-gray-500 max-w-sm mx-auto">
                                                Activity will appear here as you upload assets and work with your content.
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                </OverviewTabs>
            </main>

            <AppFooter />
        </div>
    )
}
