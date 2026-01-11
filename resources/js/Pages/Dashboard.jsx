import { usePage } from '@inertiajs/react'
import { 
    FolderIcon, 
    CloudArrowDownIcon,
    ServerIcon,
    ArrowUpIcon,
    ArrowDownIcon
} from '@heroicons/react/24/outline'
import AppFooter from '../Components/AppFooter'
import AppNav from '../Components/AppNav'

export default function Dashboard({ auth, tenant, brand, plan_limits, stats = null }) {
    const { auth: authFromPage } = usePage().props

    // Default stats if not provided
    const defaultStats = {
        total_assets: { value: 0, change: 0, is_positive: true },
        storage_mb: { value: 0, change: 0, is_positive: true, limit: null },
        downloads: { value: 0, change: 0, is_positive: true },
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
                    <h2 className="text-3xl font-bold tracking-tight text-gray-900">Dashboard</h2>
                    <p className="mt-2 text-sm text-gray-700">Welcome to your asset management dashboard</p>
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

                    {/* Downloads Card */}
                    <div className="overflow-hidden rounded-lg bg-white px-4 py-5 shadow sm:p-6 border border-gray-200">
                        <div className="flex items-center">
                            <div className="flex-shrink-0">
                                <CloudArrowDownIcon className="h-6 w-6 text-gray-400" aria-hidden="true" />
                            </div>
                            <div className="ml-5 w-0 flex-1">
                                <dt className="text-sm font-medium text-gray-500 truncate">Downloads</dt>
                                <dd className="mt-1 flex items-baseline">
                                    <div className="flex-1 flex items-baseline">
                                        <span className="text-2xl font-semibold tracking-tight text-gray-900">
                                            {dashboardStats.downloads.value.toLocaleString()}
                                        </span>
                                        {dashboardStats.downloads.change !== 0 && (
                                            <span className="ml-2 flex items-baseline text-sm font-semibold">
                                                {dashboardStats.downloads.is_positive ? (
                                                    <ArrowUpIcon className="h-4 w-4 text-green-500 mr-0.5" aria-hidden="true" />
                                                ) : (
                                                    <ArrowDownIcon className="h-4 w-4 text-red-500 mr-0.5" aria-hidden="true" />
                                                )}
                                                {formatChange(dashboardStats.downloads.change, dashboardStats.downloads.is_positive)}
                                            </span>
                                        )}
                                    </div>
                                </dd>
                                {dashboardStats.downloads.change !== 0 && (
                                    <p className="mt-1 text-xs text-gray-500">vs last month</p>
                                )}
                            </div>
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
