import { Link, router } from '@inertiajs/react'
import { useState, useRef, useEffect } from 'react'
import axios from 'axios'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    XCircleIcon,
    ClockIcon,
    QueueListIcon,
    ExclamationCircleIcon,
    LinkIcon,
    CubeIcon,
    PhotoIcon,
    ServerStackIcon,
} from '@heroicons/react/24/outline'

function IncidentRow({ incident: i, onAction, selected, onSelect }) {
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
            <td className="py-3 px-3 text-sm text-gray-500">{i.source_type}/{i.source_id || '—'}</td>
            <td className="py-3 px-3 text-sm text-gray-500">{new Date(i.detected_at).toLocaleString()}</td>
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
    { id: 'incidents', label: 'Incidents', icon: ExclamationTriangleIcon },
    { id: 'queue', label: 'Queue Health', icon: QueueListIcon },
    { id: 'scheduler', label: 'Scheduler', icon: ClockIcon },
    { id: 'assets-stalled', label: 'Assets Stalled', icon: PhotoIcon },
    { id: 'derivative-failures', label: 'Derivative Failures', icon: ExclamationCircleIcon },
    { id: 'failed-jobs', label: 'Failed Jobs', icon: ServerStackIcon },
]

export default function OperationsCenterIndex({
    auth,
    tab,
    incidents,
    assetsStalled,
    derivativeFailures,
    failedJobs,
    queueHealth,
    schedulerHealth,
    horizonAvailable,
    horizonUrl,
}) {
    const [selectedIds, setSelectedIds] = useState(new Set())
    const [bulkLoading, setBulkLoading] = useState(null)
    const selectAllRef = useRef(null)
    useEffect(() => {
        if (selectAllRef.current) {
            selectAllRef.current.indeterminate = someSelected && !allSelected
        }
    }, [someSelected, allSelected])

    const setTab = (t) => router.get(route('admin.operations-center.index'), { tab: t }, { preserveState: true })

    const incidentList = incidents || []
    const allSelected = incidentList.length > 0 && selectedIds.size === incidentList.length
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
            router.reload({ only: ['incidents', 'assetsStalled'] })
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
                                                <th className="py-3.5 px-3 text-right text-sm font-semibold text-gray-900">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {incidentList.map((i) => (
                                                <IncidentRow
                                                    key={i.id}
                                                    incident={i}
                                                    onAction={() => router.reload({ only: ['incidents', 'assetsStalled'] })}
                                                    selected={selectedIds.has(i.id)}
                                                    onSelect={(checked) => toggleSelect(i.id, checked)}
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

                        {tab === 'queue' && (
                            <div className="space-y-4">
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
                            </div>
                        )}

                        {tab === 'scheduler' && (
                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center">
                                        <ClockIcon className="h-6 w-6 text-gray-400 mr-3" />
                                        <h3 className="text-sm font-medium text-gray-900">Scheduler Heartbeat</h3>
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
                        )}

                        {tab === 'assets-stalled' && (
                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                                <div className="px-4 py-4 sm:px-6">
                                    <h2 className="text-lg font-semibold text-gray-900">Assets Stalled</h2>
                                    <p className="mt-1 text-sm text-gray-500">{assetsStalled?.length ?? 0} assets stuck in uploading or thumbnail generation</p>
                                </div>
                                <div className="border-t border-gray-200 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-300">
                                        <thead>
                                            <tr>
                                                <th className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Asset ID</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Title</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Detected</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {(assetsStalled || []).map((i) => (
                                                <tr key={i.id}>
                                                    <td className="whitespace-nowrap py-3 pl-4 pr-3 text-sm font-mono">{i.source_id}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-900">{i.title}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-500">{formatDate(i.detected_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {(!assetsStalled || assetsStalled.length === 0) && (
                                        <p className="py-8 text-center text-sm text-gray-500">No stalled assets</p>
                                    )}
                                </div>
                            </div>
                        )}

                        {tab === 'derivative-failures' && (
                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                                <div className="px-4 py-4 sm:px-6">
                                    <h2 className="text-lg font-semibold text-gray-900">Derivative Failures</h2>
                                    <p className="mt-1 text-sm text-gray-500">Thumbnail/preview failures (from incidents)</p>
                                </div>
                                <div className="border-t border-gray-200 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-300">
                                        <thead>
                                            <tr>
                                                <th className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">Asset ID</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Title</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Detected</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {(derivativeFailures || []).map((i) => (
                                                <tr key={i.id}>
                                                    <td className="whitespace-nowrap py-3 pl-4 pr-3 text-sm font-mono">{i.source_id}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-900">{i.title}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-500">{formatDate(i.detected_at)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {(!derivativeFailures || derivativeFailures.length === 0) && (
                                        <p className="py-8 text-center text-sm text-gray-500">No derivative failures</p>
                                    )}
                                </div>
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
            <AppFooter />
        </div>
    )
}
