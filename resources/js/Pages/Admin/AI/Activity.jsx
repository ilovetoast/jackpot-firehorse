import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    BoltIcon,
    CheckCircleIcon,
    XCircleIcon,
    XMarkIcon,
} from '@heroicons/react/24/outline'

export default function AIActivity({ runs, filters, filterOptions }) {
    const { auth } = usePage().props
    const [localFilters, setLocalFilters] = useState(filters || {})

    const applyFilters = (newFilters) => {
        const updatedFilters = { ...localFilters, ...newFilters }
        setLocalFilters(updatedFilters)
        router.get('/app/admin/ai/activity', updatedFilters, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const clearFilters = () => {
        setLocalFilters({})
        router.get('/app/admin/ai/activity', {}, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const hasActiveFilters = Object.values(localFilters).some((v) => v !== null && v !== '' && v !== undefined)

    const getStatusBadge = (status) => {
        if (status === 'success') {
            return (
                <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-100 text-green-800">
                    <CheckCircleIcon className="h-3 w-3 mr-1" />
                    Success
                </span>
            )
        }
        return (
            <span className="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-red-100 text-red-800">
                <XCircleIcon className="h-3 w-3 mr-1" />
                Failed
            </span>
        )
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
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Activity</h1>
                        <p className="mt-2 text-sm text-gray-700">View all AI agent executions across the system</p>
                    </div>

                    {/* Filters */}
                    <div className="mb-6 bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-4">
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Agent Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.agent_id || ''}
                                    onChange={(e) => applyFilters({ agent_id: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Agents</option>
                                    {filterOptions?.agents?.map((agent) => (
                                        <option key={agent.value} value={agent.value}>
                                            {agent.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Model Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.model_used || ''}
                                    onChange={(e) => applyFilters({ model_used: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Models</option>
                                    {filterOptions?.models?.map((model) => (
                                        <option key={model.value} value={model.value}>
                                            {model.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Task Type Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.task_type || ''}
                                    onChange={(e) => applyFilters({ task_type: e.target.value || null })}
                                    className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Task Types</option>
                                    {filterOptions?.task_types?.map((task) => (
                                        <option key={task.value} value={task.value}>
                                            {task.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Status Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.status || ''}
                                    onChange={(e) => applyFilters({ status: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Statuses</option>
                                    <option value="success">Success</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>

                            {/* Environment Filter */}
                            <div className="flex-shrink-0">
                                <select
                                    value={localFilters.environment || ''}
                                    onChange={(e) => applyFilters({ environment: e.target.value || null })}
                                    className="block w-full min-w-[140px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                >
                                    <option value="">All Environments</option>
                                    {filterOptions?.environments?.map((env) => (
                                        <option key={env.value} value={env.value}>
                                            {env.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Date From */}
                            <div className="flex-shrink-0">
                                <input
                                    type="date"
                                    value={localFilters.date_from || ''}
                                    onChange={(e) => applyFilters({ date_from: e.target.value || null })}
                                    className="block w-full min-w-[150px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                    placeholder="Date From"
                                />
                            </div>

                            {/* Date To */}
                            <div className="flex-shrink-0">
                                <input
                                    type="date"
                                    value={localFilters.date_to || ''}
                                    onChange={(e) => applyFilters({ date_to: e.target.value || null })}
                                    className="block w-full min-w-[150px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                                    placeholder="Date To"
                                />
                            </div>

                            {/* Clear Filters */}
                            {hasActiveFilters && (
                                <div className="flex-shrink-0 ml-auto">
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="inline-flex items-center rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                    >
                                        <XMarkIcon className="h-4 w-4 mr-1.5" />
                                        Clear
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Table */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Timestamp
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Agent
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Task Type
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Model
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Tokens
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Cost
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Related
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {runs.data && runs.data.length > 0 ? (
                                    runs.data.map((run) => (
                                        <tr key={run.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {run.timestamp}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <div className="text-sm font-medium text-gray-900">{run.agent_name}</div>
                                                <div className="text-xs text-gray-500">{run.agent_id}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {run.task_type}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {run.model_used}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div>In: {run.tokens_in.toLocaleString()}</div>
                                                <div>Out: {run.tokens_out.toLocaleString()}</div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                ${run.estimated_cost.toFixed(6)}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {getStatusBadge(run.status)}
                                                {run.error_message && (
                                                    <div className="mt-1 text-xs text-red-600 truncate max-w-xs" title={run.error_message}>
                                                        {run.error_message}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {run.related_tickets && run.related_tickets.length > 0 ? (
                                                    <div className="space-y-1">
                                                        {run.related_tickets.map((ticket) => (
                                                            <div key={ticket.id} className="text-xs">
                                                                <Link
                                                                    href={`/app/admin/support/tickets/${ticket.id}`}
                                                                    className="text-indigo-600 hover:text-indigo-900"
                                                                >
                                                                    {ticket.ticket_number}
                                                                </Link>
                                                            </div>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="8" className="px-6 py-4 text-center text-sm text-gray-500">
                                            No AI agent runs found.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {runs.links && runs.links.length > 3 && (
                        <div className="mt-4 flex items-center justify-between">
                            <div className="text-sm text-gray-700">
                                Showing {runs.from} to {runs.to} of {runs.total} results
                            </div>
                            <div className="flex space-x-2">
                                {runs.links.map((link, index) => (
                                    <button
                                        key={index}
                                        onClick={() => {
                                            if (link.url) {
                                                const url = new URL(link.url)
                                                const params = Object.fromEntries(url.searchParams.entries())
                                                router.get('/app/admin/ai/activity', { ...localFilters, ...params }, {
                                                    preserveState: true,
                                                    preserveScroll: true,
                                                })
                                            }
                                        }}
                                        disabled={!link.url}
                                        className={`
                                            px-3 py-2 text-sm font-medium rounded-md
                                            ${
                                                link.active
                                                    ? 'bg-indigo-600 text-white'
                                                    : link.url
                                                    ? 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50'
                                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                            }
                                        `}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
