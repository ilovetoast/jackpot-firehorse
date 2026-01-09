import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    BoltIcon,
    CheckCircleIcon,
    XCircleIcon,
    XMarkIcon,
    EyeIcon,
} from '@heroicons/react/24/outline'

export default function AIActivity({ runs, filters, filterOptions }) {
    const { auth } = usePage().props
    const [localFilters, setLocalFilters] = useState(filters || {})
    const [selectedRun, setSelectedRun] = useState(null)
    const [runDetails, setRunDetails] = useState(null)
    const [loadingDetails, setLoadingDetails] = useState(false)

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

    const fetchRunDetails = async (runId) => {
        setLoadingDetails(true)
        setSelectedRun(runId)
        try {
            const response = await fetch(`/app/admin/ai/runs/${runId}`)
            if (response.ok) {
                const data = await response.json()
                setRunDetails(data)
            } else {
                setRunDetails(null)
            }
        } catch (error) {
            console.error('Failed to fetch run details:', error)
            setRunDetails(null)
        } finally {
            setLoadingDetails(false)
        }
    }

    const closeDetails = () => {
        setSelectedRun(null)
        setRunDetails(null)
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
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
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
                                            <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                <button
                                                    onClick={() => fetchRunDetails(run.id)}
                                                    className="inline-flex items-center rounded-md bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                                                >
                                                    <EyeIcon className="h-3 w-3 mr-1" />
                                                    Details
                                                </button>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="9" className="px-6 py-4 text-center text-sm text-gray-500">
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

                    {/* Details Modal */}
                    {selectedRun && (
                        <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                            <div className="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={closeDetails}></div>
                                <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                                    <div className="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                        <div className="flex items-center justify-between mb-4">
                                            <h3 className="text-lg font-medium leading-6 text-gray-900" id="modal-title">
                                                AI Agent Run Details
                                            </h3>
                                            <button
                                                onClick={closeDetails}
                                                className="text-gray-400 hover:text-gray-500"
                                            >
                                                <XMarkIcon className="h-6 w-6" />
                                            </button>
                                        </div>
                                        {loadingDetails ? (
                                            <div className="text-center py-8">
                                                <div className="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                                                <p className="mt-2 text-sm text-gray-500">Loading details...</p>
                                            </div>
                                        ) : runDetails ? (
                                            <div className="space-y-4">
                                                {/* Basic Info */}
                                                <div className="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Agent</label>
                                                        <p className="text-sm text-gray-900">{runDetails.agent_name}</p>
                                                        <p className="text-xs text-gray-500">{runDetails.agent_id}</p>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Task Type</label>
                                                        <p className="text-sm text-gray-900">{runDetails.task_type}</p>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Status</label>
                                                        <div className="mt-1">{getStatusBadge(runDetails.status)}</div>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Model Used</label>
                                                        <p className="text-sm text-gray-900">{runDetails.model_used}</p>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Timestamp</label>
                                                        <p className="text-sm text-gray-900">{runDetails.timestamp}</p>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Duration</label>
                                                        <p className="text-sm text-gray-900">{runDetails.duration || 'N/A'}</p>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Tokens</label>
                                                        <p className="text-sm text-gray-900">
                                                            In: {runDetails.tokens_in.toLocaleString()} | Out: {runDetails.tokens_out.toLocaleString()} | Total: {runDetails.total_tokens.toLocaleString()}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <label className="text-xs font-medium text-gray-500">Cost</label>
                                                        <p className="text-sm text-gray-900">${runDetails.estimated_cost.toFixed(6)}</p>
                                                    </div>
                                                </div>

                                                {/* Context Info */}
                                                {(runDetails.tenant || runDetails.user) && (
                                                    <div className="border-t pt-4">
                                                        <h4 className="text-sm font-medium text-gray-900 mb-2">Context</h4>
                                                        <div className="grid grid-cols-2 gap-4">
                                                            {runDetails.tenant && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">Tenant</label>
                                                                    <p className="text-sm text-gray-900">{runDetails.tenant.name}</p>
                                                                </div>
                                                            )}
                                                            {runDetails.user && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">User</label>
                                                                    <p className="text-sm text-gray-900">{runDetails.user.name} ({runDetails.user.email})</p>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}

                                                {/* Error Message */}
                                                {runDetails.error_message && (
                                                    <div className="border-t pt-4">
                                                        <label className="text-xs font-medium text-gray-500">Error Message</label>
                                                        <p className="text-sm text-red-600 mt-1 bg-red-50 p-3 rounded">{runDetails.error_message}</p>
                                                    </div>
                                                )}

                                                {/* Metadata (Prompts/Responses) */}
                                                {runDetails.metadata && Object.keys(runDetails.metadata).length > 0 && (
                                                    <div className="border-t pt-4">
                                                        <h4 className="text-sm font-medium text-gray-900 mb-2">Execution Details</h4>
                                                        <div className="space-y-3">
                                                            {runDetails.metadata.prompt && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">Prompt</label>
                                                                    <pre className="mt-1 text-xs bg-gray-50 p-3 rounded border overflow-x-auto max-h-64 overflow-y-auto">
                                                                        {typeof runDetails.metadata.prompt === 'string' 
                                                                            ? runDetails.metadata.prompt 
                                                                            : JSON.stringify(runDetails.metadata.prompt, null, 2)}
                                                                    </pre>
                                                                </div>
                                                            )}
                                                            {runDetails.metadata.response && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">Response</label>
                                                                    <pre className="mt-1 text-xs bg-gray-50 p-3 rounded border overflow-x-auto max-h-64 overflow-y-auto">
                                                                        {typeof runDetails.metadata.response === 'string' 
                                                                            ? runDetails.metadata.response 
                                                                            : JSON.stringify(runDetails.metadata.response, null, 2)}
                                                                    </pre>
                                                                </div>
                                                            )}
                                                            {Object.keys(runDetails.metadata).filter(key => !['prompt', 'response'].includes(key)).length > 0 && (
                                                                <div>
                                                                    <label className="text-xs font-medium text-gray-500">Additional Metadata</label>
                                                                    <pre className="mt-1 text-xs bg-gray-50 p-3 rounded border overflow-x-auto max-h-64 overflow-y-auto">
                                                                        {JSON.stringify(
                                                                            Object.fromEntries(
                                                                                Object.entries(runDetails.metadata).filter(([key]) => !['prompt', 'response'].includes(key))
                                                                            ),
                                                                            null,
                                                                            2
                                                                        )}
                                                                    </pre>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                )}

                                                {/* Related Tickets */}
                                                {runDetails.related_tickets && runDetails.related_tickets.length > 0 && (
                                                    <div className="border-t pt-4">
                                                        <label className="text-xs font-medium text-gray-500">Related Tickets</label>
                                                        <div className="mt-2 space-y-1">
                                                            {runDetails.related_tickets.map((ticket) => (
                                                                <Link
                                                                    key={ticket.id}
                                                                    href={`/app/admin/support/tickets/${ticket.id}`}
                                                                    className="block text-sm text-indigo-600 hover:text-indigo-900"
                                                                >
                                                                    {ticket.ticket_number}: {ticket.subject}
                                                                </Link>
                                                            ))}
                                                        </div>
                                                    </div>
                                                )}

                                                {!runDetails.metadata && (
                                                    <div className="border-t pt-4">
                                                        <p className="text-xs text-gray-500 italic">
                                                            No execution details available. Prompt/response logging may be disabled (AI_STORE_PROMPTS=false).
                                                        </p>
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <div className="text-center py-8">
                                                <p className="text-sm text-red-600">Failed to load details</p>
                                            </div>
                                        )}
                                    </div>
                                    <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                        <button
                                            type="button"
                                            onClick={closeDetails}
                                            className="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm"
                                        >
                                            Close
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
