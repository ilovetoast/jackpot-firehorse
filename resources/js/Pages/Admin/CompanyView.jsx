import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import {
    BuildingOffice2Icon as BuildingOfficeIcon,
    ChartBarIcon,
    UsersIcon,
    TagIcon,
    ClockIcon,
    ArrowLeftIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
} from '@heroicons/react/24/outline'

export default function AdminCompanyView({ 
    company, 
    monthlyData, 
    currentCosts, 
    currentIncome, 
    profitability,
    recentActivity,
    users,
    brands,
    stats
}) {
    // Format currency
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
        }).format(amount || 0)
    }

    // Format date
    const formatDate = (dateString) => {
        if (!dateString) return 'N/A'
        try {
            const date = new Date(dateString)
            return date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            })
        } catch (e) {
            return dateString
        }
    }

    // Get profitability badge config
    const getProfitabilityBadge = () => {
        if (!profitability) return null
        
        const configs = {
            profitable: {
                label: profitability.label,
                className: 'bg-green-100 text-green-800',
                icon: CheckCircleIcon,
            },
            break_even: {
                label: profitability.label,
                className: 'bg-yellow-100 text-yellow-800',
                icon: ExclamationTriangleIcon,
            },
            losing: {
                label: profitability.label,
                className: 'bg-red-100 text-red-800',
                icon: XCircleIcon,
            },
            unknown: {
                label: profitability.label,
                className: 'bg-gray-100 text-gray-800',
                icon: ClockIcon,
            },
            no_data: {
                label: profitability.label,
                className: 'bg-gray-100 text-gray-800',
                icon: ClockIcon,
            },
        }

        return configs[profitability.rating] || configs.unknown
    }

    // Calculate chart dimensions
    const chartHeight = 200
    const chartWidth = 600
    const maxValue = Math.max(
        ...monthlyData.map(d => Math.max(d.income, d.total_cost)),
        1 // Minimum 1 to avoid division by zero
    )

    const profitabilityBadge = getProfitabilityBadge()
    const ProfitabilityIcon = profitabilityBadge?.icon

    return (
        <div className="min-h-full">
            <AppNav brand={null} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-flex items-center gap-1"
                        >
                            <ArrowLeftIcon className="h-4 w-4" />
                            Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">{company.name}</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Company details, financials, and activity overview
                                </p>
                            </div>
                            {profitabilityBadge && ProfitabilityIcon && (
                                <span className={`inline-flex items-center rounded-full px-4 py-2 text-sm font-medium ${profitabilityBadge.className}`}>
                                    <ProfitabilityIcon className="h-5 w-5 mr-2" />
                                    {profitabilityBadge.label}
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Company Details Card */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Company Information</h2>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Plan</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{company.plan}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Owner</dt>
                                        <dd className="mt-1 text-sm text-gray-900">
                                            {company.owner ? (
                                                <>
                                                    {company.owner.name}
                                                    <span className="text-gray-500 ml-1">({company.owner.email})</span>
                                                </>
                                            ) : (
                                                'No owner assigned'
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Created</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{formatDate(company.created_at)}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Stripe Status</dt>
                                        <dd className="mt-1">
                                            {company.stripe_connected ? (
                                                <span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${
                                                    company.subscription_status === 'active' ? 'bg-green-100 text-green-800' :
                                                    company.subscription_status === 'trialing' ? 'bg-blue-100 text-blue-800' :
                                                    company.subscription_status === 'past_due' ? 'bg-orange-100 text-orange-800' :
                                                    'bg-gray-100 text-gray-800'
                                                }`}>
                                                    {company.subscription_status || 'Connected'}
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800">
                                                    Not Connected
                                                </span>
                                            )}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Total Users</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_users}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Total Brands</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_brands}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Total Assets</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_assets}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-sm font-medium text-gray-500">Storage Used</dt>
                                        <dd className="mt-1 text-sm text-gray-900">{stats.total_storage_gb} GB</dd>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Financial Overview */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Financial Overview</h2>
                                
                                {/* Current Month Summary */}
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
                                    <div className="rounded-lg bg-green-50 p-4">
                                        <dt className="text-sm font-medium text-green-800">Monthly Income</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-green-900">
                                            {formatCurrency(currentIncome?.total_income || 0)}
                                        </dd>
                                    </div>
                                    <div className="rounded-lg bg-red-50 p-4">
                                        <dt className="text-sm font-medium text-red-800">Monthly Costs</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-red-900">
                                            {formatCurrency(
                                                (currentCosts?.storage?.monthly_cost || 0) + 
                                                (currentCosts?.ai_agents?.total_cost || 0)
                                            )}
                                        </dd>
                                        <div className="mt-2 text-xs text-red-700">
                                            Storage: {formatCurrency(currentCosts?.storage?.monthly_cost || 0)}
                                            {currentCosts?.ai_agents?.total_cost > 0 && (
                                                <> • AI: {formatCurrency(currentCosts?.ai_agents?.total_cost || 0)}</>
                                            )}
                                        </div>
                                    </div>
                                    <div className={`rounded-lg p-4 ${
                                        profitability?.profit && profitability.profit > 0 
                                            ? 'bg-green-50' 
                                            : profitability?.profit && profitability.profit < 0
                                            ? 'bg-red-50'
                                            : 'bg-gray-50'
                                    }`}>
                                        <dt className={`text-sm font-medium ${
                                            profitability?.profit && profitability.profit > 0 
                                                ? 'text-green-800' 
                                                : profitability?.profit && profitability.profit < 0
                                                ? 'text-red-800'
                                                : 'text-gray-800'
                                        }`}>
                                            Net Profit
                                        </dt>
                                        <dd className={`mt-1 text-2xl font-semibold ${
                                            profitability?.profit && profitability.profit > 0 
                                                ? 'text-green-900' 
                                                : profitability?.profit && profitability.profit < 0
                                                ? 'text-red-900'
                                                : 'text-gray-900'
                                        }`}>
                                            {formatCurrency(profitability?.profit || 0)}
                                        </dd>
                                        {profitability?.margin_percent !== undefined && (
                                            <div className="mt-2 text-xs text-gray-600">
                                                Margin: {profitability.margin_percent}%
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* 6-Month Chart */}
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-900 mb-4">Last 6 Months</h3>
                                    <div className="overflow-x-auto">
                                        <div className="inline-block min-w-full">
                                            <div className="relative" style={{ height: `${chartHeight}px`, width: `${Math.max(chartWidth, monthlyData.length * 100)}px` }}>
                                                {/* Chart Bars */}
                                                <div className="absolute inset-0 flex items-end justify-between gap-2 px-4">
                                                    {monthlyData.map((data, index) => (
                                                        <div key={index} className="flex-1 flex flex-col items-center gap-1">
                                                            {/* Income Bar */}
                                                            <div className="w-full relative" style={{ height: `${chartHeight - 40}px` }}>
                                                                {data.income > 0 && (
                                                                    <div
                                                                        className="w-full bg-green-500 rounded-t absolute bottom-0 transition-all hover:bg-green-600"
                                                                        style={{
                                                                            height: `${(data.income / maxValue) * (chartHeight - 40)}px`,
                                                                        }}
                                                                        title={`Income: ${formatCurrency(data.income)}`}
                                                                    />
                                                                )}
                                                                {/* Cost Bar */}
                                                                {data.total_cost > 0 && (
                                                                    <div
                                                                        className="w-full bg-red-500 rounded-t absolute bottom-0 transition-all hover:bg-red-600 opacity-75"
                                                                        style={{
                                                                            height: `${(data.total_cost / maxValue) * (chartHeight - 40)}px`,
                                                                        }}
                                                                        title={`Costs: ${formatCurrency(data.total_cost)}`}
                                                                    />
                                                                )}
                                                            </div>
                                                            <div className="text-xs text-gray-600 text-center">
                                                                {data.month}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                                {/* Y-axis labels */}
                                                <div className="absolute left-0 top-0 bottom-10 flex flex-col justify-between text-xs text-gray-500">
                                                    <span>{formatCurrency(maxValue)}</span>
                                                    <span>{formatCurrency(maxValue / 2)}</span>
                                                    <span>$0</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    {/* Legend */}
                                    <div className="flex items-center justify-center gap-4 mt-4">
                                        <div className="flex items-center gap-2">
                                            <div className="h-3 w-3 bg-green-500 rounded"></div>
                                            <span className="text-xs text-gray-600">Income</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <div className="h-3 w-3 bg-red-500 rounded"></div>
                                            <span className="text-xs text-gray-600">Costs</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Activity, Users, Brands Teasers */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        {/* Recent Activity */}
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <ClockIcon className="h-5 w-5 text-gray-400" />
                                        Recent Activity
                                    </h2>
                                    <Link
                                        href={`/app/admin/activity-logs?tenant_id=${company.id}`}
                                        className="text-sm text-indigo-600 hover:text-indigo-900"
                                    >
                                        View All
                                    </Link>
                                </div>
                                {recentActivity && recentActivity.length > 0 ? (
                                    <div className="space-y-3">
                                        {recentActivity.slice(0, 5).map((activity) => (
                                            <div key={activity.id} className="text-sm">
                                                <p className="text-gray-900">{activity.description || activity.type}</p>
                                                <p className="text-xs text-gray-500 mt-1">{formatDate(activity.created_at)}</p>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">No recent activity</p>
                                )}
                            </div>
                        </div>

                        {/* Users */}
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <UsersIcon className="h-5 w-5 text-gray-400" />
                                        Users
                                    </h2>
                                    <Link
                                        href={`/app/admin?company_id=${company.id}`}
                                        className="text-sm text-indigo-600 hover:text-indigo-900"
                                    >
                                        View All ({stats.total_users})
                                    </Link>
                                </div>
                                {users && users.length > 0 ? (
                                    <div className="space-y-3">
                                        {users.map((user) => (
                                            <div key={user.id} className="text-sm">
                                                <p className="text-gray-900 font-medium">{user.name || user.email}</p>
                                                <div className="flex items-center gap-2 mt-1">
                                                    <span className="text-xs text-gray-500">{user.email}</span>
                                                    {user.is_owner && (
                                                        <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800">
                                                            Owner
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">No users</p>
                                )}
                            </div>
                        </div>

                        {/* Brands */}
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center justify-between mb-4">
                                    <h2 className="text-lg font-semibold text-gray-900 flex items-center gap-2">
                                        <TagIcon className="h-5 w-5 text-gray-400" />
                                        Brands
                                    </h2>
                                    <span className="text-sm text-gray-500">{stats.total_brands} total</span>
                                </div>
                                {brands && brands.length > 0 ? (
                                    <div className="space-y-3">
                                        {brands.map((brand) => (
                                            <div key={brand.id} className="text-sm">
                                                <p className="text-gray-900 font-medium">
                                                    {brand.name}
                                                    {brand.is_default && (
                                                        <span className="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800">
                                                            Default
                                                        </span>
                                                    )}
                                                </p>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-gray-500">No brands</p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* TODO Section */}
                    <div className="mt-8 text-xs text-gray-500 bg-gray-50 rounded-lg p-4">
                        <p className="font-semibold mb-2">TODO / Future Enhancements:</p>
                        <ul className="list-disc list-inside space-y-1">
                            <li>Add detailed cost breakdown by category (storage, AI, API, etc.)</li>
                            <li>Implement actual Stripe invoice fetching for accurate income data</li>
                            <li>Add export functionality for financial reports</li>
                            <li>Add time period selector for charts (monthly, quarterly, yearly)</li>
                            <li>Add alerts for companies with negative profitability</li>
                            <li>Add cost optimization recommendations</li>
                            <li>Add activity timeline with filtering</li>
                        </ul>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
