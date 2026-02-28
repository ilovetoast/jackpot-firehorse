import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout'
import {
    ArrowDownTrayIcon,
    ExclamationTriangleIcon,
    XMarkIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline'

export default function DownloadFailuresIndex({ auth, downloads, stats, filters }) {
    const [drawerOpen, setDrawerOpen] = useState(false)
    const [drawerData, setDrawerData] = useState(null)
    const [drawerLoading, setDrawerLoading] = useState(false)

    const openDrawer = (downloadId) => {
        setDrawerOpen(true)
        setDrawerData(null)
        setDrawerLoading(true)
        fetch(`/app/admin/download-failures/${downloadId}`)
            .then(res => res.json())
            .then(data => {
                setDrawerData(data)
                setDrawerLoading(false)
            })
            .catch(() => setDrawerLoading(false))
    }

    const closeDrawer = () => {
        setDrawerOpen(false)
        setDrawerData(null)
    }

    const handleFilter = (key, value) => {
        router.get(route('admin.download-failures.index'), {
            ...filters,
            [key]: value,
            page: 1,
        })
    }

    const formatBytes = (bytes) => {
        if (bytes == null) return '—'
        if (bytes < 1024) return `${bytes} B`
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
    }

    const formatDate = (iso) => {
        if (!iso) return '—'
        const d = new Date(iso)
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString()
    }

    const getSeverityBadgeColor = (severity) => {
        if (!severity) return 'bg-gray-100 text-gray-700'
        const s = String(severity).toLowerCase()
        if (s === 'system') return 'bg-red-100 text-red-800'
        if (s === 'warning') return 'bg-yellow-100 text-yellow-800'
        if (s === 'data') return 'bg-amber-100 text-amber-800'
        return 'bg-gray-100 text-gray-700'
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Download Failures
                    </h2>
                    <Link
                        href="/app/admin"
                        className="text-sm text-gray-500 hover:text-gray-700"
                    >
                        ← Back to Admin
                    </Link>
                </div>
            }
        >
            <AppHead title="Download Failures" suffix="Admin" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Stats */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Failed (last 24h)</p>
                            <p className="text-2xl font-semibold text-gray-900">{stats?.failed_last_24h ?? 0}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Escalated</p>
                            <p className="text-2xl font-semibold text-gray-900">{stats?.escalated ?? 0}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Awaiting review</p>
                            <p className="text-2xl font-semibold text-gray-900">{stats?.awaiting_review ?? 0}</p>
                        </div>
                    </div>

                    {/* Filters */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
                        <div className="flex flex-wrap gap-4">
                            <select
                                value={filters?.escalated ?? ''}
                                onChange={(e) => handleFilter('escalated', e.target.value)}
                                className="border border-gray-300 rounded-md px-3 py-2 text-sm"
                            >
                                <option value="">All</option>
                                <option value="yes">Escalated only</option>
                                <option value="no">Not escalated</option>
                            </select>
                            <select
                                value={filters?.failure_reason ?? ''}
                                onChange={(e) => handleFilter('failure_reason', e.target.value)}
                                className="border border-gray-300 rounded-md px-3 py-2 text-sm"
                            >
                                <option value="">All failure reasons</option>
                                <option value="timeout">Timeout</option>
                                <option value="disk_full">Disk full</option>
                                <option value="s3_read_error">S3 read error</option>
                                <option value="permission_error">Permission error</option>
                                <option value="unknown">Unknown</option>
                            </select>
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                        {!downloads.data?.length ? (
                            <div className="p-12 text-center">
                                <ArrowDownTrayIcon className="mx-auto h-12 w-12 text-gray-400" />
                                <h3 className="mt-2 text-sm font-medium text-gray-900">No failed downloads</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    No ZIP build failures recorded.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Download ID</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tenant</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assets</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total bytes</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failure reason</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failures</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">AI severity</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Escalated</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last failed</th>
                                                <th className="px-4 py-3"></th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {downloads.data.map((d) => (
                                                <tr
                                                    key={d.id}
                                                    className="hover:bg-gray-50 cursor-pointer"
                                                    onClick={() => openDrawer(d.id)}
                                                >
                                                    <td className="px-4 py-3 text-sm font-mono text-gray-900">{d.id.slice(0, 8)}…</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{d.tenant?.name ?? '—'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{d.asset_count ?? '—'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{formatBytes(d.total_bytes)}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{d.failure_reason ?? '—'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{d.failure_count ?? 0}</td>
                                                    <td className="px-4 py-3">
                                                        {d.ai_severity ? (
                                                            <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${getSeverityBadgeColor(d.ai_severity)}`}>
                                                                {d.ai_severity}
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm">{d.escalated ? 'Yes' : 'No'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(d.last_failed_at)}</td>
                                                    <td className="px-4 py-3">
                                                        <ChevronRightIcon className="h-4 w-4 text-gray-400" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {/* Pagination */}
                                {(downloads.prev_page_url || downloads.next_page_url) && (
                                    <div className="px-4 py-3 border-t border-gray-200 flex justify-between">
                                        {downloads.prev_page_url && (
                                            <Link
                                                href={downloads.prev_page_url}
                                                className="text-sm text-indigo-600 hover:text-indigo-800"
                                            >
                                                ← Previous
                                            </Link>
                                        )}
                                        <span className="text-sm text-gray-500">
                                            Page {downloads.current_page} of {downloads.last_page}
                                        </span>
                                        {downloads.next_page_url && (
                                            <Link
                                                href={downloads.next_page_url}
                                                className="text-sm text-indigo-600 hover:text-indigo-800"
                                            >
                                                Next →
                                            </Link>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>

            {/* Detail Drawer (read-only) */}
            {drawerOpen && (
                <div className="fixed inset-0 z-50 overflow-hidden">
                    <div className="absolute inset-0 bg-gray-500/75" onClick={closeDrawer} />
                    <div className="fixed inset-y-0 right-0 max-w-xl w-full bg-white shadow-xl flex flex-col">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                            <h3 className="text-lg font-semibold text-gray-900">Download Failure Details</h3>
                            <button
                                onClick={closeDrawer}
                                className="p-2 text-gray-400 hover:text-gray-600"
                            >
                                <XMarkIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="flex-1 overflow-y-auto p-6">
                            {drawerLoading ? (
                                <div className="animate-pulse space-y-4">
                                    <div className="h-4 bg-gray-200 rounded w-3/4" />
                                    <div className="h-4 bg-gray-200 rounded w-1/2" />
                                    <div className="h-20 bg-gray-200 rounded" />
                                </div>
                            ) : drawerData ? (
                                <div className="space-y-6">
                                    <div>
                                        <p className="text-xs font-medium text-gray-500 uppercase">Download ID</p>
                                        <p className="font-mono text-sm text-gray-900">{drawerData.id}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium text-gray-500 uppercase">Tenant</p>
                                        <p className="text-sm text-gray-900">{drawerData.tenant?.name ?? '—'}</p>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Asset count</p>
                                            <p className="text-sm text-gray-900">{drawerData.asset_count ?? '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Total bytes</p>
                                            <p className="text-sm text-gray-900">{formatBytes(drawerData.total_bytes)}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Failure reason</p>
                                            <p className="text-sm text-gray-900">{drawerData.failure_reason ?? '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Failure count</p>
                                            <p className="text-sm text-gray-900">{drawerData.failure_count ?? 0}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">zip_build_chunk_index</p>
                                            <p className="text-sm text-gray-900">{drawerData.zip_build_chunk_index ?? 0}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">zip_total_chunks</p>
                                            <p className="text-sm text-gray-900">{drawerData.zip_total_chunks ?? '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">zip_last_progress_at</p>
                                            <p className="text-sm text-gray-900">{drawerData.zip_last_progress_at ? formatDate(drawerData.zip_last_progress_at) : '—'}</p>
                                        </div>
                                        {drawerData.last_progress_seconds_ago != null && (
                                            <div>
                                                <p className="text-xs font-medium text-gray-500 uppercase">Last progress</p>
                                                <p className="text-sm text-gray-900">{drawerData.last_progress_seconds_ago} seconds ago</p>
                                            </div>
                                        )}
                                    </div>
                                    {drawerData.failure_trace && (
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase mb-1">Failure trace</p>
                                            <pre className="text-xs bg-gray-50 p-3 rounded overflow-x-auto max-h-48 overflow-y-auto whitespace-pre-wrap font-mono text-gray-800">
                                                {drawerData.failure_trace}
                                            </pre>
                                        </div>
                                    )}
                                    {(drawerData.ai_summary || drawerData.ai_recommendation) && (
                                        <div className="space-y-2">
                                            <p className="text-xs font-medium text-gray-500 uppercase">AI assessment</p>
                                            {drawerData.ai_summary && (
                                                <p className="text-sm text-gray-900">{drawerData.ai_summary}</p>
                                            )}
                                            {drawerData.ai_recommendation && (
                                                <p className="text-sm text-gray-600">Recommendation: {drawerData.ai_recommendation}</p>
                                            )}
                                            {drawerData.ai_severity && (
                                                <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${getSeverityBadgeColor(drawerData.ai_severity)}`}>
                                                    {drawerData.ai_severity}
                                                </span>
                                            )}
                                        </div>
                                    )}
                                    {drawerData.ticket && (
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase mb-1">Linked ticket</p>
                                            <a
                                                href={drawerData.ticket.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-sm text-indigo-600 hover:text-indigo-800"
                                            >
                                                #{drawerData.ticket.id} — {drawerData.ticket.subject} ({drawerData.ticket.status})
                                            </a>
                                        </div>
                                    )}
                                    <div className="pt-4 border-t border-gray-200 text-xs text-gray-500">
                                        <p>Created: {formatDate(drawerData.created_at)}</p>
                                        <p>Last failed: {formatDate(drawerData.last_failed_at)}</p>
                                    </div>
                                </div>
                            ) : (
                                <p className="text-sm text-gray-500">Could not load details.</p>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    )
}
