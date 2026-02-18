import { Link, router } from '@inertiajs/react'
import { useState, useRef, useEffect } from 'react'
import axios from 'axios'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AssetDetailModal from '../../../Components/Admin/AssetDetailModal'
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    ClockIcon,
    QueueListIcon,
    LinkIcon,
    ServerStackIcon,
    ChartBarIcon,
    ChartBarSquareIcon,
} from '@heroicons/react/24/outline'

function IncidentRow({ incident: i, onAction, selected, onSelect, onSourceClick }) {
    const [loading, setLoading] = useState(null)
    const baseUrl = '/app/admin/incidents'
    const handle = async (action) => {
        setLoading(action)
        try {
            const res = await axios.post(`${baseUrl}/${i.id}/${action}`)
            const data = res?.data ?? {}
            onAction?.()
            if (action === 'create-ticket') {
                if (data.ticket_id) {
                    router.visit(`/app/admin/support/tickets/${data.ticket_id}`)
                } else if (!data.created) {
                    const msg = data.error
                        ? `Could not create ticket: ${data.error}`
                        : 'Could not create ticket. A ticket may already exist for this asset, or the incident may be resolved.'
                    alert(msg)
                }
            }
        } catch (e) {
            console.error(e)
            if (action === 'create-ticket') {
                const msg = e?.response?.data?.error || e?.message || 'Failed to create ticket. Please try again.'
                alert(msg)
            }
        } finally {
            setLoading(null)
        }
    }
    return (
        <tr>
            {onSelect != null && (
                <td className="whitespace-nowrap py-3 pl-4 pr-2">
                    <input
                        type="checkbox"
                        checked={selected}
                        onChange={(e) => onSelect(e.target.checked)}
                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    />
                </td>
            )}
            <td className="whitespace-nowrap py-3 pl-4 pr-3">
                <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                    i.severity === 'critical' ? 'bg-red-100 text-red-800' :
                    i.severity === 'error' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-800'
                }`}>{i.severity}</span>
            </td>
            <td className="py-3 px-3 text-sm text-gray-900">{i.title}</td>
            <td className="py-3 px-3 text-sm">
                {i.source_type === 'asset' && i.source_id ? (
                    <button
                        type="button"
                        onClick={() => onSourceClick?.(i.source_id)}
                        className="text-indigo-600 hover:text-indigo-900 font-medium hover:underline"
                    >
                        asset/{i.source_id}
                    </button>
                ) : (
                    <span className="text-gray-500">{i.source_type}/{i.source_id || '—'}</span>
                )}
            </td>
            <td className="py-3 px-3 text-sm text-gray-500">{new Date(i.detected_at).toLocaleString()}</td>
            <td className="py-3 px-3 text-sm text-gray-500">
                {i.repair_attempts > 0 ? (
                    <span title={i.last_repair_attempt_at ? `Last: ${new Date(i.last_repair_attempt_at).toLocaleString()}` : ''}>
                        {i.repair_attempts} {i.repair_attempts >= 3 ? '(→ ticket)' : ''}
                    </span>
                ) : '—'}
            </td>
            <td className="py-3 px-3 text-right">
                <div className="flex justify-end gap-1">
                    <button
                        type="button"
                        disabled={loading}
                        onClick={() => handle('attempt-repair')}
                        className="inline-flex rounded px-2 py-1 text-xs font-medium bg-indigo-100 text-indigo-800 hover:bg-indigo-200 disabled:opacity-50"
                    >
                        {loading === 'attempt-repair' ? '…' : 'Attempt Repair'}
                    </button>
                    <button
                        type="button"
                        disabled={loading}
                        onClick={() => handle('create-ticket')}
                        className="inline-flex rounded px-2 py-1 text-xs font-medium bg-amber-100 text-amber-800 hover:bg-amber-200 disabled:opacity-50"
                    >
                        {loading === 'create-ticket' ? '…' : 'Create Ticket'}
                    </button>
                    <button
                        type="button"
                        disabled={loading}
                        onClick={() => handle('resolve')}
                        className="inline-flex rounded px-2 py-1 text-xs font-medium bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-50"
                    >
                        {loading === 'resolve' ? '…' : 'Resolve (Manual)'}
                    </button>
                </div>
            </td>
        </tr>
    )
}

const TABS = [
    { id: 'overview', label: 'Overview', icon: ChartBarSquareIcon },
    { id: 'incidents', label: 'Incidents', icon: ExclamationTriangleIcon },
    { id: 'reliability', label: 'Reliability Metrics', icon: ChartBarIcon },
    { id: 'failed-jobs', label: 'Failed Jobs', icon: ServerStackIcon },
]

export default function OperationsCenterIndex({
    auth,
    tab = 'overview',
    incidents,
    failedJobs,
    queueHealth,
    schedulerHealth,
    reliabilityMetrics,
    horizonAvailable,
    horizonUrl,
}) {
    const [selectedIds, setSelectedIds] = useState(new Set())
    const [bulkLoading, setBulkLoading] = useState(null)
    const [quickViewData, setQuickViewData] = useState(null)
    const [quickViewLoading, setQuickViewLoading] = useState(false)
    const selectAllRef = useRef(null)

    const openAssetQuickView = (assetId) => {
        setQuickViewData(null)
        setQuickViewLoading(true)
        axios.get(`/app/admin/assets/${assetId}`)
            .then((r) => setQuickViewData(r.data))
            .catch(() => setQuickViewData(null))
            .finally(() => setQuickViewLoading(false))
    }

    const closeQuickView = () => {
        setQuickViewData(null)
    }

    const runAssetAction = async (assetId, action) => {
        try {
            if (action === 'repair') {
                await axios.post(`/app/admin/assets/${assetId}/repair`)
            } else if (action === 'retry-pipeline') {
                await axios.post(`/app/admin/assets/${assetId}/retry-pipeline`)
            } else if (action === 'restore') {
                await axios.post(`/app/admin/assets/${assetId}/restore`)
            }
            closeQuickView()
            router.reload({ only: ['incidents'] })
        } catch (e) {
            console.error(e)
            alert(e?.response?.data?.message || e?.message || 'Action failed.')
        }
    }

    const incidentList = incidents || []
    const allSelected = incidentList.length > 0 && selectedIds.size === incidentList.length
    const someSelected = selectedIds.size > 0

    useEffect(() => {
        if (selectAllRef.current) {
            selectAllRef.current.indeterminate = someSelected && !allSelected
        }
    }, [someSelected, allSelected])

    const setTab = (t) => router.get(route('admin.operations-center.index'), { tab: t }, { preserveState: true })

    const toggleSelect = (id, checked) => {
        setSelectedIds((prev) => {
            const next = new Set(prev)
            if (checked) next.add(id)
            else next.delete(id)
            return next
        })
    }

    const toggleSelectAll = (checked) => {
        if (checked) setSelectedIds(new Set(incidentList.map((i) => i.id)))
        else setSelectedIds(new Set())
    }

    const runBulkAction = async (action) => {
        const ids = Array.from(selectedIds)
        if (ids.length === 0) return
        setBulkLoading(action)
        try {
            const res = await axios.post('/app/admin/incidents/bulk-actions', { action, incident_ids: ids })
            const data = res?.data ?? {}
            setSelectedIds(new Set())
            router.reload({ only: ['incidents'] })
            if (action === 'create-ticket' && (data.failed_count ?? 0) > 0) {
                const created = data.ticket_ids?.length ?? 0
                const err = (data.results ?? []).find((r) => !r.ok)
                alert(`Created ${created} ticket(s). ${data.failed_count} failed: ${err?.error ?? 'unknown'}`)
            }
        } catch (e) {
            console.error(e)
            alert(e?.response?.data?.error || e?.message || 'Bulk action failed.')
        } finally {
            setBulkLoading(null)
        }
    }

    const getStatusBadge = (status) => {
        switch (status) {
            case 'healthy':
                return { label: 'Healthy', className: 'bg-green-100 text-green-800', icon: CheckCircleIcon }
            case 'warning':
            case 'delayed':
                return { label: status === 'delayed' ? 'Delayed' : 'Warning', className: 'bg-amber-100 text-amber-800', icon: ExclamationTriangleIcon }
            case 'unhealthy':
            case 'not_running':
                return { label: 'Unhealthy', className: 'bg-red-100 text-red-800', icon: XCircleIcon }
            default:
                return { label: 'Unknown', className: 'bg-gray-100 text-gray-800', icon: ExclamationCircleIcon }
        }
    }

    const formatDate = (d) => {
        if (!d) return '—'
        try {
            return new Date(d).toLocaleString()
        } catch (e) {
            return d
        }
    }

    const queueStatus = getStatusBadge(queueHealth?.status || 'unknown')
    const schedulerStatus = getStatusBadge(schedulerHealth?.status || 'unknown')
    const QueueStatusIcon = queueStatus.icon
    const SchedulerStatusIcon = schedulerStatus.icon

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <Link href="/app/admin" className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block">
                        ← Back to Admin Dashboard
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight text-gray-900">Operations Center</h1>
                    <p className="mt-2 text-sm text-gray-700">
                        Unified view of incidents, queue, scheduler, and failed jobs. Data from system_incidents and failed_jobs.
                    </p>

                    {/* Tabs */}
                    <div className="mt-6 border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8">
                            {TABS.map((t) => (
                                <button
                                    key={t.id}
                                    onClick={() => setTab(t.id)}
                                    className={`whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm ${
                                        tab === t.id
                                            ? 'border-indigo-500 text-indigo-600'
                                            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                    }`}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </nav>
                    </div>

                    {/* Tab content */}
                    <div className="mt-6">
                        {tab === 'overview' && (
                            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                                <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <QueueListIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Queue</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${queueStatus.className}`}>
                                            <QueueStatusIcon className="h-4 w-4 mr-1" />
                                            {queueStatus.label}
                                        </span>
                                    </div>
                                    <div className="mt-4 grid grid-cols-2 gap-4">
                                        <div>
                                            <span className="text-sm text-gray-500">Pending</span>
                                            <p className="text-lg font-semibold">{queueHealth?.pending_count ?? 0}</p>
                                        </div>
                                        <div>
                                            <span className="text-sm text-gray-500">Failed</span>
                                            <p className="text-lg font-semibold text-red-600">{queueHealth?.failed_count ?? 0}</p>
                                        </div>
                                    </div>
                                    {horizonAvailable && horizonUrl && (
                                        <a href={horizonUrl} target="_blank" rel="noopener noreferrer" className="mt-4 inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800">
                                            <LinkIcon className="h-4 w-4 mr-1" /> Open Horizon
                                        </a>
                                    )}
                                </div>
                                <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <ClockIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Scheduler</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${schedulerStatus.className}`}>
                                            <SchedulerStatusIcon className="h-4 w-4 mr-1" />
                                            {schedulerStatus.label}
                                        </span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500">
                                        Last heartbeat: {schedulerHealth?.last_heartbeat ? formatDate(schedulerHealth.last_heartbeat) : 'Never'}
                                    </p>
                                </div>
                                <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6 lg:col-span-2">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <ExclamationTriangleIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Incidents</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                            incidentList.length === 0 ? 'bg-green-100 text-green-800' : incidentList.length > 10 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'
                                        }`}>
                                            {incidentList.length} unresolved
                                        </span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500">
                                        {incidentList.length === 0
                                            ? 'No open incidents. System reliability is healthy.'
                                            : 'View the Incidents tab for details and actions.'}
                                    </p>
                                    {incidentList.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => setTab('incidents')}
                                            className="mt-4 inline-flex rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                                        >
                                            View Incidents
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}

                        {tab === 'incidents' && (
                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                                <div className="px-4 py-4 sm:px-6 flex items-center justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">Unresolved Incidents</h2>
                                        <p className="mt-1 text-sm text-gray-500">{incidentList.length} unresolved</p>
                                    </div>
                                    {someSelected && (
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm text-gray-600">{selectedIds.size} selected</span>
                                            <button
                                                type="button"
                                                disabled={!!bulkLoading}
                                                onClick={() => runBulkAction('attempt-repair')}
                                                className="inline-flex rounded px-3 py-1.5 text-sm font-medium bg-indigo-100 text-indigo-800 hover:bg-indigo-200 disabled:opacity-50"
                                            >
                                                {bulkLoading === 'attempt-repair' ? '…' : 'Attempt Repair All'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={!!bulkLoading}
                                                onClick={() => runBulkAction('create-ticket')}
                                                className="inline-flex rounded px-3 py-1.5 text-sm font-medium bg-amber-100 text-amber-800 hover:bg-amber-200 disabled:opacity-50"
                                            >
                                                {bulkLoading === 'create-ticket' ? '…' : 'Create Tickets'}
                                            </button>
                                            <button
                                                type="button"
                                                disabled={!!bulkLoading}
                                                onClick={() => runBulkAction('resolve')}
                                                className="inline-flex rounded px-3 py-1.5 text-sm font-medium bg-gray-100 text-gray-800 hover:bg-gray-200 disabled:opacity-50"
                                            >
                                                {bulkLoading === 'resolve' ? '…' : 'Resolve All'}
                                            </button>
                                        </div>
                                    )}
                                </div>
                                <div className="border-t border-gray-200 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-300">
                                        <thead>
                                            <tr>
                                                <th className="py-3.5 pl-4 pr-2">
                                                    <input
                                                        type="checkbox"
                                                        ref={selectAllRef}
                                                        checked={allSelected}
                                                        onChange={(e) => toggleSelectAll(e.target.checked)}
                                                        className="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                </th>
                                                <th className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Severity</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Title</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Source</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Detected</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Repair attempts</th>
                                                <th className="py-3.5 px-3 text-right text-sm font-semibold text-gray-900">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {incidentList.map((i) => (
                                                <IncidentRow
                                                    key={i.id}
                                                    incident={i}
                                                    onAction={() => router.reload({ only: ['incidents'] })}
                                                    selected={selectedIds.has(i.id)}
                                                    onSelect={(checked) => toggleSelect(i.id, checked)}
                                                    onSourceClick={openAssetQuickView}
                                                />
                                            ))}
                                        </tbody>
                                    </table>
                                    {incidentList.length === 0 && (
                                        <p className="py-8 text-center text-sm text-gray-500">No unresolved incidents</p>
                                    )}
                                </div>
                            </div>
                        )}

                        {tab === 'reliability' && (
                            <div className="space-y-6">
                                {(() => {
                                    const integrity = reliabilityMetrics?.integrity ?? {}
                                    const mttr = reliabilityMetrics?.mttr ?? {}
                                    const recovery = reliabilityMetrics?.recovery_success ?? {}
                                    const escalation = reliabilityMetrics?.ticket_escalation ?? {}
                                    return (
                                        <>
                                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                                <h3 className="text-sm font-medium text-gray-900">Visual Metadata Integrity</h3>
                                                <p className="mt-2 text-sm text-gray-500">
                                                    % of eligible assets where visualMetadataReady. SLO: {integrity?.slo_target_percent ?? 95}%.
                                                </p>
                                                <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                                                    <div>
                                                        <span className="text-sm text-gray-500">Integrity rate</span>
                                                        <p className={`text-lg font-semibold ${(integrity?.rate_percent ?? 100) >= 95 ? '' : 'text-amber-600'}`}>
                                                            {integrity?.rate_percent ?? 100}%
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <span className="text-sm text-gray-500">Eligible</span>
                                                        <p className="text-lg font-semibold">{integrity?.eligible ?? 0}</p>
                                                    </div>
                                                    <div>
                                                        <span className="text-sm text-gray-500">Invalid</span>
                                                        <p className={`text-lg font-semibold ${(integrity?.invalid ?? 0) > 0 ? 'text-amber-600' : ''}`}>
                                                            {integrity?.invalid ?? 0}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <span className="text-sm text-gray-500">Open incidents</span>
                                                        <p className="text-lg font-semibold">{integrity?.incidents_count ?? 0}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                                <h3 className="text-sm font-medium text-gray-900">Mean Time To Repair (MTTR)</h3>
                                                <p className="mt-2 text-sm text-gray-500">
                                                    Average resolution time (detected_at → resolved_at) in last {mttr?.window_hours ?? 24}h.
                                                </p>
                                                <div className="mt-4 grid grid-cols-2 gap-4">
                                                    <div>
                                                        <span className="text-sm text-gray-500">MTTR (avg min)</span>
                                                        <p className="text-lg font-semibold">
                                                            {mttr?.mttr_minutes_avg != null ? `${Math.round(mttr.mttr_minutes_avg)} min` : '—'}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <span className="text-sm text-gray-500">Resolved (24h)</span>
                                                        <p className="text-lg font-semibold">{mttr?.resolved_count_24h ?? 0}</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                                <h3 className="text-sm font-medium text-gray-900">Recovery Success Rate</h3>
                                                <p className="mt-2 text-sm text-gray-500">
                                                    % of resolved incidents that were auto-recovered.
                                                </p>
                                                <div className="mt-4 grid grid-cols-2 gap-4">
                                                    <div>
                                                        <span className="text-sm text-gray-500">Auto-recovered</span>
                                                        <p className="text-lg font-semibold">{recovery?.auto_resolved_count ?? 0}</p>
                                                    </div>
                                                    <div>
                                                        <span className="text-sm text-gray-500">Recovery rate</span>
                                                        <p className="text-lg font-semibold">{recovery?.recovery_rate_percent ?? 0}%</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                                <h3 className="text-sm font-medium text-gray-900">Ticket Escalation</h3>
                                                <p className="mt-2 text-sm text-gray-500">
                                                    Incidents escalated to tickets in last {escalation?.window_hours ?? 24}h.
                                                </p>
                                                <div className="mt-4 grid grid-cols-2 gap-4">
                                                    <div>
                                                        <span className="text-sm text-gray-500">Escalated (24h)</span>
                                                        <p className="text-lg font-semibold">{escalation?.escalated_count_24h ?? 0}</p>
                                                    </div>
                                                    <div>
                                                        <span className="text-sm text-gray-500">Unresolved</span>
                                                        <p className="text-lg font-semibold">{escalation?.unresolved_count ?? 0}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </>
                                    )
                                })()}
                            </div>
                        )}

                        {tab === 'failed-jobs' && (
                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                                <div className="px-4 py-4 sm:px-6">
                                    <h2 className="text-lg font-semibold text-gray-900">Failed Jobs (Horizon / DB)</h2>
                                    <p className="mt-1 text-sm text-gray-500">Recent failed jobs from failed_jobs table</p>
                                </div>
                                <div className="border-t border-gray-200 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-300">
                                        <thead>
                                            <tr>
                                                <th className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Job</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Queue</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Failed At</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Exception</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {(failedJobs || []).map((j) => (
                                                <tr key={j.id}>
                                                    <td className="py-3 pl-4 pr-3 text-sm font-mono">{j.job_name}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-500">{j.queue}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-500">{formatDate(j.failed_at)}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-600 max-w-xs truncate" title={j.exception}>{j.exception}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {(!failedJobs || failedJobs.length === 0) && (
                                        <p className="py-8 text-center text-sm text-gray-500">No failed jobs</p>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </main>

            {/* Asset Quick View Modal (from source click in Incidents) */}
            {(quickViewData !== null || quickViewLoading) && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={closeQuickView}>
                    <div
                        className="max-h-[90vh] w-full max-w-4xl overflow-auto rounded-xl bg-white shadow-xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        {quickViewLoading ? (
                            <div className="p-12 text-center">Loading…</div>
                        ) : quickViewData ? (
                            <AssetDetailModal
                                data={quickViewData}
                                onClose={closeQuickView}
                                onAction={runAssetAction}
                                onRefresh={() => { closeQuickView(); router.reload({ only: ['incidents'] }) }}
                                showThumbnail
                            />
                        ) : null}
                    </div>
                </div>
            )}

            <AppFooter />
        </div>
    )
}
