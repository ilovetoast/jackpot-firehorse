import { router, useForm, usePage } from '@inertiajs/react'
import { usePermission } from '../../hooks/usePermission'
import { useState } from 'react'
import { CheckCircleIcon, XCircleIcon, XMarkIcon, CurrencyDollarIcon, ChartBarIcon, ExclamationTriangleIcon, InformationCircleIcon, TicketIcon, DocumentTextIcon, ChevronDownIcon, ChevronRightIcon, CodeBracketIcon, CheckIcon } from '@heroicons/react/24/outline'
import BudgetStatusBadge from './BudgetStatusBadge'

// Activity Tab Content
export function ActivityTabContent({ runs, failedJobs = [], filterOptions, canManage }) {
    const [localFilters, setLocalFilters] = useState({})
    const [showFailedJobs, setShowFailedJobs] = useState(false)

    const applyFilters = (newFilters) => {
        const updatedFilters = { ...localFilters, ...newFilters }
        setLocalFilters(updatedFilters)
        router.get('/app/admin/ai', { tab: 'activity', ...updatedFilters }, {
            preserveState: true,
            preserveScroll: true,
            only: ['tabContent'],
        })
    }

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

    if (!runs) return <div className="text-center py-8 text-gray-500">Loading activity...</div>

    return (
        <div>
            {/* Filters */}
            <div className="mb-6 bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <select
                        value={localFilters.agent_id || ''}
                        onChange={(e) => applyFilters({ agent_id: e.target.value || null })}
                        className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm py-1.5"
                    >
                        <option value="">All Agents</option>
                        {filterOptions?.agents?.map((agent) => (
                            <option key={agent.value} value={agent.value}>{agent.label}</option>
                        ))}
                    </select>
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
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Timestamp</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Task</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cost</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {runs.data?.map((run) => (
                            <tr key={run.id}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{run.timestamp}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{run.agent_name}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{run.task_type}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${Number(run.estimated_cost || 0).toFixed(4)}</td>
                                <td className="px-6 py-4 whitespace-nowrap">{getStatusBadge(run.status)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {runs.links && (
                <div className="mt-4 flex justify-center">
                    <div className="flex space-x-2">
                        {runs.links.map((link, idx) => {
                            if (!link.url) {
                                return (
                                    <span
                                        key={idx}
                                        className="px-3 py-2 text-sm rounded-md opacity-50 cursor-not-allowed bg-white text-gray-700"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            }
                            // Parse URL and ensure tab parameter is set
                            const url = new URL(link.url, window.location.origin)
                            url.searchParams.set('tab', 'activity')
                            // Preserve any existing filters
                            Object.keys(localFilters).forEach(key => {
                                if (localFilters[key] && !url.searchParams.has(key)) {
                                    url.searchParams.set(key, localFilters[key])
                                }
                            })
                            return (
                                <button
                                    key={idx}
                                    onClick={() => router.get(url.pathname + url.search, {}, { preserveState: true, only: ['tabContent'] })}
                                    className={`px-3 py-2 text-sm rounded-md ${
                                        link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            )
                        })}
                    </div>
                </div>
            )}

            {/* Failed Jobs Section */}
            {failedJobs && failedJobs.length > 0 && (
                <div className="mt-8">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-lg font-medium text-gray-900">Failed Automation Jobs</h3>
                        <button
                            onClick={() => setShowFailedJobs(!showFailedJobs)}
                            className="text-sm text-indigo-600 hover:text-indigo-800"
                        >
                            {showFailedJobs ? 'Hide' : 'Show'} ({failedJobs.length})
                        </button>
                    </div>
                    {showFailedJobs && (
                        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failed At</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Job</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Error</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {failedJobs.map((job) => (
                                        <tr key={job.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{job.failed_at}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div>
                                                    <div className="font-medium">{job.job_class}</div>
                                                    {job.ticket_id && (
                                                        <div className="text-xs text-gray-400">Ticket ID: {job.ticket_id}</div>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                <div className="max-w-md truncate" title={job.error_message}>
                                                    {job.error_message}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                <button
                                                    onClick={() => {
                                                        if (confirm('Retry this failed job?')) {
                                                            router.post(`/app/admin/ai/queue/retry/${job.uuid}`, {}, {
                                                                preserveScroll: true,
                                                                onSuccess: () => {
                                                                    router.reload({ only: ['tabContent'] })
                                                                }
                                                            })
                                                        }
                                                    }}
                                                    className="text-indigo-600 hover:text-indigo-800"
                                                >
                                                    Retry
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            )}
        </div>
    )
}

// Models Tab Content
export function ModelsTabContent({ models, environment, canManage }) {
    const [editingModel, setEditingModel] = useState(null)
    const { data, setData, post, processing } = useForm({
        active: null,
        default_for_tasks: [],
        environment: environment,
    })

    const handleSave = (modelKey) => {
        post(`/app/admin/ai/models/${modelKey}/override`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingModel(null)
                router.get('/app/admin/ai', { tab: 'models' }, { preserveState: true, only: ['tabContent'] })
            },
        })
    }

    if (!models) return <div className="text-center py-8 text-gray-500">Loading models...</div>

    return (
        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Model</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Provider</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Active</th>
                        {canManage && <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>}
                    </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                    {models.map((model) => {
                        const isEditing = editingModel === model.key
                        const effective = model.effective || model.config
                        return (
                            <tr key={model.key}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{model.key}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{model.config.provider}</td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    {effective.active ? (
                                        <CheckCircleIcon className="h-5 w-5 text-green-500" />
                                    ) : (
                                        <XCircleIcon className="h-5 w-5 text-red-500" />
                                    )}
                                </td>
                                {canManage && (
                                    <td className="px-6 py-4 whitespace-nowrap text-sm">
                                        {isEditing ? (
                                            <div className="flex space-x-2">
                                                <button onClick={() => handleSave(model.key)} disabled={processing} className="text-indigo-600">Save</button>
                                                <button onClick={() => setEditingModel(null)} className="text-gray-600">Cancel</button>
                                            </div>
                                        ) : (
                                            <button onClick={() => setEditingModel(model.key)} className="text-indigo-600">Edit</button>
                                        )}
                                    </td>
                                )}
                            </tr>
                        )
                    })}
                </tbody>
            </table>
        </div>
    )
}

// Agents Tab Content - simplified
export function AgentsTabContent({ agents, availableModels, environment, canManage }) {
    if (!agents) return <div className="text-center py-8 text-gray-500">Loading agents...</div>
    return (
        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent ID</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Scope</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Default Model</th>
                    </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                    {agents.map((agent) => (
                        <tr key={agent.id}>
                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{agent.id}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{agent.config.name}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{agent.config.scope}</td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{agent.effective?.default_model || agent.config.default_model}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}

// Automations Tab Content - simplified
export function AutomationsTabContent({ automations, environment, canManage }) {
    if (!automations) return <div className="text-center py-8 text-gray-500">Loading automations...</div>
    return (
        <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
            <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trigger</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Enabled</th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Triggered</th>
                    </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                    {automations.map((auto) => (
                        <tr key={auto.key}>
                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{auto.key}</td>
                            <td className="px-6 py-4 text-sm text-gray-500">{auto.description}</td>
                            <td className="px-6 py-4 whitespace-nowrap">
                                {auto.effective?.enabled ? (
                                    <CheckCircleIcon className="h-5 w-5 text-green-500" />
                                ) : (
                                    <XCircleIcon className="h-5 w-5 text-red-500" />
                                )}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                {auto.last_triggered_at ? new Date(auto.last_triggered_at).toLocaleString() : 'Never'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}

// Reports Tab Content - full version
export function ReportsTabContent({ report, filters, filterOptions, environment }) {
    const [localFilters, setLocalFilters] = useState(filters || {})

    const applyFilters = (newFilters) => {
        const updatedFilters = { ...localFilters, ...newFilters }
        setLocalFilters(updatedFilters)
        router.get('/app/admin/ai', { tab: 'reports', ...updatedFilters }, {
            preserveState: true,
            preserveScroll: true,
            only: ['tabContent'],
        })
    }

    const clearFilters = () => {
        setLocalFilters({})
        router.get('/app/admin/ai', { tab: 'reports' }, {
            preserveState: true,
            preserveScroll: true,
            only: ['tabContent'],
        })
    }

    const hasActiveFilters = Object.values(localFilters).some((v) => v !== null && v !== '' && v !== undefined)

    if (!report) return <div className="text-center py-8 text-gray-500">Loading reports...</div>

    return (
        <div>
            {/* Summary Cards - Report-specific metrics (Error Rate and Avg Cost/Run only) */}
            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
                    <div className="flex items-center">
                        <ChartBarIcon className="h-6 w-6 text-gray-400" />
                        <div className="ml-4">
                            <p className="text-sm font-medium text-gray-500">Error Rate</p>
                            <p className="mt-1 text-2xl font-semibold text-gray-900">
                                {(report.error_rate || 0).toFixed(1)}%
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
                                ${Number(report.average_cost_per_run || 0).toFixed(4)}
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
                        <label className="block text-xs font-medium text-gray-700 mb-1">Task Type</label>
                        <select
                            value={localFilters.task_type || ''}
                            onChange={(e) => applyFilters({ task_type: e.target.value || null })}
                            className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Task Types</option>
                            {filterOptions?.task_types?.map((task) => (
                                <option key={task.value} value={task.value}>
                                    {task.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Context</label>
                        <select
                            value={localFilters.triggering_context || ''}
                            onChange={(e) => applyFilters({ triggering_context: e.target.value || null })}
                            className="block w-full min-w-[180px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Contexts</option>
                            {filterOptions?.contexts?.map((context) => (
                                <option key={context.value} value={context.value}>
                                    {context.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    {hasActiveFilters && (
                        <div className="flex-shrink-0">
                            <button
                                onClick={clearFilters}
                                className="mt-6 rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50"
                            >
                                Clear Filters
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {/* Aggregations */}
            <div className="space-y-6">
                {/* By Agent */}
                {report.aggregations?.by_agent && report.aggregations.by_agent.length > 0 && (
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
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Success Rate</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {report.aggregations.by_agent.map((item, idx) => (
                                        <tr key={idx}>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.agent_id}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_runs}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${Number(item.total_cost || 0).toFixed(4)}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_tokens.toLocaleString()}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {item.total_runs > 0 ? `${Math.round((item.successful_runs / item.total_runs) * 100)}%` : 'N/A'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* By Model */}
                {report.aggregations?.by_model && report.aggregations.by_model.length > 0 && (
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
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${Number(item.total_cost || 0).toFixed(4)}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">${Number(item.average_cost_per_run || 0).toFixed(4)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* By Task Type */}
                {report.aggregations?.by_task_type && report.aggregations.by_task_type.length > 0 && (
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Cost by Task Type</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task Type</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Runs</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Cost</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {report.aggregations.by_task_type.map((item, idx) => (
                                        <tr key={idx}>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.task_type}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_runs}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${Number(item.total_cost || 0).toFixed(4)}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_tokens.toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* By Context */}
                {report.aggregations?.by_context && report.aggregations.by_context.length > 0 && (
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Cost by Context</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Context</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Runs</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Cost</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {report.aggregations.by_context.map((item, idx) => (
                                        <tr key={idx}>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{item.triggering_context}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_runs}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${Number(item.total_cost || 0).toFixed(4)}</td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{item.total_tokens.toLocaleString()}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </div>
    )
}

// Budgets Tab Content - full version with editing
export function BudgetsTabContent({ budgets, environment, canManage }) {
    const [editingBudget, setEditingBudget] = useState(null)
    const { data, setData, post, processing, errors } = useForm({
        amount: null,
        warning_threshold_percent: null,
        hard_limit_enabled: null,
        environment: environment,
    })

    const handleEdit = (budget) => {
        setEditingBudget(budget.id)
        setData({
            amount: budget.override?.amount ?? budget.config?.amount ?? null,
            warning_threshold_percent: budget.override?.warning_threshold_percent ?? budget.config?.warning_threshold_percent ?? null,
            hard_limit_enabled: budget.override?.hard_limit_enabled ?? budget.config?.hard_limit_enabled ?? null,
            environment: environment,
        })
    }

    const handleSave = (budgetId) => {
        post(`/app/admin/ai/budgets/${budgetId}/override`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingBudget(null)
                router.get('/app/admin/ai', { tab: 'budgets' }, {
                    preserveState: true,
                    only: ['tabContent'],
                })
            },
        })
    }

    const handleCancel = () => {
        setEditingBudget(null)
        setData({
            amount: null,
            warning_threshold_percent: null,
            hard_limit_enabled: null,
            environment: environment,
        })
    }

    const getEffectiveValue = (budget, field) => {
        if (budget.override && budget.override[field] !== null && budget.override[field] !== undefined) {
            return budget.override[field]
        }
        return budget.config?.[field] ?? null
    }

    if (!budgets) return <div className="text-center py-8 text-gray-500">Loading budgets...</div>

    if (budgets.length === 0) {
        return (
            <div className="rounded-lg bg-white p-8 shadow-sm ring-1 ring-gray-200 text-center">
                <p className="text-sm text-gray-500">No budgets configured.</p>
            </div>
        )
    }

    return (
        <div>
            {canManage && (
                <div className="mb-6 rounded-lg bg-blue-50 p-4 ring-1 ring-blue-200">
                    <p className="text-sm text-blue-700">
                        <strong>Note:</strong> Changes will apply to future AI executions only. Historical runs
                        remain unchanged. Config fields are read-only; only override fields can be edited.
                    </p>
                </div>
            )}

            <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Budget</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Used</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remaining</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Warning</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hard Limit</th>
                            {canManage && (
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {budgets.map((budget) => {
                            const isEditing = editingBudget === budget.id
                            const effectiveAmount = budget.effective_amount ?? getEffectiveValue(budget, 'amount')
                            const currentUsage = budget.current_usage ?? 0
                            const remaining = budget.remaining ?? (effectiveAmount - currentUsage)
                            const usagePercent = effectiveAmount > 0 ? (currentUsage / effectiveAmount) * 100 : 0

                            return (
                                <tr key={budget.id || budget.budget_type + budget.scope_key}>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <div className="text-sm font-medium text-gray-900">{budget.name}</div>
                                        <div className="text-xs text-gray-500">
                                            {budget.source === 'database' ? 'Database' : 'Config'}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        {isEditing ? (
                                            <input
                                                type="number"
                                                step="0.01"
                                                value={data.amount ?? ''}
                                                onChange={(e) => setData('amount', e.target.value ? parseFloat(e.target.value) : null)}
                                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            />
                                        ) : (
                                            <div className="text-sm text-gray-900">${effectiveAmount?.toFixed(2) ?? 'N/A'}</div>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <div className="text-sm text-gray-900">${currentUsage.toFixed(2)}</div>
                                        <div className="text-xs text-gray-500">{usagePercent.toFixed(1)}%</div>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <div className={`text-sm font-medium ${remaining < 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                            ${remaining.toFixed(2)}
                                        </div>
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        <BudgetStatusBadge status={budget.status} />
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        {isEditing ? (
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                value={data.warning_threshold_percent ?? ''}
                                                onChange={(e) => setData('warning_threshold_percent', e.target.value ? parseInt(e.target.value) : null)}
                                                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            />
                                        ) : (
                                            <div className="text-sm text-gray-900">
                                                {budget.warning_threshold ?? getEffectiveValue(budget, 'warning_threshold_percent')}%
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 whitespace-nowrap">
                                        {isEditing ? (
                                            <input
                                                type="checkbox"
                                                checked={data.hard_limit_enabled ?? false}
                                                onChange={(e) => setData('hard_limit_enabled', e.target.checked)}
                                                className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                        ) : (
                                            <div className="text-sm text-gray-900">
                                                {budget.hard_limit_enabled ?? getEffectiveValue(budget, 'hard_limit_enabled') ? 'Yes' : 'No'}
                                            </div>
                                        )}
                                    </td>
                                    {canManage && (
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            {isEditing ? (
                                                <div className="flex space-x-2">
                                                    <button
                                                        onClick={() => handleSave(budget.id)}
                                                        disabled={processing}
                                                        className="text-indigo-600 hover:text-indigo-900"
                                                    >
                                                        Save
                                                    </button>
                                                    <button
                                                        onClick={handleCancel}
                                                        className="text-gray-600 hover:text-gray-900"
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                            ) : (
                                                <button
                                                    onClick={() => handleEdit(budget)}
                                                    className="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    Edit
                                                </button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            )
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    )
}

// Alerts Tab Content (Phase 5B: Admin Observability UI)
export function AlertsTabContent({ alerts, filterOptions }) {
    const { can } = usePermission()
    const canViewAIDashboard = can('ai.dashboard.view')
    // Initialize filters (backend defaults to open alerts if no status filter provided)
    const [localFilters, setLocalFilters] = useState({})
    const [confirmAction, setConfirmAction] = useState({ open: false, alertId: null, action: null, alertName: null })
    const [expandedAlertId, setExpandedAlertId] = useState(null)

    const applyFilters = (newFilters) => {
        const updatedFilters = { ...localFilters, ...newFilters }
        setLocalFilters(updatedFilters)
        router.get('/app/admin/ai', { tab: 'alerts', ...updatedFilters }, {
            preserveState: true,
            preserveScroll: true,
            only: ['tabContent'],
        })
    }

    const clearFilters = () => {
        setLocalFilters({})
        router.get('/app/admin/ai', { tab: 'alerts' }, {
            preserveState: true,
            preserveScroll: true,
            only: ['tabContent'],
        })
    }

    const hasActiveFilters = Object.values(localFilters).some((v) => v !== null && v !== '' && v !== undefined)

    const getSeverityBadge = (severity) => {
        const styles = {
            critical: 'bg-red-100 text-red-800',
            warning: 'bg-yellow-100 text-yellow-800',
            info: 'bg-blue-100 text-blue-800',
        }
        const icons = {
            critical: ExclamationTriangleIcon,
            warning: ExclamationTriangleIcon,
            info: InformationCircleIcon,
        }
        const Icon = icons[severity] || InformationCircleIcon
        return (
            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[severity] || styles.info}`}>
                <Icon className="h-3 w-3 mr-1" />
                {severity.charAt(0).toUpperCase() + severity.slice(1)}
            </span>
        )
    }

    const getStatusBadge = (status) => {
        const styles = {
            open: 'bg-red-100 text-red-800',
            acknowledged: 'bg-yellow-100 text-yellow-800',
            resolved: 'bg-green-100 text-green-800',
            closed: 'bg-gray-100 text-gray-800',
        }
        return (
            <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${styles[status] || styles.open}`}>
                {status.charAt(0).toUpperCase() + status.slice(1)}
            </span>
        )
    }

    if (!alerts) return <div className="text-center py-8 text-gray-500">Loading alerts...</div>

    return (
        <div>
            {/* Filters */}
            <div className="mb-6 bg-white shadow-sm ring-1 ring-gray-200 rounded-lg p-4">
                <div className="flex flex-wrap items-center gap-3">
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Severity</label>
                        <select
                            value={localFilters.severity || ''}
                            onChange={(e) => applyFilters({ severity: e.target.value || null })}
                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Severities</option>
                            {filterOptions?.severities?.map((severity) => (
                                <option key={severity.value} value={severity.value}>
                                    {severity.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Status</label>
                        <select
                            value={localFilters.status || ''}
                            onChange={(e) => applyFilters({ status: e.target.value || null })}
                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Statuses</option>
                            {filterOptions?.statuses?.map((status) => (
                                <option key={status.value} value={status.value}>
                                    {status.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Scope</label>
                        <select
                            value={localFilters.scope || ''}
                            onChange={(e) => applyFilters({ scope: e.target.value || null })}
                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Scopes</option>
                            {filterOptions?.scopes?.map((scope) => (
                                <option key={scope.value} value={scope.value}>
                                    {scope.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Detection Rule</label>
                        <select
                            value={localFilters.rule_id || ''}
                            onChange={(e) => applyFilters({ rule_id: e.target.value || null })}
                            className="block w-full min-w-[200px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Rules</option>
                            {filterOptions?.rules?.map((rule) => (
                                <option key={rule.value} value={rule.value}>
                                    {rule.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Tenant</label>
                        <select
                            value={localFilters.tenant_id || ''}
                            onChange={(e) => applyFilters({ tenant_id: e.target.value || null })}
                            className="block w-full min-w-[150px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All Tenants</option>
                            {filterOptions?.tenants?.map((tenant) => (
                                <option key={tenant.value} value={tenant.value}>
                                    {tenant.label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Has Ticket</label>
                        <select
                            value={localFilters.has_ticket || ''}
                            onChange={(e) => applyFilters({ has_ticket: e.target.value || null })}
                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All</option>
                            <option value="yes">Has Ticket</option>
                            <option value="no">No Ticket</option>
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Ticket Status</label>
                        <select
                            value={localFilters.ticket_status || ''}
                            onChange={(e) => applyFilters({ ticket_status: e.target.value || null })}
                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All</option>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                            <option value="none">No Ticket</option>
                        </select>
                    </div>
                    <div className="flex-shrink-0">
                        <label className="block text-xs font-medium text-gray-700 mb-1">Has Summary</label>
                        <select
                            value={localFilters.has_summary || ''}
                            onChange={(e) => applyFilters({ has_summary: e.target.value || null })}
                            className="block w-full min-w-[120px] rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                        >
                            <option value="">All</option>
                            <option value="yes">Has Summary</option>
                            <option value="no">No Summary</option>
                        </select>
                    </div>
                    {hasActiveFilters && (
                        <div className="flex-shrink-0 self-end">
                            <button
                                onClick={clearFilters}
                                className="inline-flex items-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            >
                                Clear Filters
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {/* Alerts Table */}
            <div className="rounded-lg bg-white shadow-sm ring-1 ring-gray-200 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-8"></th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rule</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Scope</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Detections</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alert Status</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ticket Status</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">First Detected</th>
                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Detected</th>
                                {canViewAIDashboard && (
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {alerts.data && alerts.data.length > 0 ? (
                                alerts.data.map((alert) => {
                                    const isExpanded = expandedAlertId === alert.id
                                    return (
                                        <>
                                            <tr key={alert.id} className="hover:bg-gray-50">
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <button
                                                        onClick={() => setExpandedAlertId(isExpanded ? null : alert.id)}
                                                        className="text-gray-400 hover:text-gray-600"
                                                    >
                                                        {isExpanded ? (
                                                            <ChevronDownIcon className="h-5 w-5" />
                                                        ) : (
                                                            <ChevronRightIcon className="h-5 w-5" />
                                                        )}
                                                    </button>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    {getSeverityBadge(alert.severity)}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="text-sm font-medium text-gray-900">{alert.rule_name}</div>
                                                    <div className="text-xs text-gray-500 mt-1">
                                                        {alert.observed_count} / {alert.threshold_count} in {alert.window_minutes}min
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm text-gray-900 capitalize">{alert.scope}</div>
                                                    {alert.tenant_name && (
                                                        <div className="text-xs text-gray-500">{alert.tenant_name}</div>
                                                    )}
                                                    {alert.subject_id && (
                                                        <div className="text-xs text-gray-500 font-mono">{alert.subject_id.substring(0, 8)}...</div>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <div className="text-sm text-gray-900">{alert.detection_count}x</div>
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    {getStatusBadge(alert.status)}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    {alert.has_ticket ? (
                                                        <div className="flex items-center text-sm text-gray-900">
                                                            <TicketIcon className="h-4 w-4 mr-1 text-blue-500" />
                                                            <span className="capitalize">{alert.ticket?.status}</span>
                                                        </div>
                                                    ) : (
                                                        <span className="text-sm text-gray-400">No ticket</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {new Date(alert.first_detected_at).toLocaleString()}
                                                </td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    {new Date(alert.last_detected_at).toLocaleString()}
                                                </td>
                                                {canViewAIDashboard && (
                                                    <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <div className="flex items-center space-x-2">
                                                            {alert.status === 'open' && (
                                                                <>
                                                                    <button
                                                                        onClick={() => handleAcknowledge(alert)}
                                                                        className="text-yellow-600 hover:text-yellow-900 text-xs px-2 py-1 rounded border border-yellow-300 hover:bg-yellow-50"
                                                                    >
                                                                        Acknowledge
                                                                    </button>
                                                                    <button
                                                                        onClick={() => handleResolve(alert)}
                                                                        className="text-green-600 hover:text-green-900 text-xs px-2 py-1 rounded border border-green-300 hover:bg-green-50"
                                                                    >
                                                                        Resolve
                                                                    </button>
                                                                </>
                                                            )}
                                                            {alert.status === 'acknowledged' && (
                                                                <button
                                                                    onClick={() => handleResolve(alert)}
                                                                    className="text-green-600 hover:text-green-900 text-xs px-2 py-1 rounded border border-green-300 hover:bg-green-50"
                                                                >
                                                                    Resolve
                                                                </button>
                                                            )}
                                                            {(alert.status === 'resolved' || alert.status === 'closed') && (
                                                                <span className="text-xs text-gray-400">—</span>
                                                            )}
                                                        </div>
                                                    </td>
                                                )}
                                            </tr>
                                            {isExpanded && (
                                                <tr key={`${alert.id}-detail`} className="bg-gray-50">
                                                    <td colSpan={canViewAIDashboard ? "10" : "9"} className="px-6 py-4">
                                                        <div className="space-y-4">
                                                            {/* AI Summary */}
                                                            {alert.has_summary && alert.summary && (
                                                                <div className="bg-white rounded-lg p-4 shadow-sm ring-1 ring-gray-200">
                                                                    <h3 className="text-sm font-semibold text-gray-900 mb-2 flex items-center">
                                                                        <DocumentTextIcon className="h-4 w-4 mr-2 text-green-500" />
                                                                        AI Summary
                                                                    </h3>
                                                                    <p className="text-sm text-gray-700 mb-2">{alert.summary.summary_text}</p>
                                                                    {alert.summary.impact_summary && (
                                                                        <div className="mt-2">
                                                                            <p className="text-xs font-medium text-gray-500 mb-1">Impact:</p>
                                                                            <p className="text-sm text-gray-700">{alert.summary.impact_summary}</p>
                                                                        </div>
                                                                    )}
                                                                    {alert.summary.affected_scope && (
                                                                        <div className="mt-2">
                                                                            <p className="text-xs font-medium text-gray-500 mb-1">Affected Scope:</p>
                                                                            <p className="text-sm text-gray-700">{alert.summary.affected_scope}</p>
                                                                        </div>
                                                                    )}
                                                                    {alert.summary.suggested_actions && alert.summary.suggested_actions.length > 0 && (
                                                                        <div className="mt-2">
                                                                            <p className="text-xs font-medium text-gray-500 mb-1">Suggested Actions:</p>
                                                                            <ul className="list-disc list-inside text-sm text-gray-700 space-y-1">
                                                                                {alert.summary.suggested_actions.map((action, idx) => (
                                                                                    <li key={idx}>{action}</li>
                                                                                ))}
                                                                            </ul>
                                                                        </div>
                                                                    )}
                                                                    <div className="mt-2 text-xs text-gray-500">
                                                                        Confidence: {(alert.summary.confidence_score * 100).toFixed(0)}%
                                                                    </div>
                                                                </div>
                                                            )}

                                                            {/* Support Ticket Details */}
                                                            {alert.has_ticket && alert.ticket && (
                                                                <div className="bg-white rounded-lg p-4 shadow-sm ring-1 ring-gray-200">
                                                                    <h3 className="text-sm font-semibold text-gray-900 mb-2 flex items-center">
                                                                        <TicketIcon className="h-4 w-4 mr-2 text-blue-500" />
                                                                        Support Ticket #{alert.ticket.id}
                                                                    </h3>
                                                                    <div className="grid grid-cols-2 gap-4 text-sm">
                                                                        <div>
                                                                            <p className="text-xs font-medium text-gray-500">Status</p>
                                                                            <p className="text-gray-900 capitalize mt-1">{alert.ticket.status}</p>
                                                                        </div>
                                                                        <div>
                                                                            <p className="text-xs font-medium text-gray-500">Source</p>
                                                                            <p className="text-gray-900 capitalize mt-1">{alert.ticket.source}</p>
                                                                        </div>
                                                                        <div className="col-span-2">
                                                                            <p className="text-xs font-medium text-gray-500">Summary</p>
                                                                            <p className="text-gray-900 mt-1">{alert.ticket.summary}</p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            )}

                                                            {/* Detection Metadata (Collapsed) */}
                                                            {alert.context && Object.keys(alert.context).length > 0 && (
                                                                <details className="bg-white rounded-lg p-4 shadow-sm ring-1 ring-gray-200">
                                                                    <summary className="text-sm font-semibold text-gray-900 cursor-pointer flex items-center">
                                                                        <CodeBracketIcon className="h-4 w-4 mr-2 text-gray-400" />
                                                                        Detection Metadata
                                                                    </summary>
                                                                    <pre className="mt-3 text-xs text-gray-600 overflow-x-auto bg-gray-50 p-3 rounded">
                                                                        {JSON.stringify(alert.context, null, 2)}
                                                                    </pre>
                                                                </details>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            )}
                                        </>
                                    )
                                })
                            ) : (
                                <tr>
                                    <td colSpan={canViewAIDashboard ? "10" : "9"} className="px-6 py-8 text-center text-sm text-gray-500">
                                        No alerts found
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {alerts.links && alerts.links.length > 3 && (
                    <div className="bg-gray-50 px-4 py-3 border-t border-gray-200 sm:px-6">
                        <div className="flex items-center justify-between">
                            <div className="flex-1 flex justify-between sm:hidden">
                                {alerts.links[0].url && (
                                    <a
                                        href={alerts.links[0].url}
                                        onClick={(e) => {
                                            e.preventDefault()
                                            router.get(alerts.links[0].url, { tab: 'alerts', ...localFilters }, {
                                                preserveState: true,
                                                preserveScroll: false,
                                                only: ['tabContent'],
                                            })
                                        }}
                                        className="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                    >
                                        Previous
                                    </a>
                                )}
                                {alerts.links[alerts.links.length - 1].url && (
                                    <a
                                        href={alerts.links[alerts.links.length - 1].url}
                                        onClick={(e) => {
                                            e.preventDefault()
                                            router.get(alerts.links[alerts.links.length - 1].url, { tab: 'alerts', ...localFilters }, {
                                                preserveState: true,
                                                preserveScroll: false,
                                                only: ['tabContent'],
                                            })
                                        }}
                                        className="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
                                    >
                                        Next
                                    </a>
                                )}
                            </div>
                            <div className="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-sm text-gray-700">
                                        Showing <span className="font-medium">{alerts.from || 0}</span> to{' '}
                                        <span className="font-medium">{alerts.to || 0}</span> of{' '}
                                        <span className="font-medium">{alerts.total || 0}</span> results
                                    </p>
                                </div>
                                <div>
                                    <nav className="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                        {alerts.links.map((link, index) => (
                                            link.url ? (
                                                <a
                                                    key={index}
                                                    href={link.url}
                                                    onClick={(e) => {
                                                        e.preventDefault()
                                                        router.get(link.url, { tab: 'alerts', ...localFilters }, {
                                                            preserveState: true,
                                                            preserveScroll: false,
                                                            only: ['tabContent'],
                                                        })
                                                    }}
                                                    className={`relative inline-flex items-center px-4 py-2 border text-sm font-medium ${
                                                        link.active
                                                            ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600'
                                                            : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50'
                                                    }`}
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            ) : (
                                                <span
                                                    key={index}
                                                    className="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-300 cursor-not-allowed"
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            )
                                        ))}
                                    </nav>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Confirmation Dialog */}
            {confirmAction.open && (
                <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div className="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                        <div className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onClick={cancelAction}></div>
                        <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                        <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                            <div className="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div className="sm:flex sm:items-start">
                                    <div className="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 sm:mx-0 sm:h-10 sm:w-10">
                                        <ExclamationTriangleIcon className="h-6 w-6 text-yellow-600" />
                                    </div>
                                    <div className="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                        <h3 className="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                            Confirm {confirmAction.action === 'acknowledge' ? 'Acknowledgment' : 'Resolution'}
                                        </h3>
                                        <div className="mt-2">
                                            <p className="text-sm text-gray-500">
                                                Are you sure you want to {confirmAction.action} this alert?
                                            </p>
                                            <p className="text-sm font-medium text-gray-900 mt-1">
                                                {confirmAction.alertName}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                <button
                                    type="button"
                                    onClick={confirmAndExecute}
                                    className={`w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-base font-medium text-white sm:ml-3 sm:w-auto sm:text-sm ${
                                        confirmAction.action === 'acknowledge'
                                            ? 'bg-yellow-600 hover:bg-yellow-700'
                                            : 'bg-green-600 hover:bg-green-700'
                                    } focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500`}
                                >
                                    {confirmAction.action === 'acknowledge' ? 'Acknowledge' : 'Resolve'}
                                </button>
                                <button
                                    type="button"
                                    onClick={cancelAction}
                                    className="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
