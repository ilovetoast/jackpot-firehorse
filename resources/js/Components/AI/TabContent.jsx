import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { CheckCircleIcon, XCircleIcon, XMarkIcon, CurrencyDollarIcon, ChartBarIcon } from '@heroicons/react/24/outline'
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
