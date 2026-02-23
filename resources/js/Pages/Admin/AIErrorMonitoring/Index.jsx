import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import axios from 'axios'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    ExclamationTriangleIcon,
    ChevronDownIcon,
    ChevronRightIcon,
    BoltIcon,
} from '@heroicons/react/24/outline'

function formatDate(iso) {
    if (!iso) return '—'
    try {
        return new Date(iso).toLocaleString()
    } catch (e) {
        return iso
    }
}

function ConfigPanel({ config }) {
    const c = config || {}
    return (
        <div className="rounded-lg bg-white shadow ring-1 ring-gray-200 p-4 mb-6">
            <h2 className="text-sm font-semibold text-gray-900 mb-3">Sentry AI Config</h2>
            <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-4 text-sm">
                <div>
                    <span className="text-gray-500">Pull enabled</span>
                    <p className="font-medium">{c.pull_enabled ? 'Yes' : 'No'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Auto heal enabled</span>
                    <p className="font-medium">{c.auto_heal_enabled ? 'Yes' : 'No'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Require confirmation</span>
                    <p className="font-medium">{c.require_confirmation ? 'Yes' : 'No'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Model</span>
                    <p className="font-medium">{c.model || '—'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Emergency disable</span>
                    <p className={`font-medium ${c.emergency_disable ? 'text-red-600' : ''}`}>{c.emergency_disable ? 'Yes' : 'No'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Monthly AI limit ($)</span>
                    <p className="font-medium">{c.monthly_ai_limit ?? '—'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Current month cost ($)</span>
                    <p className="font-medium">{c.current_month_cost != null ? Number(c.current_month_cost).toFixed(4) : '—'}</p>
                </div>
                <div>
                    <span className="text-gray-500">Last sync</span>
                    <p className="font-medium">{c.last_sync_at ? formatDate(c.last_sync_at) : 'Never'}</p>
                </div>
            </div>
        </div>
    )
}

function IssueRow({ issue, selected, onSelect, onAction, requireConfirmation }) {
    const [expanded, setExpanded] = useState(false)
    const [stackOpen, setStackOpen] = useState(false)
    const [loading, setLoading] = useState(null)

    const run = async (action) => {
        setLoading(action)
        try {
            if (action === 'toggle-heal') {
                await axios.post(route('admin.ai-error-monitoring.toggle-heal', { issue: issue.id }))
            } else if (action === 'dismiss') {
                await axios.post(route('admin.ai-error-monitoring.dismiss', { issue: issue.id }))
            } else if (action === 'resolve') {
                await axios.post(route('admin.ai-error-monitoring.resolve', { issue: issue.id }))
            } else if (action === 'reanalyze') {
                await axios.post(route('admin.ai-error-monitoring.reanalyze', { issue: issue.id }))
            } else if (action === 'confirm') {
                await axios.post(route('admin.ai-error-monitoring.confirm', { issue: issue.id }))
            }
            onAction?.()
        } catch (e) {
            console.error(e)
            alert(e?.response?.data?.message || e?.message || 'Action failed.')
        } finally {
            setLoading(null)
        }
    }

    const levelBadge = issue.level === 'error' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'
    const statusBadge = issue.status === 'open' ? 'bg-yellow-100 text-yellow-800' : issue.status === 'resolved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'

    return (
        <>
            <tr className="border-t border-gray-200">
                <td className="py-2 pl-4 pr-2">
                    <input
                        type="checkbox"
                        checked={selected}
                        onChange={(e) => onSelect(e.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                </td>
                <td className="py-2 pl-2 pr-2">
                    <button
                        type="button"
                        onClick={() => setExpanded((e) => !e)}
                        className="text-gray-500 hover:text-gray-700"
                    >
                        {expanded ? <ChevronDownIcon className="h-4 w-4" /> : <ChevronRightIcon className="h-4 w-4" />}
                    </button>
                </td>
                <td className="py-2 px-2">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${levelBadge}`}>{issue.level}</span>
                </td>
                <td className="py-2 px-2 text-sm text-gray-900 max-w-xs truncate" title={issue.title}>{issue.title}</td>
                <td className="py-2 px-2 text-sm text-gray-600">{issue.occurrence_count ?? 0}</td>
                <td className="py-2 px-2 text-sm text-gray-600">{issue.environment ?? '—'}</td>
                <td className="py-2 px-2 text-sm text-gray-600">{formatDate(issue.last_seen)}</td>
                <td className="py-2 px-2">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusBadge}`}>{issue.status}</span>
                </td>
                <td className="py-2 px-2 text-right">
                    <div className="flex justify-end gap-1 flex-wrap">
                        <button
                            type="button"
                            disabled={!!loading}
                            onClick={() => run('toggle-heal')}
                            className="inline-flex rounded px-2 py-1 text-xs font-medium bg-indigo-100 text-indigo-800 hover:bg-indigo-200 disabled:opacity-50"
                            title={issue.selected_for_heal ? 'Unmark for heal' : 'Select for heal (stub)'}
                        >
                            {loading === 'toggle-heal' ? '…' : issue.selected_for_heal ? 'Heal ✓' : 'Heal'}
                        </button>
                        <button
                            type="button"
                            disabled={!!loading}
                            onClick={() => run('dismiss')}
                            className="inline-flex rounded px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-50"
                        >
                            {loading === 'dismiss' ? '…' : 'Dismiss'}
                        </button>
                        <button
                            type="button"
                            disabled={!!loading}
                            onClick={() => run('resolve')}
                            className="inline-flex rounded px-2 py-1 text-xs font-medium bg-green-100 text-green-800 hover:bg-green-200 disabled:opacity-50"
                        >
                            {loading === 'resolve' ? '…' : 'Resolve'}
                        </button>
                        <button
                            type="button"
                            disabled={!!loading}
                            onClick={() => run('reanalyze')}
                            className="inline-flex rounded px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800 hover:bg-amber-200 disabled:opacity-50"
                            title="Re-run AI analysis"
                        >
                            {loading === 'reanalyze' ? '…' : <BoltIcon className="h-3.5 w-3.5" />}
                        </button>
                        {requireConfirmation && (
                            <button
                                type="button"
                                disabled={!!loading || issue.confirmed_for_heal}
                                onClick={() => run('confirm')}
                                className="inline-flex rounded px-2 py-1 text-xs font-medium bg-green-100 text-green-800 hover:bg-green-200 disabled:opacity-50"
                                title="Confirm for heal"
                            >
                                {loading === 'confirm' ? '…' : issue.confirmed_for_heal ? 'Confirmed' : 'Confirm'}
                            </button>
                        )}
                    </div>
                </td>
            </tr>
            {expanded && (
                <tr className="bg-gray-50 border-t border-gray-200">
                    <td colSpan={9} className="py-4 px-4">
                        <div className="grid grid-cols-1 gap-4 text-sm">
                            {issue.ai_summary && (
                                <div>
                                    <h4 className="font-medium text-gray-700 mb-1">AI Summary</h4>
                                    <p className="text-gray-600 whitespace-pre-wrap">{issue.ai_summary}</p>
                                </div>
                            )}
                            {issue.ai_root_cause && (
                                <div>
                                    <h4 className="font-medium text-gray-700 mb-1">Root cause</h4>
                                    <p className="text-gray-600 whitespace-pre-wrap">{issue.ai_root_cause}</p>
                                </div>
                            )}
                            {issue.ai_fix_suggestion && (
                                <div>
                                    <h4 className="font-medium text-gray-700 mb-1">Fix suggestion</h4>
                                    <p className="text-gray-600 whitespace-pre-wrap">{issue.ai_fix_suggestion}</p>
                                </div>
                            )}
                            <div>
                                <button
                                    type="button"
                                    onClick={() => setStackOpen((o) => !o)}
                                    className="font-medium text-gray-700 hover:text-gray-900"
                                >
                                    {stackOpen ? 'Hide' : 'Show'} stack trace
                                </button>
                                {stackOpen && (
                                    <pre className="mt-2 p-3 bg-gray-800 text-gray-100 rounded text-xs overflow-x-auto max-h-64 overflow-y-auto">
                                        {issue.stack_trace || '—'}
                                    </pre>
                                )}
                            </div>
                            <div className="flex gap-4 text-gray-600">
                                <span>Token in: {issue.ai_token_input ?? '—'}</span>
                                <span>Token out: {issue.ai_token_output ?? '—'}</span>
                                <span>Cost: {issue.ai_cost != null ? `$${Number(issue.ai_cost).toFixed(4)}` : '—'}</span>
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    )
}

export default function AIErrorMonitoringIndex({ auth, config, issues }) {
    const [selectedIds, setSelectedIds] = useState(new Set())
    const [bulkLoading, setBulkLoading] = useState(null)

    const data = issues?.data ?? []
    const links = issues?.links ?? []
    const allSelected = data.length > 0 && selectedIds.size === data.length
    const someSelected = selectedIds.size > 0

    const toggleSelect = (id, checked) => {
        setSelectedIds((prev) => {
            const next = new Set(prev)
            if (checked) next.add(id)
            else next.delete(id)
            return next
        })
    }

    const toggleSelectAll = (checked) => {
        if (checked) setSelectedIds(new Set(data.map((i) => i.id)))
        else setSelectedIds(new Set())
    }

    const runBulkAction = async (action) => {
        const ids = Array.from(selectedIds)
        if (ids.length === 0) return
        setBulkLoading(action)
        try {
            await axios.post(route('admin.ai-error-monitoring.bulk-action'), { action, ids })
            setSelectedIds(new Set())
            router.reload()
        } catch (e) {
            console.error(e)
            alert(e?.response?.data?.message || e?.message || 'Bulk action failed.')
        } finally {
            setBulkLoading(null)
        }
    }

    return (
        <div className="min-h-full">
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <Link href="/app/admin" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                        ← Back to Admin Dashboard
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">AI Error Monitoring</h1>
                    <p className="mt-2 text-sm text-gray-700">
                        Sentry issues pulled and analyzed by AI. No auto-heal yet (stub only).
                    </p>

                    <ConfigPanel config={config} />

                    <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                        <div className="px-4 py-4 sm:px-6 flex items-center justify-between flex-wrap gap-2">
                            <h2 className="text-lg font-semibold text-gray-900">Sentry Issues</h2>
                            {someSelected && (
                                <div className="flex items-center gap-2">
                                    <span className="text-sm text-gray-600">{selectedIds.size} selected</span>
                                    <button
                                        type="button"
                                        disabled={!!bulkLoading}
                                        onClick={() => runBulkAction('resolve')}
                                        className="inline-flex rounded px-3 py-1.5 text-sm font-medium bg-green-100 text-green-800 hover:bg-green-200 disabled:opacity-50"
                                    >
                                        {bulkLoading === 'resolve' ? '…' : 'Mark resolved'}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={!!bulkLoading}
                                        onClick={() => runBulkAction('dismiss')}
                                        className="inline-flex rounded px-3 py-1.5 text-sm font-medium bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-50"
                                    >
                                        {bulkLoading === 'dismiss' ? '…' : 'Mark dismissed'}
                                    </button>
                                </div>
                            )}
                        </div>
                        <div className="border-t border-gray-200 overflow-x-auto">
                            {!data.length ? (
                                <div className="p-12 text-center text-gray-500">
                                    <ExclamationTriangleIcon className="mx-auto h-12 w-12 text-gray-400" />
                                    <p className="mt-2">No Sentry issues. Pull runs daily at 2am when enabled.</p>
                                </div>
                            ) : (
                                <>
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="py-3 pl-4 pr-2 text-left">
                                                    <input
                                                        type="checkbox"
                                                        checked={allSelected}
                                                        ref={(el) => el && (el.indeterminate = someSelected && !allSelected)}
                                                        onChange={(e) => toggleSelectAll(e.target.checked)}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                </th>
                                                <th className="py-3 pl-2 pr-2 w-8" />
                                                <th className="py-3 px-2 text-left text-xs font-medium text-gray-500 uppercase">Level</th>
                                                <th className="py-3 px-2 text-left text-xs font-medium text-gray-500 uppercase">Title</th>
                                                <th className="py-3 px-2 text-left text-xs font-medium text-gray-500 uppercase">Count</th>
                                                <th className="py-3 px-2 text-left text-xs font-medium text-gray-500 uppercase">Environment</th>
                                                <th className="py-3 px-2 text-left text-xs font-medium text-gray-500 uppercase">Last Seen</th>
                                                <th className="py-3 px-2 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                                <th className="py-3 px-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200 bg-white">
                                            {data.map((issue) => (
                                                <IssueRow
                                                    key={issue.id}
                                                    issue={issue}
                                                    selected={selectedIds.has(issue.id)}
                                                    onSelect={(checked) => toggleSelect(issue.id, checked)}
                                                    onAction={() => router.reload()}
                                                    requireConfirmation={config?.require_confirmation === true}
                                                />
                                            ))}
                                        </tbody>
                                    </table>
                                    {links.length > 0 && (
                                        <div className="px-4 py-3 border-t border-gray-200 flex items-center justify-between">
                                            <div className="flex gap-2">
                                                {links.map((link, idx) => (
                                                    <span key={idx}>
                                                        {link.url ? (
                                                            <Link
                                                                href={link.url}
                                                                className={`px-2 py-1 rounded text-sm ${link.active ? 'bg-indigo-100 text-indigo-800 font-medium' : 'text-gray-600 hover:bg-gray-100'}`}
                                                            >
                                                                {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                                                            </Link>
                                                        ) : (
                                                            <span className="px-2 py-1 text-gray-400 cursor-not-allowed" dangerouslySetInnerHTML={{ __html: link.label }} />
                                                        )}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
