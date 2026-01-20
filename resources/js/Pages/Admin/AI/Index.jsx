import { Link, usePage, router } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import BudgetStatusBadge from '../../../Components/AI/BudgetStatusBadge'
import CostTrendIndicator from '../../../Components/AI/CostTrendIndicator'
import {
    ActivityTabContent,
    ModelsTabContent,
    AgentsTabContent,
    AutomationsTabContent,
    ReportsTabContent,
    BudgetsTabContent,
    AlertsTabContent,
} from '../../../Components/AI/TabContent'
import {
    BoltIcon,
    SparklesIcon,
    ClockIcon,
    CheckCircleIcon,
    XCircleIcon,
    CurrencyDollarIcon,
    ExclamationTriangleIcon,
    ChartBarIcon,
} from '@heroicons/react/24/outline'

export default function AIDashboardIndex({ stats, environment, canManage, budgetStatus, budgetRemaining, currentMonthCost, costTrends, costSpikes, canViewBudgets, activeTab: initialTab = 'activity', tabContent = {} }) {
    const { auth } = usePage().props
    const [activeTab, setActiveTab] = useState(initialTab || 'activity')

    const tabs = [
        { id: 'activity', name: 'Activity' },
        { id: 'models', name: 'Models' },
        { id: 'agents', name: 'Agents' },
        { id: 'automations', name: 'Automations' },
        { id: 'reports', name: 'Reports' },
        { id: 'alerts', name: 'Alerts' },
        ...(canViewBudgets ? [{ id: 'budgets', name: 'Budgets' }] : []),
    ]

    // Sync activeTab when initialTab prop changes (from URL parameter)
    useEffect(() => {
        if (initialTab && initialTab !== activeTab) {
            setActiveTab(initialTab)
        }
    }, [initialTab])

    const handleTabChange = (tabId) => {
        setActiveTab(tabId)
        router.get('/app/admin/ai', { tab: tabId }, {
            preserveState: true,
            preserveScroll: true,
            only: ['tabContent', 'activeTab'],
        })
    }

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
            subtitle: 'Estimated AI usage cost',
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

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-8">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block"
                        >
                            ← Back to Admin Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Dashboard</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Observe and manage AI operations across the system
                                </p>
                            </div>
                            <div className="text-sm text-gray-500">
                                Environment: <span className="font-medium text-gray-900">{environment}</span>
                            </div>
                        </div>
                    </div>

                    {/* Summary Stats */}
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

                    {/* Cost Overview & Budget Status */}
                    {canViewBudgets && budgetStatus && (
                        <div className="mb-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900 mb-4">Cost Overview & Budget Status</h2>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Current Month Cost</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        ${(currentMonthCost || 0).toFixed(4)}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Budget Status</p>
                                    <div className="mt-1">
                                        <BudgetStatusBadge status={budgetStatus} />
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-gray-500">Budget Remaining</p>
                                    <p className={`mt-1 text-2xl font-semibold ${(budgetRemaining || 0) < 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                        ${(budgetRemaining || 0).toFixed(2)}
                                    </p>
                                </div>
                                {costTrends && costTrends.length >= 2 && (
                                    <div>
                                        <p className="text-sm font-medium text-gray-500">Cost Trend (7 days)</p>
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
                                            <h3 className="text-sm font-medium text-yellow-800">Cost Spike Detected</h3>
                                            <p className="mt-1 text-sm text-yellow-700">
                                                Unusual cost increase detected: {costSpikes[0].percent_increase.toFixed(1)}% increase in {costSpikes[0].period}
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
                                                {budgetStatus === 'over' ? 'Budget Exceeded' : 'Budget Warning'}
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

                    {/* Tabs */}
                    <div className="mb-6 border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8" aria-label="Tabs">
                            {tabs.map((tab) => (
                                <button
                                    key={tab.id}
                                    onClick={() => handleTabChange(tab.id)}
                                    className={`
                                        whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium
                                        ${
                                            activeTab === tab.id
                                                ? 'border-indigo-500 text-indigo-600'
                                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                        }
                                    `}
                                >
                                    {tab.name}
                                </button>
                            ))}
                        </nav>
                    </div>

                    {/* Tab Content */}
                    <div className="mb-6">
                            {activeTab === 'activity' && (
                                tabContent.activity ? (
                                    <ActivityTabContent 
                                        runs={tabContent.activity.runs}
                                        failedJobs={tabContent.activity.failedJobs}
                                        filterOptions={tabContent.activity.filterOptions}
                                        canManage={canManage}
                                    />
                                ) : (
                                    <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                        <p className="text-sm text-gray-500">Loading Activity...</p>
                                    </div>
                                )
                            )}
                        {activeTab === 'models' && (
                            tabContent.models ? (
                                <ModelsTabContent 
                                    models={tabContent.models.models}
                                    environment={environment}
                                    canManage={canManage}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading Models...</p>
                                </div>
                            )
                        )}
                        {activeTab === 'agents' && (
                            tabContent.agents ? (
                                <AgentsTabContent 
                                    agents={tabContent.agents.agents}
                                    availableModels={tabContent.agents.availableModels}
                                    environment={environment}
                                    canManage={canManage}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading Agents...</p>
                                </div>
                            )
                        )}
                        {activeTab === 'automations' && (
                            tabContent.automations ? (
                                <AutomationsTabContent 
                                    automations={tabContent.automations.automations}
                                    environment={environment}
                                    canManage={canManage}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading Automations...</p>
                                </div>
                            )
                        )}
                        {activeTab === 'reports' && (
                            tabContent.reports ? (
                                <ReportsTabContent 
                                    report={tabContent.reports.report}
                                    filters={tabContent.reports.filters}
                                    filterOptions={tabContent.reports.filterOptions}
                                    environment={environment}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading Reports...</p>
                                </div>
                            )
                        )}
                        {activeTab === 'alerts' && (
                            tabContent.alerts ? (
                                <AlertsTabContent 
                                    alerts={tabContent.alerts.alerts}
                                    filterOptions={tabContent.alerts.filterOptions}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading Alerts...</p>
                                </div>
                            )
                        )}
                        {activeTab === 'budgets' && (
                            tabContent.budgets ? (
                                <BudgetsTabContent 
                                    budgets={tabContent.budgets.budgets}
                                    environment={environment}
                                    canManage={canManage && Array.isArray(auth.permissions) && auth.permissions.includes('ai.budgets.manage')}
                                />
                            ) : (
                                <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                                    <p className="text-sm text-gray-500">Loading Budgets...</p>
                                </div>
                            )
                        )}
                    </div>

                    {/* Info Message */}
                    <div className="rounded-lg bg-blue-50 p-4 ring-1 ring-blue-200">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <SparklesIcon className="h-5 w-5 text-blue-400" />
                            </div>
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-blue-800">AI Control Plane</h3>
                                <div className="mt-2 text-sm text-blue-700">
                                    <p>
                                        This dashboard provides observability and management for all AI operations.
                                        {!canManage && (
                                            <span className="font-medium"> You have read-only access.</span>
                                        )}
                                    </p>
                                    {canManage && (
                                        <p className="mt-1">
                                            Changes to overrides will apply to future AI executions only. Historical
                                            runs remain unchanged.
                                        </p>
                                    )}
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
