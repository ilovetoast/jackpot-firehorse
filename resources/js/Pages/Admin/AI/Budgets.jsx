import { useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import { ChevronDownIcon, ChevronUpIcon } from '@heroicons/react/24/outline'
import AdminAiCenterPage from '../../../Components/Admin/AdminAiCenterPage'
import BudgetStatusBadge from '../../../Components/AI/BudgetStatusBadge'

function formatUsd(value) {
    if (value === null || value === undefined || value === '') {
        return 'N/A'
    }
    const n = Number(value)
    if (!Number.isFinite(n)) {
        return 'N/A'
    }
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(n)
}

const STUDIO_VENDOR_TASK_KEYS = new Set([
    'studio_layer_extraction',
    'studio_layer_background_fill',
    'studio_composition_animation',
])

function sumUsedUsd(budgets) {
    return budgets.reduce((acc, b) => acc + (Number(b.current_usage) || 0), 0)
}

/** Collapsible block — same chrome as Admin Dashboard {@link SectionCard}. */
function BudgetGroupSection({ title, description, defaultExpanded = true, children }) {
    const [expanded, setExpanded] = useState(defaultExpanded)

    return (
        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <button
                type="button"
                onClick={() => setExpanded(!expanded)}
                className="w-full px-5 py-4 flex items-center justify-between text-left hover:bg-slate-50"
            >
                <div>
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    <p className="text-sm text-slate-500">{description}</p>
                </div>
                {expanded ? (
                    <ChevronUpIcon className="h-5 w-5 shrink-0 text-slate-400" aria-hidden />
                ) : (
                    <ChevronDownIcon className="h-5 w-5 shrink-0 text-slate-400" aria-hidden />
                )}
            </button>
            {expanded && <div className="border-t border-slate-200 overflow-x-auto">{children}</div>}
        </div>
    )
}

function BudgetsDataTable({
    budgets,
    canManage,
    editingBudget,
    data,
    setData,
    processing,
    getEffectiveValue,
    handleEdit,
    handleSave,
    handleCancel,
}) {
    if (budgets.length === 0) {
        return <p className="px-5 py-6 text-sm text-slate-500">No budgets in this group.</p>
    }

    return (
        <table className="min-w-full divide-y divide-gray-200">
            <thead className="bg-gray-50">
                <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Budget</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount (monthly)</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Used (this month)</th>
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
                                    {budget.period === 'monthly' ? ' · monthly' : ''}
                                </div>
                                {budget.period_description && (
                                    <div className="text-xs text-gray-400 max-w-sm mt-0.5" title={budget.period_description}>
                                        {budget.period_description}
                                    </div>
                                )}
                                {budget.description && (
                                    <div className="text-xs text-gray-500 max-w-lg mt-1">{budget.description}</div>
                                )}
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
                                    <div className="text-sm text-gray-900">{formatUsd(effectiveAmount)}</div>
                                )}
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap">
                                <div className="text-sm text-gray-900">{formatUsd(currentUsage)}</div>
                                <div className="text-xs text-gray-500">{usagePercent.toFixed(1)}%</div>
                            </td>
                            <td className="px-6 py-4 whitespace-nowrap">
                                <div className={`text-sm font-medium ${remaining < 0 ? 'text-red-600' : 'text-gray-900'}`}>
                                    {formatUsd(remaining)}
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
                                        onChange={(e) =>
                                            setData('warning_threshold_percent', e.target.value ? parseInt(e.target.value) : null)
                                        }
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
                                                type="button"
                                                onClick={() => handleSave(budget.id)}
                                                disabled={processing}
                                                className="text-indigo-600 hover:text-indigo-900"
                                            >
                                                Save
                                            </button>
                                            <button type="button" onClick={handleCancel} className="text-gray-600 hover:text-gray-900">
                                                Cancel
                                            </button>
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
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
    )
}

export default function AIBudgets({ budgets, environment, canManage }) {
    const [editingBudget, setEditingBudget] = useState(null)
    const { data, setData, post, processing } = useForm({
        amount: null,
        warning_threshold_percent: null,
        hard_limit_enabled: null,
        environment: environment,
    })

    const { systemBudgets, studioVendorBudgets, otherBudgets } = useMemo(() => {
        const system = []
        const studio = []
        const other = []
        for (const b of budgets) {
            if (b.budget_type === 'system') {
                system.push(b)
            } else if (b.budget_type === 'task_type' && b.scope_key && STUDIO_VENDOR_TASK_KEYS.has(b.scope_key)) {
                studio.push(b)
            } else {
                other.push(b)
            }
        }
        return { systemBudgets: system, studioVendorBudgets: studio, otherBudgets: other }
    }, [budgets])

    const studioUsedSummary = useMemo(() => formatUsd(sumUsedUsd(studioVendorBudgets)), [studioVendorBudgets])

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

    const tableProps = {
        canManage,
        editingBudget,
        data,
        setData,
        processing,
        getEffectiveValue,
        handleEdit,
        handleSave,
        handleCancel,
    }

    return (
        <AdminAiCenterPage
            breadcrumbs={[
                { label: 'Admin', href: '/app/admin' },
                { label: 'AI Control Center', href: '/app/admin/ai' },
                { label: 'Spend & budgets' },
            ]}
            title="Spend &amp; budgets"
            description="Caps and used amounts are per calendar month (resets on the 1st, app timezone). The system-wide monthly ceiling is stored in the database; edit overrides here — not via .env."
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

                    {budgets.length === 0 ? (
                        <div className="text-center py-12 rounded-2xl border border-slate-200 bg-white">
                            <p className="text-sm text-gray-500">No budgets configured.</p>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            <BudgetGroupSection
                                title="System-wide"
                                description="Global monthly USD cap across all tenants and AI runs"
                                defaultExpanded
                            >
                                <BudgetsDataTable budgets={systemBudgets} {...tableProps} />
                            </BudgetGroupSection>

                            <BudgetGroupSection
                                title="Studio vendor APIs"
                                description={
                                    studioVendorBudgets.length === 0
                                        ? 'No Studio task rows in this list (segmentation, background fill, still → video).'
                                        : `${studioVendorBudgets.length} service caps · ${studioUsedSummary} used this month (sum of rows)`
                                }
                                defaultExpanded
                            >
                                <BudgetsDataTable budgets={studioVendorBudgets} {...tableProps} />
                            </BudgetGroupSection>

                            {otherBudgets.length > 0 && (
                                <BudgetGroupSection
                                    title="Other task & agent budgets"
                                    description={`${otherBudgets.length} additional per–task-type or per-agent monthly caps`}
                                    defaultExpanded={false}
                                >
                                    <BudgetsDataTable budgets={otherBudgets} {...tableProps} />
                                </BudgetGroupSection>
                            )}
                        </div>
                    )}
        </AdminAiCenterPage>
    )
}
