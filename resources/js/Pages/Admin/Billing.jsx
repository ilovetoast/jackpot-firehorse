import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import {
    ChartBarIcon,
    ArrowLeftIcon,
    CurrencyDollarIcon,
    ServerIcon,
    CreditCardIcon,
    BuildingOfficeIcon,
} from '@heroicons/react/24/outline'

export default function AdminBilling({ 
    summary,
    monthlyData,
    infrastructureExpenses,
    compedAccounts
}) {
    // Format currency
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: 'USD',
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount || 0)
    }

    // Calculate chart dimensions
    const chartHeight = 300
    const chartWidth = Math.max(800, monthlyData.length * 60)
    const maxValue = Math.max(
        ...monthlyData.map(d => Math.max(d.income, d.total_expenses)),
        1 // Minimum 1 to avoid division by zero
    )

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
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Billing Overview</h1>
                        <p className="mt-2 text-sm text-gray-700">
                            Aggregate income, expenses, and financial reports for all companies
                        </p>
                    </div>

                    {/* Summary Cards */}
                    <div className="mb-8 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <CreditCardIcon className="h-8 w-8 text-green-600" />
                                    </div>
                                    <div className="ml-4">
                                        <dt className="text-sm font-medium text-gray-500">Monthly Income</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-green-900">
                                            {formatCurrency(summary.total_monthly_income)}
                                        </dd>
                                    </div>
                                </div>
                                <div className="mt-4 text-xs text-gray-500">
                                    {summary.paid_accounts} paid • {summary.trial_accounts || 0} trial • {summary.comped_accounts || 0} comped
                                </div>
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <CurrencyDollarIcon className="h-8 w-8 text-red-600" />
                                    </div>
                                    <div className="ml-4">
                                        <dt className="text-sm font-medium text-gray-500">Monthly Expenses</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-red-900">
                                            {formatCurrency(summary.total_monthly_costs + summary.total_infrastructure_expenses)}
                                        </dd>
                                    </div>
                                </div>
                                <div className="mt-4 text-xs text-gray-500">
                                    Company: {formatCurrency(summary.total_monthly_costs)} • Infrastructure: {formatCurrency(summary.total_infrastructure_expenses)}
                                </div>
                            </div>
                        </div>

                        <div className={`overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200`}>
                            <div className="p-6">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <ChartBarIcon className={`h-8 w-8 ${
                                            summary.net_profit >= 0 ? 'text-green-600' : 'text-red-600'
                                        }`} />
                                    </div>
                                    <div className="ml-4">
                                        <dt className="text-sm font-medium text-gray-500">Net Profit</dt>
                                        <dd className={`mt-1 text-2xl font-semibold ${
                                            summary.net_profit >= 0 ? 'text-green-900' : 'text-red-900'
                                        }`}>
                                            {formatCurrency(summary.net_profit)}
                                        </dd>
                                    </div>
                                </div>
                                <div className="mt-4 text-xs text-gray-500">
                                    Margin: {summary.margin_percent}%
                                </div>
                            </div>
                        </div>

                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <div className="flex items-center">
                                    <div className="flex-shrink-0">
                                        <ServerIcon className="h-8 w-8 text-gray-600" />
                                    </div>
                                    <div className="ml-4">
                                        <dt className="text-sm font-medium text-gray-500">Total Accounts</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-gray-900">
                                            {summary.total_accounts}
                                        </dd>
                                    </div>
                                </div>
                                <div className="mt-4 text-xs text-gray-500">
                                    {summary.paid_accounts} paid • {summary.trial_accounts || 0} trial • {summary.comped_accounts || 0} comped
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Income vs Expenses Chart */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Income vs Expenses (Last 12 Months)</h2>
                                
                                <div className="overflow-x-auto">
                                    <div className="inline-block min-w-full">
                                        <div className="relative" style={{ height: `${chartHeight}px`, width: `${chartWidth}px` }}>
                                            {/* Chart Bars */}
                                            <div className="absolute inset-0 flex items-end justify-between gap-2 px-4">
                                                {monthlyData.map((data, index) => (
                                                    <div key={index} className="flex-1 flex flex-col items-center gap-1">
                                                        {/* Income Bar */}
                                                        <div className="w-full relative" style={{ height: `${chartHeight - 60}px` }}>
                                                            {data.income > 0 && (
                                                                <div
                                                                    className="w-full bg-green-500 rounded-t absolute bottom-0 transition-all hover:bg-green-600 opacity-90"
                                                                    style={{
                                                                        height: `${(data.income / maxValue) * (chartHeight - 60)}px`,
                                                                        width: '48%',
                                                                        left: '0%',
                                                                    }}
                                                                    title={`Income: ${formatCurrency(data.income)}`}
                                                                />
                                                            )}
                                                            {/* Expenses Bar */}
                                                            {data.total_expenses > 0 && (
                                                                <div
                                                                    className="w-full bg-red-500 rounded-t absolute bottom-0 transition-all hover:bg-red-600 opacity-90"
                                                                    style={{
                                                                        height: `${(data.total_expenses / maxValue) * (chartHeight - 60)}px`,
                                                                        width: '48%',
                                                                        right: '0%',
                                                                    }}
                                                                    title={`Expenses: ${formatCurrency(data.total_expenses)}`}
                                                                />
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-gray-600 text-center mt-2">
                                                            {data.month}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                            {/* Y-axis labels */}
                                            <div className="absolute left-0 top-0 bottom-16 flex flex-col justify-between text-xs text-gray-500">
                                                <span>{formatCurrency(maxValue)}</span>
                                                <span>{formatCurrency(maxValue / 2)}</span>
                                                <span>$0</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                {/* Legend */}
                                <div className="flex items-center justify-center gap-4 mt-6">
                                    <div className="flex items-center gap-2">
                                        <div className="h-3 w-3 bg-green-500 rounded"></div>
                                        <span className="text-xs text-gray-600">Income</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="h-3 w-3 bg-red-500 rounded"></div>
                                        <span className="text-xs text-gray-600">Expenses</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Infrastructure Expenses Breakdown */}
                    <div className="mb-8">
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <div className="p-6">
                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Infrastructure Expenses</h2>
                                <p className="text-sm text-gray-600 mb-4">
                                    TODO: Integrate with AWS Cost Explorer API and other service providers
                                </p>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">AWS / S3 Storage</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.aws_s3)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Query AWS Cost Explorer API</dd>
                                    </div>
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">Cloud Compute</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.cloud_compute)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Query EC2, RDS, Lambda costs</dd>
                                    </div>
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">Email Services</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.email_services)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Query email provider costs</dd>
                                    </div>
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">Monitoring/Logging</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.monitoring_logging)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Query CloudWatch, Datadog, etc.</dd>
                                    </div>
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">CI/CD</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.cicd)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Query GitHub Actions, CircleCI, etc.</dd>
                                    </div>
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">SaaS Tools</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.saas_tools)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Query SaaS vendor costs</dd>
                                    </div>
                                    <div className="rounded-lg bg-gray-50 p-4">
                                        <dt className="text-sm font-medium text-gray-700">Contractors</dt>
                                        <dd className="mt-1 text-lg font-semibold text-gray-900">
                                            {formatCurrency(infrastructureExpenses.contractors)}
                                        </dd>
                                        <dd className="mt-1 text-xs text-gray-500">TODO: Track contractor payments</dd>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Equivalent Plan Value (Sales Insight Only) */}
                    {summary.total_equivalent_plan_value > 0 && (
                        <div className="mb-8">
                            <div className="overflow-hidden rounded-lg bg-blue-50 shadow-sm ring-1 ring-blue-200">
                                <div className="p-6">
                                    <h2 className="text-lg font-semibold text-blue-900 mb-2">Sales Insight</h2>
                                    <p className="text-sm text-blue-800 mb-4">
                                        <strong>Important:</strong> Equivalent plan value is for sales insights only. 
                                        This is NOT real revenue and should NOT be included in accounting calculations.
                                    </p>
                                    <div className="rounded-lg bg-white p-4">
                                        <dt className="text-sm font-medium text-blue-700">Total Equivalent Plan Value</dt>
                                        <dd className="mt-1 text-2xl font-semibold text-blue-900">
                                            {formatCurrency(summary.total_equivalent_plan_value)}
                                        </dd>
                                        <dd className="mt-2 text-xs text-blue-600">
                                            This represents the value of comped accounts if they were paying customers. 
                                            Use for sales forecasting only, not revenue recognition.
                                        </dd>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* TODO Section */}
                    <div className="mt-8 text-xs text-gray-500 bg-gray-50 rounded-lg p-4">
                        <p className="font-semibold mb-2">TODO / Future Enhancements:</p>
                        <ul className="list-disc list-inside space-y-1">
                            <li>Integrate AWS Cost Explorer API for actual S3 and compute costs</li>
                            <li>Query Stripe invoices directly for accurate income data (currently estimated)</li>
                            <li>Add expense tracking for email services (SendGrid, SES, etc.)</li>
                            <li>Add expense tracking for monitoring/logging (CloudWatch, Datadog, New Relic, etc.)</li>
                            <li>Add expense tracking for CI/CD platforms (GitHub Actions, CircleCI, etc.)</li>
                            <li>Add expense tracking for SaaS tools (Intercom, analytics tools, etc.)</li>
                            <li>Add contractor payment tracking (separate from employee costs)</li>
                            <li>Add export functionality for financial reports</li>
                            <li>Add time period selector for charts (monthly, quarterly, yearly)</li>
                            <li>Add cost allocation per company for infrastructure expenses</li>
                            <li>Add alerts for negative profitability trends</li>
                            <li>Add expiration date handling for trial/comped accounts</li>
                            <li>Add UI to set equivalent_plan_value when creating comped accounts</li>
                        </ul>
                        <p className="mt-3 font-semibold">Accounting Rules:</p>
                        <ul className="list-disc list-inside space-y-1">
                            <li><strong>billing_status</strong> values: null/'paid' (revenue), 'trial' ($0 revenue), 'comped' ($0 revenue)</li>
                            <li><strong>Comped accounts:</strong> No invoices, no revenue, expenses still apply</li>
                            <li><strong>Trial accounts:</strong> No revenue during trial, expenses still apply</li>
                            <li><strong>Expenses:</strong> Deduct AWS/S3, Compute, SaaS tools, Contractors from all accounts</li>
                            <li><strong>equivalent_plan_value:</strong> Sales insight only - do NOT count as revenue</li>
                            <li><strong>Do NOT:</strong> Invent revenue or discounts - only count actual Stripe income</li>
                            <li><strong>billing_status</strong> is NOT frontend-facing (internal accounting only)</li>
                        </ul>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
