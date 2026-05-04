import { router } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AdminAiCenterPage from '../../../Components/Admin/AdminAiCenterPage'
import AIReportsQuickFilters from '../../../Components/Admin/AIReportsQuickFilters'
import { CurrencyDollarIcon, ChartBarIcon } from '@heroicons/react/24/outline'
import {
    mergeAiReportFilters,
    serializeAiReportFilters,
    formatAiReportRangeSubtitle,
} from '../../../utils/aiReportsFilters'

export default function AIReports({ report, filters, filterOptions, environment }) {
    const [localFilters, setLocalFilters] = useState(filters || {})

    useEffect(() => {
        setLocalFilters(filters || {})
    }, [filters])

    const navigateWithFilters = (next) => {
        setLocalFilters(next)
        router.get('/app/admin/ai/reports', serializeAiReportFilters(next), {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const applyFilters = (patch) => {
        navigateWithFilters(mergeAiReportFilters(localFilters, patch))
    }

    const clearFilters = () => {
        router.get(
            '/app/admin/ai/reports',
            {},
            {
                preserveState: true,
                preserveScroll: true,
            },
        )
    }

    const rangeSubtitle = formatAiReportRangeSubtitle(report?.meta, localFilters)

    return (
        <AdminAiCenterPage
            breadcrumbs={[
                { label: 'Admin', href: '/app/admin' },
                { label: 'AI Control Center', href: '/app/admin/ai' },
                { label: 'Reports' },
            ]}
            title="Reports"
            description="Detailed cost analysis and usage metrics."
            technicalNote={
                <p className="mt-2 text-xs text-slate-500">
                    Environment: <span className="font-medium text-slate-800">{environment}</span>
                </p>
            }
        >
            <AIReportsQuickFilters
                localFilters={localFilters}
                filterOptions={filterOptions}
                activePresetId={localFilters.range_preset || null}
                onApplyPreset={(id) => applyFilters({ range_preset: id })}
                onAgentChange={(v) => applyFilters({ agent_id: v || null })}
                onModelChange={(v) => applyFilters({ model_used: v || null })}
            />

            {rangeSubtitle ? (
                <p className="-mt-1 mb-4 text-sm text-slate-600">
                    <span className="font-medium text-slate-800">Summary period:</span> {rangeSubtitle}
                </p>
            ) : null}

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
                            <p className="mt-1 text-2xl font-semibold text-gray-900">{report?.total_runs || 0}</p>
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

            {/* Advanced: custom calendar range */}
            <div className="mb-6 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200">
                <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Advanced — custom date range
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex-shrink-0">
                        <label className="mb-1 block text-xs font-medium text-gray-700">Start date</label>
                        <input
                            type="date"
                            value={localFilters.start_date || ''}
                            onChange={(e) => applyFilters({ start_date: e.target.value || null })}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                    <div className="flex-shrink-0">
                        <label className="mb-1 block text-xs font-medium text-gray-700">End date</label>
                        <input
                            type="date"
                            value={localFilters.end_date || ''}
                            onChange={(e) => applyFilters({ end_date: e.target.value || null })}
                            className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        />
                    </div>
                    <div className="flex-shrink-0">
                        <button
                            type="button"
                            onClick={clearFilters}
                            className="mt-6 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                        >
                            Reset to defaults
                        </button>
                    </div>
                </div>
            </div>

            {/* Aggregations */}
            <div className="space-y-6">
                {/* By Agent */}
                {report?.aggregations?.by_agent && report.aggregations.by_agent.length > 0 && (
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="border-b border-gray-200 px-6 py-4">
                            <h2 className="text-lg font-semibold text-gray-900">Cost by Agent</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Agent
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Runs
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Total Cost
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Tokens
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {report.aggregations.by_agent.map((item, idx) => (
                                        <tr key={idx}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {item.agent_id}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {item.total_runs}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                ${item.total_cost.toFixed(4)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {item.total_tokens.toLocaleString()}
                                            </td>
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
                        <div className="border-b border-gray-200 px-6 py-4">
                            <h2 className="text-lg font-semibold text-gray-900">Cost by Model</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Model
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Runs
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Total Cost
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Avg Cost/Run
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200 bg-white">
                                    {report.aggregations.by_model.map((item, idx) => (
                                        <tr key={idx}>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                                {item.model_used}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                {item.total_runs}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                                ${item.total_cost.toFixed(4)}
                                            </td>
                                            <td className="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                                ${item.average_cost_per_run.toFixed(4)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AdminAiCenterPage>
    )
}
