import { Link, router, usePage, useForm } from '@inertiajs/react'
import { useState } from 'react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import BudgetStatusBadge from '../../../Components/AI/BudgetStatusBadge'
import { CurrencyDollarIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline'

export default function AIBudgets({ budgets, environment, canManage }) {
    const { auth } = usePage().props
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
                                <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Budgets</h1>
                                <p className="mt-2 text-sm text-gray-700">
                                    Manage AI budget limits and quotas
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

                    {/* Budgets Table */}
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
                                    {canManage && <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>}
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
                                                    <div className="text-sm text-gray-900">{budget.warning_threshold ?? getEffectiveValue(budget, 'warning_threshold_percent')}%</div>
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

                    {budgets.length === 0 && (
                        <div className="text-center py-12">
                            <p className="text-sm text-gray-500">No budgets configured.</p>
                        </div>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
