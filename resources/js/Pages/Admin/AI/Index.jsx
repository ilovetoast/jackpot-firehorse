import { usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminAiSectionSidebar from '../../../Components/Admin/AdminAiSectionSidebar'
import BudgetStatusBadge from '../../../Components/AI/BudgetStatusBadge'
import CostTrendIndicator from '../../../Components/AI/CostTrendIndicator'
import { ActivityTabContent, AlertsTabContent } from '../../../Components/AI/TabContent'
import {
    BoltIcon,
    SparklesIcon,
    CheckCircleIcon,
    CurrencyDollarIcon,
    ExclamationTriangleIcon,
} from '@heroicons/react/24/outline'

export default function AIDashboardIndex({
    stats,
    environment,
    canManage,
    budgetStatus,
    budgetRemaining,
    currentMonthCost,
    costTrends,
    costSpikes,
    canViewBudgets,
    activeTab: initialTab = 'overview',
    tabContent = {},
}) {
    const { auth } = usePage().props
    const activeTab = initialTab === 'alerts' ? 'alerts' : 'overview'

    const summaryCards = [
        {
            name: 'Total Runs',
            value: stats.total_runs || 0,
            subtitle: `${stats.successful_runs || 0} successful, ${stats.failed_runs || 0} failed`,
            icon: BoltIcon,
        },
        {
            name: 'Total Cost',
            value: `$${Number(stats.total_cost || 0).toFixed(4)}`,
            subtitle: 'Sum of run estimates (incl. Studio video COGS from config) — not vendor invoices',
            icon: CurrencyDollarIcon,
        },
        {
            name: 'Tokens Used',
            value: ((stats.total_tokens_in || 0) + (stats.total_tokens_out || 0)).toLocaleString(),
            subtitle: `${(stats.total_tokens_in || 0).toLocaleString()} in, ${(stats.total_tokens_out || 0).toLocaleString()} out`,
            icon: SparklesIcon,
        },
        {
            name: 'Success Rate',
            value: stats.total_runs > 0
                ? `${Math.round(((stats.successful_runs || 0) / stats.total_runs) * 100)}%`
                : 'N/A',
            subtitle: 'Successful executions',
            icon: CheckCircleIcon,
        },
    ]

    const crumbLabel = activeTab === 'alerts' ? 'Alerts' : 'Overview'

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="ai"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'AI Control Center', href: '/app/admin/ai' },
                        { label: crumbLabel },
                    ]}
                    title="AI Control Center"
                    description="What AI ran, what it cost, and how the platform is configured to behave."
                    technicalNote={
                        <p className="mt-2 text-xs text-slate-500">
                            Environment: <span className="font-medium text-slate-800">{environment}</span>
                        </p>
                    }
                    sidebar={<AdminAiSectionSidebar />}
                >
                    {activeTab === 'overview' && (
                        <>
                            <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {summaryCards.map((card) => {
                                    const IconComponent = card.icon
                                    return (
                                        <div
                                            key={card.name}
                                            className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200"
                                        >
                                            <div className="flex items-center">
                                                <div className="flex-shrink-0">
                                                    <IconComponent className="h-6 w-6 text-gray-400" />
                                                </div>
                                                <div className="ml-4 flex-1">
                                                    <p className="text-sm font-medium text-gray-500">{card.name}</p>
                                                    <p className="mt-1 text-2xl font-semibold text-gray-900">{card.value}</p>
                                                    <p className="mt-1 text-xs text-gray-500">{card.subtitle}</p>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                })}
                            </div>

                            {canViewBudgets && budgetStatus && (
                                <div className="mb-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900 mb-4">Cost overview &amp; budget status</h2>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Current month cost</p>
                                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                                ${Number(currentMonthCost ?? 0).toFixed(4)}
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Budget status</p>
                                            <div className="mt-1">
                                                <BudgetStatusBadge status={budgetStatus} />
                                            </div>
                                        </div>
                                        <div>
                                            <p className="text-sm font-medium text-gray-500">Budget remaining</p>
                                            <p className={`mt-1 text-2xl font-semibold ${Number(budgetRemaining ?? 0) < 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                                ${Number(budgetRemaining ?? 0).toFixed(2)}
                                            </p>
                                        </div>
                                        {costTrends && costTrends.length >= 2 && (
                                            <div>
                                                <p className="text-sm font-medium text-gray-500">Cost trend (7 days)</p>
                                                <div className="mt-1">
                                                    <CostTrendIndicator
                                                        trend={costTrends[costTrends.length - 1]?.total_cost > costTrends[costTrends.length - 2]?.total_cost ? 'up' : 'down'}
                                                        percentChange={
                                                            costTrends[costTrends.length - 2]?.total_cost > 0
                                                                ? ((costTrends[costTrends.length - 1]?.total_cost - costTrends[costTrends.length - 2]?.total_cost) / costTrends[costTrends.length - 2]?.total_cost) * 100
                                                                : 0
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                    {costSpikes && costSpikes.length > 0 && (
                                        <div className="mt-4 rounded-md bg-yellow-50 p-4 ring-1 ring-yellow-200">
                                            <div className="flex">
                                                <ExclamationTriangleIcon className="h-5 w-5 text-yellow-400" />
                                                <div className="ml-3">
                                                    <h3 className="text-sm font-medium text-yellow-800">Cost spike detected</h3>
                                                    <p className="mt-1 text-sm text-yellow-700">
                                                        Unusual cost increase detected: {Number(costSpikes[0].percent_increase ?? 0).toFixed(1)}% increase in {costSpikes[0].period}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                    {(budgetStatus === 'over' || budgetStatus === 'warning') && (
                                        <div className={`mt-4 rounded-md p-4 ring-1 ${budgetStatus === 'over' ? 'bg-red-50 ring-red-200' : 'bg-yellow-50 ring-yellow-200'}`}>
                                            <div className="flex">
                                                <ExclamationTriangleIcon className={`h-5 w-5 ${budgetStatus === 'over' ? 'text-red-400' : 'text-yellow-400'}`} />
                                                <div className="ml-3">
                                                    <h3 className={`text-sm font-medium ${budgetStatus === 'over' ? 'text-red-800' : 'text-yellow-800'}`}>
                                                        {budgetStatus === 'over' ? 'Budget exceeded' : 'Budget warning'}
                                                    </h3>
                                                    <p className={`mt-1 text-sm ${budgetStatus === 'over' ? 'text-red-700' : 'text-yellow-700'}`}>
                                                        {budgetStatus === 'over'
                                                            ? 'System-wide budget has been exceeded. Consider reviewing AI usage or increasing budget limits.'
                                                            : 'System-wide budget is approaching the limit. Monitor usage closely.'}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            <div className="mb-6">
                                {tabContent.activity ? (
                                    <ActivityTabContent
                                        variant="preview"
                                        runs={tabContent.activity.runs}
                                        failedJobs={tabContent.activity.failedJobs}
                                        filterOptions={tabContent.activity.filterOptions}
                                        canManage={canManage}
                                    />
                                ) : (
                                    <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                        <p className="text-sm text-gray-500">Loading activity preview…</p>
                                    </div>
                                )}
                            </div>
                        </>
                    )}

                    {activeTab === 'alerts' && (
                        <div className="mb-6">
                            {tabContent.alerts ? (
                                <AlertsTabContent
                                    alerts={tabContent.alerts.alerts}
                                    filterOptions={tabContent.alerts.filterOptions}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading alerts…</p>
                                </div>
                            )}
                        </div>
                    )}

                    <details className="mt-8 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-600">
                        <summary className="cursor-pointer font-medium text-slate-800">Access &amp; overrides</summary>
                        <p className="mt-2">
                            {!canManage ? 'You have read-only access to this area.' : 'Overrides apply to future runs only; history is unchanged.'}
                        </p>
                    </details>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
