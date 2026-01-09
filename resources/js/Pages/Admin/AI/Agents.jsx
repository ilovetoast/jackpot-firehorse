import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline'

export default function AIAgents({ agents, environment, availableModels = [], canManage }) {
    const { auth } = usePage().props
    const [editingAgent, setEditingAgent] = useState(null)
    const { data, setData, post, processing, errors } = useForm({
        active: null,
        default_model: null,
        environment: environment,
    })

    const handleEdit = (agent) => {
        setEditingAgent(agent.id)
        setData({
            active: agent.override?.active ?? null,
            default_model: agent.override?.default_model ?? null,
            environment: environment,
        })
    }

    const handleSave = (agentId) => {
        post(`/app/admin/ai/agents/${agentId}/override`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingAgent(null)
            },
        })
    }

    const handleCancel = () => {
        setEditingAgent(null)
        setData({
            active: null,
            default_model: null,
            environment: environment,
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
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Agents</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Manage AI agent configuration and overrides
                                </p>
                            </div>
                            <div className="text-sm text-gray-500">
                                Environment: <span className="font-medium text-gray-900">{environment}</span>
                            </div>
                        </div>
                    </div>

                    {/* Info Message */}
                    {canManage && (
                        <div className="mb-6 rounded-lg bg-blue-50 p-4 ring-1 ring-blue-200">
                            <p className="text-sm text-blue-700">
                                <strong>Note:</strong> Changes will apply to future AI executions only. Historical runs
                                remain unchanged. Config fields are read-only; only override fields can be edited.
                            </p>
                        </div>
                    )}

                    {/* Table */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Agent ID
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Name
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Description
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Scope
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Default Model
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Active
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Source
                                    </th>
                                    {canManage && (
                                        <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {agents.map((agent) => {
                                    const isEditing = editingAgent === agent.id
                                    const effective = agent.effective || agent.config

                                    return (
                                        <tr key={agent.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {agent.id}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {agent.config.name}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {agent.config.description}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {agent.config.scope}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {isEditing ? (
                                                    <select
                                                        value={data.default_model || ''}
                                                        onChange={(e) =>
                                                            setData('default_model', e.target.value || null)
                                                        }
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    >
                                                        <option value="">Use Config</option>
                                                        {availableModels.map((model) => (
                                                            <option key={model} value={model}>
                                                                {model}
                                                            </option>
                                                        ))}
                                                    </select>
                                                ) : (
                                                    <div className="text-sm text-gray-500">
                                                        {effective.default_model}
                                                        {agent.has_override && agent.override?.default_model && (
                                                            <span className="ml-2 text-xs text-indigo-600">(Override)</span>
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {isEditing ? (
                                                    <select
                                                        value={data.active === null ? '' : data.active ? 'true' : 'false'}
                                                        onChange={(e) =>
                                                            setData(
                                                                'active',
                                                                e.target.value === '' ? null : e.target.value === 'true'
                                                            )
                                                        }
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    >
                                                        <option value="">Use Config</option>
                                                        <option value="true">Active</option>
                                                        <option value="false">Inactive</option>
                                                    </select>
                                                ) : (
                                                    <div className="flex items-center">
                                                        {effective.active !== false ? (
                                                            <CheckCircleIcon className="h-5 w-5 text-green-500" />
                                                        ) : (
                                                            <XCircleIcon className="h-5 w-5 text-red-500" />
                                                        )}
                                                        <span className="ml-2 text-sm text-gray-500">
                                                            {agent.has_override && agent.override?.active !== null
                                                                ? '(Override)'
                                                                : '(Config)'}
                                                        </span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                        agent.source === 'override'
                                                            ? 'bg-indigo-100 text-indigo-800'
                                                            : 'bg-gray-100 text-gray-800'
                                                    }`}
                                                >
                                                    {agent.source === 'override' ? 'Override' : 'Config'}
                                                </span>
                                            </td>
                                            {canManage && (
                                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                    {isEditing ? (
                                                        <div className="flex space-x-2">
                                                            <button
                                                                onClick={() => handleSave(agent.id)}
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
                                                            onClick={() => handleEdit(agent)}
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
            </main>
            <AppFooter />
        </div>
    )
}
