import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { CurrencyDollarIcon, ChartBarIcon } from '@heroicons/react/24/outline'

export default function AIReports({ report, filters, filterOptions, environment }) {
    const { auth } = usePage().props
    const [localFilters, setLocalFilters] = useState(filters || {})

    const applyFilters = (newFilters) => {
        const updatedFilters = { ...localFilters, ...newFilters }
        setLocalFilters(updatedFilters)
        router.get('/app/admin/ai/reports', updatedFilters, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const clearFilters = () => {
        setLocalFilters({})
        router.get('/app/admin/ai/reports', {}, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    {/* Header */}
                    <div className="mb-6">
                        <Link
                            href="/app/admin/ai"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block"
                        >
                            ← Back to AI Dashboard
                        </Link>
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Cost Reports</h1>
                                <p className="mt-2 text-sm text-gray-700">View detailed cost analysis and usage metrics</p>
                            </div>
                            <div className="text-sm text-gray-500">
                                Environment: <span className="font-medium text-gray-900">{environment}</span>
                            </div>
                        </div>
                    </div>

                    {/* Summary Cards */}
                    <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <div className="flex items-center">
                                <CurrencyDollarIcon className="h-6 w-6 text-gray-400" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-gray-500">Total Cost</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        ${(report?.total_cost || 0).toFixed(4)}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <div className="flex items-center">
                                <ChartBarIcon className="h-6 w-6 text-gray-400" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-gray-500">Total Runs</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        {report?.total_runs || 0}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <div className="flex items-center">
                                <ChartBarIcon className="h-6 w-6 text-gray-400" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-gray-500">Avg Cost/Run</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        ${(report?.average_cost_per_run || 0).toFixed(4)}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                            <div className="flex items-center">
                                <ChartBarIcon className="h-6 w-6 text-gray-400" />
                                <div className="ml-4">
                                    <p className="text-sm font-medium text-gray-500">Error Rate</p>
                                    <p className="mt-1 text-2xl font-semibold text-gray-900">
                                        {(report?.error_rate || 0).toFixed(1)}%
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="mb-6 bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-4">
                        <div className="flex flex-wrap items-center gap-3">
                            <div className="flex-shrink-0">
                                <label className="block text-xs font-medium text-gray-700 mb-1">Start Date</label>
                                <input
                                    type="date"
                                    value={localFilters.start_date || ''}
                                    onChange={(e) => applyFilters({ start_date: e.target.value || null })}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                />
                            </div>
                            <div className="flex-shrink-0">
                                <label className="block text-xs font-medium text-gray-700 mb-1">End Date</label>
                                <input
                                    type="date"
                                    value={localFilters.end_date || ''}
                                    onChange={(e) => applyFilters({ end_date: e.target.value || null })}
                                    className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                />
                            </div>
                            <div className="flex-shrink-0">
                                <label className="block text-xs font-medium text-gray-700 mb-1">Agent</label>
                                <select
                                    value={localFilters.agent_id || ''}
                                    onChange={(e) => applyFilters({ agent_id: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                >
                                    <option value="">All Agents</option>
                                    {filterOptions?.agents?.map((agent) => (
                                        <option key={agent.value} value={agent.value}>
                                            {agent.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex-shrink-0">
                                <label className="block text-xs font-medium text-gray-700 mb-1">Model</label>
                                <select
                                    value={localFilters.model_used || ''}
                                    onChange={(e) => applyFilters({ model_used: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                >
                                    <option value="">All Models</option>
                                    {filterOptions?.models?.map((model) => (
                                        <option key={model.value} value={model.value}>
                                            {model.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex-shrink-0">
                                <button
                                    onClick={clearFilters}
                                    className="mt-6 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                >
                                    Clear Filters
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Aggregations */}
                    <div className="space-y-6">
                        {/* By Agent */}
                        {report?.aggregations?.by_agent && report.aggregations.by_agent.length > 0 && (
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-6 py-4 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Cost by Agent</h2>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Agent</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Runs</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Cost</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {report.aggregations.by_agent.map((item, idx) => (
                                                <tr key={idx}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.agent_id}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_runs}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${item.total_cost.toFixed(4)}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_tokens.toLocaleString()}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {/* By Model */}
                        {report?.aggregations?.by_model && report.aggregations.by_model.length > 0 && (
                            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                                <div className="px-6 py-4 border-b border-gray-200">
                                    <h2 className="text-lg font-semibold text-gray-900">Cost by Model</h2>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Model</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Runs</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Cost</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Cost/Run</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {report.aggregations.by_model.map((item, idx) => (
                                                <tr key={idx}>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.model_used}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_runs}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${item.total_cost.toFixed(4)}</td>
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${item.average_cost_per_run.toFixed(4)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
