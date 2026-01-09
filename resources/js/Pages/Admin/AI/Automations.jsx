import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import { CheckCircleIcon, XCircleIcon, ClockIcon } from '@heroicons/react/24/outline'

export default function AIAutomations({ automations, environment, canManage }) {
    const { auth } = usePage().props
    const [editingAutomation, setEditingAutomation] = useState(null)
    const { data, setData, post, processing, errors } = useForm({
        enabled: null,
        thresholds: null,
        environment: environment,
    })

    const handleEdit = (automation) => {
        setEditingAutomation(automation.key)
        setData({
            enabled: automation.override?.enabled ?? null,
            thresholds: automation.override?.thresholds ?? null,
            environment: environment,
        })
    }

    const handleSave = (triggerKey) => {
        post(`/app/admin/ai/automations/${triggerKey}/override`, {
            preserveScroll: true,
            onSuccess: () => {
                setEditingAutomation(null)
            },
        })
    }

    const handleCancel = () => {
        setEditingAutomation(null)
        setData({
            enabled: null,
            thresholds: null,
            environment: environment,
        })
    }

    const formatLastTriggered = (timestamp) => {
        if (!timestamp) return 'Never'
        const date = new Date(timestamp)
        const now = new Date()
        const diffMs = now - date
        const diffMins = Math.floor(diffMs / 60000)
        const diffHours = Math.floor(diffMs / 3600000)
        const diffDays = Math.floor(diffMs / 86400000)

        if (diffMins < 1) return 'Just now'
        if (diffMins < 60) return `${diffMins}m ago`
        if (diffHours < 24) return `${diffHours}h ago`
        if (diffDays < 7) return `${diffDays}d ago`
        return date.toLocaleDateString()
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
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Automations</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Manage AI automation triggers and thresholds
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
                                        Trigger Key
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Description
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Thresholds
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Enabled
                                    </th>
                                    <th scope="col" className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Last Triggered
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
                                {automations.map((automation) => {
                                    const isEditing = editingAutomation === automation.key
                                    const effective = automation.effective || automation.config

                                    return (
                                        <tr key={automation.key} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {automation.key}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {automation.description || automation.name || '—'}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {isEditing ? (
                                                    <textarea
                                                        value={JSON.stringify(data.thresholds || {}, null, 2)}
                                                        onChange={(e) => {
                                                            try {
                                                                const parsed = JSON.parse(e.target.value)
                                                                setData('thresholds', parsed)
                                                            } catch {
                                                                // Invalid JSON, keep as is
                                                            }
                                                        }}
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm font-mono text-xs"
                                                        rows={3}
                                                        placeholder="{}"
                                                    />
                                                ) : (
                                                    <pre className="text-xs font-mono bg-gray-50 p-2 rounded">
                                                        {JSON.stringify(effective.thresholds || {}, null, 2)}
                                                    </pre>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                {isEditing ? (
                                                    <select
                                                        value={data.enabled === null ? '' : data.enabled ? 'true' : 'false'}
                                                        onChange={(e) =>
                                                            setData(
                                                                'enabled',
                                                                e.target.value === '' ? null : e.target.value === 'true'
                                                            )
                                                        }
                                                        className="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                                    >
                                                        <option value="">Use Config</option>
                                                        <option value="true">Enabled</option>
                                                        <option value="false">Disabled</option>
                                                    </select>
                                                ) : (
                                                    <div className="flex items-center">
                                                        {effective.enabled !== false ? (
                                                            <CheckCircleIcon className="h-5 w-5 text-green-500" />
                                                        ) : (
                                                            <XCircleIcon className="h-5 w-5 text-red-500" />
                                                        )}
                                                        <span className="ml-2 text-sm text-gray-500">
                                                            {automation.has_override && automation.override?.enabled !== null
                                                                ? '(Override)'
                                                                : '(Config)'}
                                                        </span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <div className="flex items-center">
                                                    <ClockIcon className="h-4 w-4 mr-1 text-gray-400" />
                                                    {formatLastTriggered(automation.last_triggered_at)}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap">
                                                <span
                                                    className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                                        automation.source === 'override'
                                                            ? 'bg-indigo-100 text-indigo-800'
                                                            : 'bg-gray-100 text-gray-800'
                                                    }`}
                                                >
                                                    {automation.source === 'override' ? 'Override' : 'Config'}
                                                </span>
                                            </td>
                                            {canManage && (
                                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                    {isEditing ? (
                                                        <div className="flex space-x-2">
                                                            <button
                                                                onClick={() => handleSave(automation.key)}
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
                                                            onClick={() => handleEdit(automation)}
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
