import { usePage, Link } from '@inertiajs/react'
import { 
    FolderIcon, 
    CloudArrowDownIcon,
    ServerIcon,
    ArrowUpIcon,
    ArrowDownIcon,
    EyeIcon,
    SparklesIcon
} from '@heroicons/react/24/outline'
import AppFooter from '../Components/AppFooter'
import AppNav from '../Components/AppNav'
import ThumbnailPreview from '../Components/ThumbnailPreview'

export default function Dashboard({ auth, tenant, brand, plan_limits, plan, stats = null, most_viewed_assets = [], most_downloaded_assets = [], ai_usage = null }) {
    const { auth: authFromPage } = usePage().props

    // Default stats if not provided
    const defaultStats = {
        total_assets: { value: 0, change: 0, is_positive: true },
        storage_mb: { value: 0, change: 0, is_positive: true, limit: null },
        download_links: { value: 0, change: 0, is_positive: true, limit: null },
    }
    const dashboardStats = stats || defaultStats

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

                    {/* Storage Size Card */}
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
                <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Most Viewed Assets */}
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

                    {/* Most Downloaded Assets */}
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
                </div>

                <div className="mt-8">
                    <div className="overflow-hidden rounded-lg bg-white shadow">
                        <div className="px-4 py-5 sm:p-6">
                            <h3 className="text-base font-semibold leading-6 text-gray-900">Recent Activity</h3>
                            <div className="mt-5">
                                <div className="text-center py-12">
                                    <svg
                                        className="mx-auto h-12 w-12 text-gray-400"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        aria-hidden="true"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth={2}
                                            d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"
                                        />
                                    </svg>
                                    <h3 className="mt-2 text-sm font-semibold text-gray-900">No activity</h3>
                                    <p className="mt-1 text-sm text-gray-500">Get started by adding your first asset.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <AppFooter />
        </div>
    )
}
