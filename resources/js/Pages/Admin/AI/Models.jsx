import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import AdminAiCenterPage from '../../../Components/Admin/AdminAiCenterPage'
import { CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline'

export default function AIModels({ models, environment, canManage }) {
    const [editingModel, setEditingModel] = useState(null)
    const { data, setData, post, processing, errors } = useForm({
        active: null,
        default_for_tasks: [],
        environment: environment,
    })

    const handleEdit = (model) => {
        setEditingModel(model.key)
        setData({
            active: model.override?.active ?? null,
            default_for_tasks: model.override?.default_for_tasks ?? [],
            environment: environment,
        })
    }

    const handleSave = (modelKey) => {
        post(`/app/admin/ai/models/${modelKey}/override`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingModel(null)
            },
        })
    }

    const handleCancel = () => {
        setEditingModel(null)
        setData({
            active: null,
            default_for_tasks: [],
            environment: environment,
        })
    }

    return (
        <AdminAiCenterPage
            breadcrumbs={[
                { label: 'Admin', href: '/app/admin' },
                { label: 'AI Control Center', href: '/app/admin/ai' },
                { label: 'Models & providers' },
            ]}
            title="Models &amp; providers"
            description="Manage AI model configuration and overrides."
            technicalNote={
                <p className="mt-2 text-xs text-slate-500">
                    Environment: <span className="font-medium text-slate-800">{environment}</span>
                </p>
            }
        >
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
                                        Model Key
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Provider
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Model Name
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Capabilities
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Cost (per 1K tokens)
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
                                {models.map((model) => {
                                    const isEditing = editingModel === model.key
                                    const effective = model.effective || model.config

                                    return (
                                        <tr key={model.key} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {model.key}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {model.config.provider}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {model.config.model_name}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {model.config.capabilities?.join(', ') || '—'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {model.config.default_cost_per_token ? (
                                                    <>
                                                        <div>In: ${((model.config.default_cost_per_token.input || 0) * 1000).toFixed(6)}</div>
                                                        <div>Out: ${((model.config.default_cost_per_token.output || 0) * 1000).toFixed(6)}</div>
                                                    </>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
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
                                                        {effective.active ? (
                                                            <CheckCircleIcon className="h-5 w-5 text-green-500" />
                                                        ) : (
                                                            <XCircleIcon className="h-5 w-5 text-red-500" />
                                                        )}
                                                        <span className="ml-2 text-sm text-gray-500">
                                                            {model.has_override && model.override?.active !== null
                                                                ? '(Override)'
                                                                : '(Config)'}
                                                        </span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                        model.source === 'override'
                                                            ? 'bg-indigo-100 text-indigo-800'
                                                            : 'bg-gray-100 text-gray-800'
                                                    }`}
                                                >
                                                    {model.source === 'override' ? 'Override' : 'Config'}
                                                </span>
                                            </td>
                                            {canManage && (
                                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                    {isEditing ? (
                                                        <div className="flex space-x-2">
                                                            <button
                                                                onClick={() => handleSave(model.key)}
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
                                                            onClick={() => handleEdit(model)}
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
        </AdminAiCenterPage>
    )
}
