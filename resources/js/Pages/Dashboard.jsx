import { usePage, Link } from '@inertiajs/react'
import { 
    FolderIcon, 
    CloudArrowDownIcon,
    ServerIcon,
    ArrowUpIcon,
    ArrowDownIcon,
    EyeIcon,
    SparklesIcon,
    ClockIcon,
    UserIcon,
    DocumentIcon,
    PhotoIcon,
    TagIcon,
    CogIcon,
    CheckCircleIcon,
    ArrowUpCircleIcon,
    BeakerIcon,
    ExclamationCircleIcon
} from '@heroicons/react/24/outline'
import AppFooter from '../Components/AppFooter'
import AppNav from '../Components/AppNav'
import ThumbnailPreview from '../Components/ThumbnailPreview'
import PendingAiSuggestionsTile from '../Components/PendingAiSuggestionsTile'

export default function Dashboard({ auth, tenant, brand, plan_limits, plan, stats = null, most_viewed_assets = [], most_downloaded_assets = [], ai_usage = null, recent_activity = null, pending_ai_suggestions = null, unpublished_assets_count = 0, pending_metadata_approvals_count = 0, widget_visibility = {} }) {
    const { auth: authFromPage } = usePage().props

    // Default stats if not provided
    const defaultStats = {
        total_assets: { value: 0, change: 0, is_positive: true },
        storage_mb: { value: 0, change: 0, is_positive: true, limit: null },
        download_links: { value: 0, change: 0, is_positive: true, limit: null },
    }
    const dashboardStats = stats || defaultStats
    
    // Widget visibility configuration (defaults to showing all if not configured)
    const showTotalAssets = widget_visibility.total_assets !== false
    const showStorage = widget_visibility.storage !== false
    const showDownloadLinks = widget_visibility.download_links !== false
    const showMostViewed = widget_visibility.most_viewed !== false
    const showMostDownloaded = widget_visibility.most_downloaded !== false

    // Format storage size with appropriate unit
    const formatStorage = (mb) => {
        if (mb < 1) {
            return `${(mb * 1024).toFixed(2)} KB`
        } else if (mb < 1024) {
            return `${mb.toFixed(2)} MB`
        } else {
            return `${(mb / 1024).toFixed(2)} GB`
        }
    }

    // Check if storage limit is "unlimited" (999999 or very large number)
    const isUnlimited = (limit) => {
        return !limit || limit >= 999999 || limit === Number.MAX_SAFE_INTEGER || limit === 2147483647
    }

    // Format storage with limit
    const formatStorageWithLimit = (currentMB, limitMB) => {
        const current = formatStorage(currentMB)
        if (!limitMB || isUnlimited(limitMB)) {
            return `${current} of Unlimited`
        }
        const limit = formatStorage(limitMB)
        return `${current} / ${limit}`
    }

    // Calculate storage usage percentage
    const getStorageUsagePercentage = (currentMB, limitMB) => {
        if (!limitMB || isUnlimited(limitMB)) {
            return 0
        }
        return Math.min((currentMB / limitMB) * 100, 100)
    }

    // Format downloads with limit
    const formatDownloadsWithLimit = (current, limit) => {
        if (!limit || isUnlimited(limit)) {
            return `${current.toLocaleString()} of Unlimited`
        }
        return `${current.toLocaleString()} / ${limit.toLocaleString()}`
    }

    // Calculate download usage percentage
    const getDownloadUsagePercentage = (current, limit) => {
        if (!limit || isUnlimited(limit)) {
            return 0
        }
        return Math.min((current / limit) * 100, 100)
    }

    // Format percentage change
    const formatChange = (change, isPositive) => {
        const sign = change >= 0 ? '+' : ''
        const colorClass = isPositive ? 'text-green-600' : 'text-red-600'
        return (
            <span className={`text-sm font-medium ${colorClass}`}>
                {sign}{change.toFixed(2)}%
            </span>
        )
    }

    // Get appropriate icon and color for activity type
    const getActivityIcon = (eventType, actorType) => {
        const iconClass = "h-5 w-5"
        
        if (eventType.includes('metadata')) {
            return <TagIcon className={`${iconClass} text-blue-500`} />
        } else if (eventType.includes('uploaded') || eventType.includes('created')) {
            return <ArrowUpCircleIcon className={`${iconClass} text-green-500`} />
        } else if (eventType.includes('ai') || eventType.includes('suggestions') || eventType.includes('tagging')) {
            return <SparklesIcon className={`${iconClass} text-purple-500`} />
        } else if (eventType.includes('promoted') || eventType.includes('completed')) {
            return <CheckCircleIcon className={`${iconClass} text-emerald-500`} />
        } else if (eventType.includes('agent') || eventType.includes('run')) {
            return <BeakerIcon className={`${iconClass} text-indigo-500`} />
        } else if (actorType === 'user') {
            return <UserIcon className={`${iconClass} text-gray-500`} />
        } else {
            return <CogIcon className={`${iconClass} text-gray-400`} />
        }
    }

    // Format activity description to be more user-friendly
    const formatActivityDescription = (activity) => {
        const { event_type_label, actor, subject, metadata } = activity
        
        // Get a clean subject name
        const subjectDisplay = subject.name && subject.name !== 'Unknown' 
            ? subject.name 
            : 'an asset'
        
        // Create more natural descriptions based on event type
        if (activity.event_type.includes('metadata_updated')) {
            const fieldName = metadata?.field_name || metadata?.metadata_field_name
            if (fieldName) {
                return `Updated ${fieldName} for ${subjectDisplay}`
            }
            return `Updated metadata for ${subjectDisplay}`
        } else if (activity.event_type.includes('promoted')) {
            return `Published ${subjectDisplay}`
        } else if (activity.event_type.includes('ai_suggestions.generated')) {
            const fieldName = metadata?.field_name || metadata?.suggestion_type
            if (fieldName) {
                return `Generated ${fieldName} suggestions for ${subjectDisplay}`
            }
            return `Generated AI suggestions for ${subjectDisplay}`
        } else if (activity.event_type.includes('ai_metadata.generated')) {
            return `Analyzed ${subjectDisplay} with AI`
        } else if (activity.event_type.includes('agent_run.completed')) {
            const agentType = metadata?.agent_type || metadata?.agent_name
            if (agentType) {
                return `Completed ${agentType} processing for ${subjectDisplay}`
            }
            return `Completed AI analysis of ${subjectDisplay}`
        } else if (activity.event_type.includes('uploaded')) {
            return `Uploaded ${subjectDisplay}`
        } else if (activity.event_type.includes('deleted')) {
            return `Deleted ${subjectDisplay}`
        } else if (activity.event_type.includes('created')) {
            return `Created ${subjectDisplay}`
        } else if (activity.event_type.includes('updated')) {
            return `Updated ${subjectDisplay}`
        } else if (activity.event_type.includes('tagged')) {
            const tagName = metadata?.tag_name
            if (tagName) {
                return `Added tag "${tagName}" to ${subjectDisplay}`
            }
            return `Tagged ${subjectDisplay}`
        } else {
            // Fallback to original description or create one
            return activity.description || `${event_type_label} ${subjectDisplay}`
        }
    }

    return (
        <div className="min-h-full">
            <AppNav brand={authFromPage?.activeBrand || auth.activeBrand} tenant={tenant} />

            <main className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                <div className="mb-8">
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="text-3xl font-bold tracking-tight text-gray-900">Dashboard</h2>
                            <p className="mt-2 text-sm text-gray-700">Welcome to your asset management dashboard</p>
                        </div>
                        {plan?.show_badge && plan?.name && (
                            <span className="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium bg-indigo-100 text-indigo-800">
                                {plan.name} Plan
                            </span>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {/* Total Assets Card */}
                    {showTotalAssets && (
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                        <div className="flex items-center">
                            <div className="flex-shrink-0">
                                <FolderIcon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                            </div>
                            <div className="ml-5 w-0 flex-1">
                                <dt className="text-sm font-medium text-gray-500 truncate">Total Assets</dt>
                                <dd className="mt-1 flex items-baseline">
                                    <div className="flex-1 flex items-baseline">
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
                                    </div>
                                </dd>
                                {dashboardStats.total_assets.change !== 0 && (
                                    <p className="mt-1 text-xs text-gray-500">vs last month</p>
                                )}
                            </div>
                        </div>
                    </div>
                    )}

                    {/* Storage Size Card */}
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
                                                    style={{
                                                        width: `${getStorageUsagePercentage(dashboardStats.storage_mb.value, dashboardStats.storage_mb.limit)}%`
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                    {dashboardStats.storage_mb.change !== 0 && !dashboardStats.storage_mb.limit && (
                                        <p className="mt-1 text-xs text-gray-500">vs last month</p>
                                    )}
                                </dd>
                            </div>
                        </div>
                    </div>
                    )}

                    {/* Download Links Card */}
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
                                                    style={{
                                                        width: `${getDownloadUsagePercentage(dashboardStats.download_links.value, dashboardStats.download_links.limit)}%`
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    )}
                                    {dashboardStats.download_links.change !== 0 && !dashboardStats.download_links.limit && (
                                        <p className="mt-1 text-xs text-gray-500">vs last month</p>
                                    )}
                                </dd>
                            </div>
                        </div>
                    </div>

                    {/* Phase L.5.1: Unpublished Assets Tile */}
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
                                        <div className="flex items-baseline">
                                            <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                {unpublished_assets_count.toLocaleString()}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-gray-500">Click to view unpublished assets</p>
                                    </dd>
                                </div>
                            </div>
                        </Link>
                    )}

                    {/* Pending AI Suggestions Tile */}
                    {pending_ai_suggestions && (
                        <PendingAiSuggestionsTile pendingCount={pending_ai_suggestions.total || 0} />
                    )}

                    {/* Pending Metadata Approvals Tile */}
                    {pending_metadata_approvals_count > 0 && auth?.metadata_approval_features?.metadata_approval_enabled && (
                        <Link
                            href="/app/assets"
                            className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200 hover:border-yellow-300 hover:shadow-md transition-all cursor-pointer"
                        >
                            <div className="flex items-center">
                                <div className="flex-shrink-0">
                                    <ExclamationCircleIcon className="h-6 w-6 text-yellow-500" aria-hidden="true" />
                                </div>
                                <div className="ml-5 w-0 flex-1">
                                    <dt className="text-sm font-medium text-gray-500 truncate">Pending Metadata Approvals</dt>
                                    <dd className="mt-1">
                                        <div className="flex items-baseline">
                                            <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                                {pending_metadata_approvals_count.toLocaleString()}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-gray-500">Click to review pending metadata</p>
                                    </dd>
                                </div>
                            </div>
                        </Link>
                    )}

                    {/* AI Tagging Card - Only show if user has permission and data is available */}
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
                                                <span className="ml-2 text-sm text-gray-500">
                                                    of {ai_usage.tagging.cap.toLocaleString()}
                                                </span>
                                            )}
                                        </div>
                                        {ai_usage.tagging.is_unlimited ? (
                                            <p className="mt-1 text-xs text-gray-500">Unlimited</p>
                                        ) : ai_usage.tagging.is_disabled ? (
                                            <p className="mt-1 text-xs text-gray-500">Disabled</p>
                                        ) : (
                                            <>
                                                <p className="mt-1 text-xs text-gray-500">
                                                    {ai_usage.tagging.remaining !== null 
                                                        ? `${ai_usage.tagging.remaining.toLocaleString()} remaining this month`
                                                        : 'N/A'}
                                                </p>
                                                <div className="mt-2">
                                                    <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                                                        <span>Usage</span>
                                                        <span>{ai_usage.tagging.percentage.toFixed(1)}%</span>
                                                    </div>
                                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                                        <div
                                                            className={`h-2 rounded-full transition-all ${
                                                                ai_usage.tagging.is_exceeded
                                                                    ? 'bg-red-500'
                                                                    : ai_usage.tagging.percentage >= 80
                                                                    ? 'bg-yellow-500'
                                                                    : 'bg-purple-500'
                                                            }`}
                                                            style={{
                                                                width: `${Math.min(100, ai_usage.tagging.percentage)}%`
                                                            }}
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

                    {/* AI Suggestions Card - Only show if user has permission and data is available */}
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
                                                <span className="ml-2 text-sm text-gray-500">
                                                    of {ai_usage.suggestions.cap.toLocaleString()}
                                                </span>
                                            )}
                                        </div>
                                        {ai_usage.suggestions.is_unlimited ? (
                                            <p className="mt-1 text-xs text-gray-500">Unlimited</p>
                                        ) : ai_usage.suggestions.is_disabled ? (
                                            <p className="mt-1 text-xs text-gray-500">Disabled</p>
                                        ) : (
                                            <>
                                                <p className="mt-1 text-xs text-gray-500">
                                                    {ai_usage.suggestions.remaining !== null 
                                                        ? `${ai_usage.suggestions.remaining.toLocaleString()} remaining this month`
                                                        : 'N/A'}
                                                </p>
                                                <div className="mt-2">
                                                    <div className="flex items-center justify-between text-xs text-gray-500 mb-1">
                                                        <span>Usage</span>
                                                        <span>{ai_usage.suggestions.percentage.toFixed(1)}%</span>
                                                    </div>
                                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                                        <div
                                                            className={`h-2 rounded-full transition-all ${
                                                                ai_usage.suggestions.is_exceeded
                                                                    ? 'bg-red-500'
                                                                    : ai_usage.suggestions.percentage >= 80
                                                                    ? 'bg-yellow-500'
                                                                    : 'bg-indigo-500'
                                                            }`}
                                                            style={{
                                                                width: `${Math.min(100, ai_usage.suggestions.percentage)}%`
                                                            }}
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

                {/* Most Viewed and Most Downloaded Blocks */}
                {(showMostViewed || showMostDownloaded) && (
                <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Most Viewed Assets */}
                    {showMostViewed && (
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-4 py-5 sm:p-6">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-base font-semibold leading-6 text-gray-900 flex items-center gap-2">
                                    <EyeIcon className="h-5 w-5 text-gray-400" />
                                    Most Viewed
                                </h3>
                                {most_viewed_assets.length > 0 && (
                                    <Link
                                        href="/app/assets"
                                        className="text-sm text-indigo-600 hover:text-indigo-900"
                                    >
                                        View All
                                    </Link>
                                )}
                            </div>
                            {most_viewed_assets.length > 0 ? (
                                <div className="space-y-3">
                                    {most_viewed_assets.map((asset) => (
                                        <Link
                                            key={asset.id}
                                            href="/app/assets"
                                            className="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 transition-colors group"
                                        >
                                            <div className="flex-shrink-0 w-16 h-16 rounded-md overflow-hidden bg-gray-100">
                                                <ThumbnailPreview
                                                    asset={asset}
                                                    alt={asset.title}
                                                    className="w-full h-full object-cover"
                                                    size="sm"
                                                />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium text-gray-900 truncate group-hover:text-indigo-600">
                                                    {asset.title}
                                                </p>
                                                <div className="flex items-center gap-1 mt-1">
                                                    <EyeIcon className="h-4 w-4 text-gray-400" />
                                                    <span className="text-xs text-gray-500">
                                                        {asset.view_count.toLocaleString()} {asset.view_count === 1 ? 'view' : 'views'}
                                                    </span>
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <EyeIcon className="mx-auto h-8 w-8 text-gray-400" />
                                    <p className="mt-2 text-sm text-gray-500">No views yet</p>
                                </div>
                            )}
                        </div>
                    </div>
                    )}

                    {/* Most Downloaded Assets */}
                    {showMostDownloaded && (
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-4 py-5 sm:p-6">
                            <div className="flex items-center justify-between mb-4">
                                <h3 className="text-base font-semibold leading-6 text-gray-900 flex items-center gap-2">
                                    <CloudArrowDownIcon className="h-5 w-5 text-gray-400" />
                                    Most Downloaded
                                </h3>
                                {most_downloaded_assets.length > 0 && (
                                    <Link
                                        href="/app/assets"
                                        className="text-sm text-indigo-600 hover:text-indigo-900"
                                    >
                                        View All
                                    </Link>
                                )}
                            </div>
                            {most_downloaded_assets.length > 0 ? (
                                <div className="space-y-3">
                                    {most_downloaded_assets.map((asset) => (
                                        <Link
                                            key={asset.id}
                                            href="/app/assets"
                                            className="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 transition-colors group"
                                        >
                                            <div className="flex-shrink-0 w-16 h-16 rounded-md overflow-hidden bg-gray-100">
                                                <ThumbnailPreview
                                                    asset={asset}
                                                    alt={asset.title}
                                                    className="w-full h-full object-cover"
                                                    size="sm"
                                                />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <p className="text-sm font-medium text-gray-900 truncate group-hover:text-indigo-600">
                                                    {asset.title}
                                                </p>
                                                <div className="flex items-center gap-1 mt-1">
                                                    <CloudArrowDownIcon className="h-4 w-4 text-gray-400" />
                                                    <span className="text-xs text-gray-500">
                                                        {asset.download_count.toLocaleString()} {asset.download_count === 1 ? 'download' : 'downloads'}
                                                    </span>
                                                </div>
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center py-8">
                                    <CloudArrowDownIcon className="mx-auto h-8 w-8 text-gray-400" />
                                    <p className="mt-2 text-sm text-gray-500">No downloads yet</p>
                                </div>
                            )}
                        </div>
                    </div>
                    )}
                </div>
                )}

                {/* Recent Activity - Only show if user has permission */}
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
                                                {/* Icon with background */}
                                                <div className="flex-shrink-0">
                                                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gray-50 group-hover:bg-white transition-colors">
                                                        {getActivityIcon(activity.event_type, activity.actor.type)}
                                                    </div>
                                                </div>
                                                
                                                {/* Content */}
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-start justify-between">
                                                        <div className="flex-1">
                                                            <p className="text-sm font-medium text-gray-900 leading-5">
                                                                {formatActivityDescription(activity)}
                                                            </p>
                                                            <div className="mt-2 flex items-center gap-3 text-xs text-gray-500">
                                                                <span className="flex items-center gap-1">
                                                                    {activity.actor.type === 'user' ? (
                                                                        <UserIcon className="h-3 w-3" />
                                                                    ) : (
                                                                        <CogIcon className="h-3 w-3" />
                                                                    )}
                                                                    {activity.actor.name}
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

                                                {/* Connecting line (except for last item) */}
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
            </main>

            <AppFooter />
        </div>
    )
}
