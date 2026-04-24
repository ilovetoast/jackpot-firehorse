import { Link, router } from '@inertiajs/react'
import { Fragment, useState, useRef, useEffect } from 'react'
import axios from 'axios'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AssetDetailModal from '../../../Components/Admin/AssetDetailModal'
import {
    CheckCircleIcon,
    ExclamationTriangleIcon,
    ExclamationCircleIcon,
    XCircleIcon,
    ClockIcon,
    QueueListIcon,
    LinkIcon,
    ServerStackIcon,
    ChartBarIcon,
    ChartBarSquareIcon,
    BoltIcon,
    VideoCameraIcon,
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
    { id: 'queue', label: 'Queue', icon: QueueListIcon },
    { id: 'incidents', label: 'Incidents', icon: ExclamationTriangleIcon },
    { id: 'application-errors', label: 'Application errors', icon: BoltIcon },
    { id: 'studio-exports', label: 'Studio video exports', icon: VideoCameraIcon },
    { id: 'reliability', label: 'Reliability Metrics', icon: ChartBarIcon },
    { id: 'failed-jobs', label: 'Failed Jobs', icon: ServerStackIcon },
]

export default function OperationsCenterIndex({
    auth,
    tab = 'overview',
    incidents,
    failedJobs,
    applicationErrors = [],
    queueHealth,
    schedulerHealth,
    reliabilityMetrics,
    horizonAvailable,
    horizonUrl,
    studioVideoExports = {},
}) {
    const [selectedIds, setSelectedIds] = useState(new Set())
    const [bulkLoading, setBulkLoading] = useState(null)
    const [flushFailedLoading, setFlushFailedLoading] = useState(false)
    const [studioExportDeleteId, setStudioExportDeleteId] = useState(null)
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
            } else if (action === 'reanalyze') {
                await axios.post(`/app/admin/assets/${assetId}/reanalyze`)
            } else if (action === 'publish') {
                await axios.post(`/app/admin/assets/${assetId}/publish`)
            } else if (action === 'unpublish') {
                await axios.post(`/app/admin/assets/${assetId}/unpublish`)
            }
            closeQuickView()
            router.reload({ only: ['incidents'] })
        } catch (e) {
            console.error(e)
            alert(e?.response?.data?.message || e?.message || 'Action failed.')
        }
    }

    const incidentList = incidents || []
    const applicationErrorList = applicationErrors || []
    const allSelected = incidentList.length > 0 && selectedIds.size === incidentList.length
    const someSelected = selectedIds.size > 0

    useEffect(() => {
        if (selectAllRef.current) {
            selectAllRef.current.indeterminate = someSelected && !allSelected
        }
    }, [someSelected, allSelected])

    const setTab = (t) => router.get(route('admin.operations-center.index'), { tab: t }, { preserveState: true })

    const deleteStudioExportJobRow = async (jobId) => {
        if (
            !window.confirm(
                'Delete this failed export job row from the database? This only removes the diagnostic record (not the composition). Queued or completed jobs cannot be deleted here.'
            )
        ) {
            return
        }
        setStudioExportDeleteId(jobId)
        try {
            await axios.delete(route('admin.studio-composition-video-export-jobs.destroy', jobId))
            router.reload({ only: ['studioVideoExports'] })
        } catch (e) {
            window.alert(e?.response?.data?.message || e?.message || 'Delete failed.')
        } finally {
            setStudioExportDeleteId(null)
        }
    }

    const flushFailedJobRecords = async () => {
        if (
            !window.confirm(
                'Remove all rows from the failed_jobs table? This only clears history — it does not retry jobs. Equivalent to: php artisan queue:flush'
            )
        ) {
            return
        }
        setFlushFailedLoading(true)
        try {
            const res = await axios.post(route('admin.operations-center.failed-jobs.flush'))
            const msg = res?.data?.message ?? 'Done.'
            window.alert(msg)
            router.reload({ only: ['failedJobs', 'queueHealth'] })
        } catch (e) {
            window.alert(e?.response?.data?.message || e?.message || 'Failed to clear failed job records.')
        } finally {
            setFlushFailedLoading(false)
        }
    }

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
            const dt = new Date(d)
            if (Number.isNaN(dt.getTime())) {
                return String(d)
            }
            return dt.toLocaleString(undefined, {
                dateStyle: 'medium',
                timeStyle: 'medium',
                timeZoneName: 'short',
            })
        } catch (e) {
            return String(d)
        }
    }

    /** Compact local time for Studio export rows (browser timezone). */
    const formatStudioExportWhen = (d) => {
        if (!d) return '—'
        try {
            const dt = new Date(d)
            if (Number.isNaN(dt.getTime())) {
                return String(d)
            }
            return dt.toLocaleString(undefined, {
                month: 'short',
                day: 'numeric',
                year: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                timeZoneName: 'short',
            })
        } catch {
            return String(d)
        }
    }

    /** Hover text: same instant in UTC + which zone the table uses (browser). */
    const formatDateHoverUtc = (d) => {
        if (!d) return ''
        try {
            const dt = new Date(d)
            if (Number.isNaN(dt.getTime())) {
                return ''
            }
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone || 'browser default'
            const utc = `${dt.toISOString().replace('T', ' ').slice(0, 19)} UTC`
            return `Displayed in your browser timezone (${tz}). Same instant: ${utc}`
        } catch {
            return ''
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
                        Unified view of incidents, application errors, Studio composition export failures, queue, scheduler, and failed jobs.
                        Data from system_incidents, application_error_events, studio_composition_video_export_jobs, and failed_jobs.
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
                                <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200 p-6 lg:col-span-2">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center">
                                            <VideoCameraIcon className="h-6 w-6 text-gray-400 mr-3" />
                                            <h3 className="text-sm font-medium text-gray-900">Studio video exports (failed)</h3>
                                        </div>
                                        <span className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                            (studioVideoExports?.last_24h ?? 0) === 0 ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'
                                        }`}>
                                            {studioVideoExports?.last_24h ?? 0} in 24h / {studioVideoExports?.last_7d ?? 0} in 7d
                                        </span>
                                    </div>
                                    <p className="mt-4 text-sm text-gray-500">
                                        <span className="font-medium text-gray-700">Failed jobs only</span> on the tab (stderr inline, optional row delete for cleanup). Counts help spot fleet-wide FFmpeg or blend issues.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => setTab('studio-exports')}
                                        className="mt-4 inline-flex rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-500"
                                    >
                                        View Studio export failures
                                    </button>
                                </div>
                            </div>
                        )}

                        {tab === 'queue' && (
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
                                        <a
                                            href={horizonUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mt-4 inline-flex items-center text-sm text-indigo-600 hover:text-indigo-800"
                                        >
                                            <LinkIcon className="h-4 w-4 mr-1" /> Open Horizon
                                        </a>
                                    )}
                                    {!horizonAvailable && (
                                        <p className="mt-4 text-xs text-gray-500">
                                            Horizon is not installed. Use <code className="rounded bg-gray-100 px-1">php artisan queue:work</code> or your
                                            process manager for workers.
                                        </p>
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
                                <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600 lg:col-span-2">
                                    <p>
                                        For per-job detail (payloads, retries, metrics), use{' '}
                                        {horizonAvailable && horizonUrl ? (
                                            <a href={horizonUrl} className="font-medium text-indigo-600 hover:text-indigo-800" target="_blank" rel="noopener noreferrer">
                                                Horizon
                                            </a>
                                        ) : (
                                            <span className="font-medium text-gray-800">Horizon</span>
                                        )}
                                        . The <span className="font-medium text-gray-800">Failed Jobs</span> tab lists recent hard failures from the{' '}
                                        <code className="rounded bg-gray-200/80 px-1">failed_jobs</code> table.
                                    </p>
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

                        {tab === 'application-errors' && (
                            <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                                <div className="px-4 py-4 sm:px-6">
                                    <h2 className="text-lg font-semibold text-gray-900">Application errors</h2>
                                    <p className="mt-1 text-sm text-gray-500">
                                        User-impacting errors that are not queue hard-failures (for example AI provider overload). Studio
                                        canvas worker / Playwright dependency issues appear as category{' '}
                                        <span className="font-mono text-gray-700">studio_worker_infra</span>. Newest first.
                                    </p>
                                </div>
                                <div className="border-t border-gray-200 overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-300">
                                        <thead>
                                            <tr>
                                                <th className="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900">When</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Category</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Code</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Tenant</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Source</th>
                                                <th className="py-3.5 px-3 text-left text-sm font-semibold text-gray-900">Message</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {applicationErrorList.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="whitespace-nowrap py-3 pl-4 pr-3 text-sm text-gray-500">
                                                        {formatDate(row.created_at)}
                                                    </td>
                                                    <td className="whitespace-nowrap py-3 px-3 text-sm text-gray-900">{row.category}</td>
                                                    <td className="whitespace-nowrap py-3 px-3 text-sm text-gray-500">{row.code || '—'}</td>
                                                    <td className="py-3 px-3 text-sm text-gray-700 max-w-[14rem]">
                                                        {row.tenant_id != null ? (
                                                            <span className="block">
                                                                <span className="font-medium text-gray-900">
                                                                    {row.tenant_name || `Tenant #${row.tenant_id}`}
                                                                </span>
                                                                {row.tenant_slug ? (
                                                                    <span className="block text-xs text-gray-500 truncate" title={row.tenant_slug}>
                                                                        {row.tenant_slug}
                                                                    </span>
                                                                ) : (
                                                                    <span className="block text-xs text-gray-500">ID {row.tenant_id}</span>
                                                                )}
                                                            </span>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                    <td className="py-3 px-3 text-sm text-gray-500">
                                                        {row.source_type}/{row.source_id || '—'}
                                                    </td>
                                                    <td className="py-3 px-3 text-sm text-gray-900 max-w-md">
                                                        <span className="line-clamp-3" title={row.message}>
                                                            {row.message}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                    {applicationErrorList.length === 0 && (
                                        <p className="py-8 text-center text-sm text-gray-500">
                                            No application errors recorded yet, or the table has not been migrated on this environment.
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}

                        {tab === 'studio-exports' && (
                            <div className="space-y-6">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div className="rounded-lg bg-white p-5 shadow ring-1 ring-gray-200">
                                        <p className="text-sm font-medium text-gray-500">Failed (24h)</p>
                                        <p className="mt-1 text-2xl font-semibold text-gray-900">{studioVideoExports?.last_24h ?? 0}</p>
                                    </div>
                                    <div className="rounded-lg bg-white p-5 shadow ring-1 ring-gray-200">
                                        <p className="text-sm font-medium text-gray-500">Failed (7d)</p>
                                        <p className="mt-1 text-2xl font-semibold text-gray-900">{studioVideoExports?.last_7d ?? 0}</p>
                                    </div>
                                    <div className="rounded-lg bg-white p-5 shadow ring-1 ring-gray-200 sm:col-span-1">
                                        <p className="text-sm font-medium text-gray-500">Top error codes (7d)</p>
                                        <p className="mt-1 text-sm text-gray-600">
                                            {(studioVideoExports?.by_code ?? []).length === 0
                                                ? '—'
                                                : (studioVideoExports.by_code || []).map((x) => `${x.code} (${x.count})`).join(' · ')}
                                        </p>
                                    </div>
                                </div>
                                <div className="overflow-hidden rounded-lg bg-white shadow ring-1 ring-gray-200">
                                    <div className="px-4 py-4 sm:px-6">
                                        <h2 className="text-lg font-semibold text-gray-900">Failed Studio export jobs</h2>
                                        <p className="mt-1 text-sm text-gray-500">
                                            This list is <span className="font-medium text-gray-800">failed rows only</span> (newest first, up to 100). Successful
                                            exports are not shown here — use tenant tools or asset history for completed MP4s.{' '}
                                            <span className="font-mono text-gray-700">blend</span> marks graphs that used{' '}
                                            <span className="font-mono text-gray-700">blend=all_mode=…</span>. Full <span className="font-mono text-gray-700">filter_complex</span> lives in{' '}
                                            <span className="font-mono text-gray-700">error_json</span> in the database. You may <span className="font-medium text-gray-800">delete</span> a row to
                                            tidy diagnostics (optional); that does not fix the underlying composition.
                                        </p>
                                    </div>
                                    <div className="border-t border-gray-200 overflow-x-auto">
                                        <table className="w-full min-w-[56rem] table-fixed divide-y divide-gray-300">
                                            <thead>
                                                <tr>
                                                    <th className="w-[11rem] py-3.5 pl-4 pr-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">When (local)</th>
                                                    <th className="w-[4.5rem] py-3.5 px-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Status</th>
                                                    <th className="w-[7.5rem] py-3.5 px-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Tenant</th>
                                                    <th className="w-[5rem] py-3.5 px-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Comp.</th>
                                                    <th className="w-[8.5rem] py-3.5 px-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Mode</th>
                                                    <th className="w-[9rem] py-3.5 px-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Code</th>
                                                    <th className="min-w-0 py-3.5 px-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600">Message</th>
                                                    <th className="w-[5.5rem] py-3.5 pr-4 pl-2 text-right text-xs font-semibold uppercase tracking-wide text-gray-600">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200">
                                                {(studioVideoExports?.rows ?? []).map((row) => (
                                                    <Fragment key={row.id}>
                                                        <tr className="align-top">
                                                            <td
                                                                className="py-2.5 pl-4 pr-2 align-top text-xs leading-snug text-gray-600"
                                                                title={formatDateHoverUtc(row.updated_at) || undefined}
                                                            >
                                                                {formatStudioExportWhen(row.updated_at)}
                                                            </td>
                                                            <td className="py-2.5 px-2 align-top">
                                                                <span className="inline-flex rounded-full bg-red-50 px-1.5 py-0.5 text-[10px] font-medium text-red-800">
                                                                    {row.status || 'failed'}
                                                                </span>
                                                            </td>
                                                            <td className="min-w-0 py-2.5 px-2 align-top text-xs text-gray-700">
                                                                <span className="block truncate font-medium text-gray-900" title={row.tenant_name || ''}>
                                                                    {row.tenant_name || `Tenant #${row.tenant_id}`}
                                                                </span>
                                                                {row.tenant_slug ? (
                                                                    <span className="block truncate text-[10px] text-gray-500" title={row.tenant_slug}>{row.tenant_slug}</span>
                                                                ) : null}
                                                            </td>
                                                            <td className="py-2.5 px-2 align-top font-mono text-xs text-gray-700">{row.composition_id}</td>
                                                            <td className="min-w-0 py-2.5 px-2 align-top text-xs text-gray-600">
                                                                <span className="break-all">{row.render_mode || '—'}</span>
                                                                {row.has_blend_graph ? (
                                                                    <span className="mt-0.5 inline-block rounded bg-violet-100 px-1 py-0.5 text-[10px] font-medium text-violet-800">blend</span>
                                                                ) : null}
                                                            </td>
                                                            <td className="min-w-0 py-2.5 px-2 align-top text-xs text-gray-600">
                                                                <span className="break-all font-mono text-[10px]">{row.error_code || '—'}</span>
                                                                {row.exit_code != null ? (
                                                                    <span className="block text-[10px] text-gray-500">exit {row.exit_code}</span>
                                                                ) : null}
                                                            </td>
                                                            <td className="min-w-0 py-2.5 px-2 align-top text-xs text-gray-900">
                                                                <p className="break-words hyphens-auto text-left leading-snug" title={row.error_message}>
                                                                    {row.error_message || '—'}
                                                                </p>
                                                            </td>
                                                            <td className="py-2.5 pr-4 pl-2 align-top text-right">
                                                                <button
                                                                    type="button"
                                                                    disabled={studioExportDeleteId === row.id}
                                                                    onClick={() => void deleteStudioExportJobRow(row.id)}
                                                                    className="inline-flex shrink-0 rounded border border-gray-300 bg-white px-2 py-1 text-[11px] font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                                                    aria-label={`Delete failed export job ${row.id}`}
                                                                >
                                                                    {studioExportDeleteId === row.id ? '…' : 'Delete'}
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <tr className="bg-slate-50/90">
                                                            <td colSpan={8} className="min-w-0 px-4 pb-4 pt-0">
                                                                {row.stderr_preview ? (
                                                                    <>
                                                                        <div className="pt-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                                                                            FFmpeg stderr (tail)
                                                                        </div>
                                                                        <pre className="mt-1 max-h-60 overflow-auto whitespace-pre-wrap break-words rounded-md border border-slate-200 bg-white p-3 font-mono text-[11px] leading-snug text-slate-900 shadow-inner">
                                                                            {row.stderr_preview}
                                                                        </pre>
                                                                    </>
                                                                ) : null}
                                                                {row.diagnostics_detail ? (
                                                                    <>
                                                                        <div className={`text-xs font-semibold uppercase tracking-wide text-slate-600 ${row.stderr_preview ? 'mt-3' : 'pt-2'}`}>
                                                                            Structured diagnostics (JSON)
                                                                        </div>
                                                                        <pre className="mt-1 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-md border border-slate-200 bg-white p-3 font-mono text-[11px] leading-snug text-slate-900 shadow-inner">
                                                                            {row.diagnostics_detail}
                                                                        </pre>
                                                                    </>
                                                                ) : null}
                                                                {!row.stderr_preview && !row.diagnostics_detail ? (
                                                                    <p className="pt-2 text-xs text-slate-500">
                                                                        No FFmpeg stderr or structured diagnostics payload for this failure.
                                                                    </p>
                                                                ) : null}
                                                            </td>
                                                        </tr>
                                                    </Fragment>
                                                ))}
                                            </tbody>
                                        </table>
                                        {(studioVideoExports?.rows ?? []).length === 0 && (
                                            <p className="py-8 text-center text-sm text-gray-500">No failed Studio video export jobs in this environment.</p>
                                        )}
                                    </div>
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
                                <div className="px-4 py-4 sm:px-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h2 className="text-lg font-semibold text-gray-900">Failed Jobs (Horizon / DB)</h2>
                                        <p className="mt-1 text-sm text-gray-500">
                                            Recent failed jobs from failed_jobs table. Clearing removes stored failure records only;
                                            use Horizon to retry work if needed.{' '}
                                            <span className="text-gray-600">
                                                Failed at uses your browser’s timezone (abbreviation in the cell); hover for the
                                                same instant in UTC.
                                            </span>
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        disabled={flushFailedLoading || (queueHealth?.failed_count ?? 0) === 0}
                                        onClick={() => void flushFailedJobRecords()}
                                        className="shrink-0 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-900 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        {flushFailedLoading ? 'Clearing…' : 'Clear all failed job records'}
                                    </button>
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
                                                    <td
                                                        className="py-3 px-3 text-sm text-gray-500"
                                                        title={formatDateHoverUtc(j.failed_at) || undefined}
                                                    >
                                                        {formatDate(j.failed_at)}
                                                    </td>
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
                        className="flex max-h-[90vh] w-full max-w-4xl flex-col overflow-hidden rounded-xl bg-white shadow-xl"
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
                                onDetailDataReplace={(d) => setQuickViewData(d)}
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
