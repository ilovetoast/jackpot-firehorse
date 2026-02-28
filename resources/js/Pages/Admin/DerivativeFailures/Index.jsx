import { useState } from 'react'
import { Link, router } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout'
import {
    PhotoIcon,
    XMarkIcon,
    ChevronRightIcon,
    ArrowTopRightOnSquareIcon,
} from '@heroicons/react/24/outline'

export default function DerivativeFailuresIndex({
    auth,
    failures,
    stats,
    groupedByProcessor,
    groupedByDerivativeType,
    groupedByCodec,
    filters,
}) {
    const [drawerOpen, setDrawerOpen] = useState(false)
    const [drawerData, setDrawerData] = useState(null)
    const [drawerLoading, setDrawerLoading] = useState(false)

    const openDrawer = (failureId) => {
        setDrawerOpen(true)
        setDrawerData(null)
        setDrawerLoading(true)
        fetch(`/app/admin/derivative-failures/${failureId}`)
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
        router.get(route('admin.derivative-failures.index'), {
            ...filters,
            [key]: value,
            page: 1,
        })
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
                        Derivative Failures
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
            <AppHead title="Derivative Failures" suffix="Admin" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Stats */}
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Total failures</p>
                            <p className="text-2xl font-semibold text-gray-900">{stats?.total ?? 0}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Escalated</p>
                            <p className="text-2xl font-semibold text-gray-900">{stats?.escalated ?? 0}</p>
                        </div>
                    </div>

                    {/* Grouped views */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-700 mb-3">By processor</p>
                            <ul className="space-y-1.5">
                                {(groupedByProcessor || []).map((g) => (
                                    <li key={g.processor} className="flex justify-between text-sm">
                                        <button
                                            type="button"
                                            onClick={() => handleFilter('processor', filters?.processor === g.processor ? '' : g.processor)}
                                            className={`text-left hover:text-indigo-600 ${filters?.processor === g.processor ? 'font-medium text-indigo-600' : 'text-gray-600'}`}
                                        >
                                            {g.processor}
                                        </button>
                                        <span className="text-gray-500">{g.count}</span>
                                    </li>
                                ))}
                                {(!groupedByProcessor || groupedByProcessor.length === 0) && (
                                    <li className="text-sm text-gray-400">No data</li>
                                )}
                            </ul>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-700 mb-3">By derivative type</p>
                            <ul className="space-y-1.5">
                                {(groupedByDerivativeType || []).map((g) => (
                                    <li key={g.derivative_type} className="flex justify-between text-sm">
                                        <button
                                            type="button"
                                            onClick={() => handleFilter('derivative_type', filters?.derivative_type === g.derivative_type ? '' : g.derivative_type)}
                                            className={`text-left hover:text-indigo-600 ${filters?.derivative_type === g.derivative_type ? 'font-medium text-indigo-600' : 'text-gray-600'}`}
                                        >
                                            {g.derivative_type}
                                        </button>
                                        <span className="text-gray-500">{g.count}</span>
                                    </li>
                                ))}
                                {(!groupedByDerivativeType || groupedByDerivativeType.length === 0) && (
                                    <li className="text-sm text-gray-400">No data</li>
                                )}
                            </ul>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-700 mb-3">By codec</p>
                            <ul className="space-y-1.5">
                                {(groupedByCodec || []).slice(0, 10).map((g) => (
                                    <li key={g.codec} className="flex justify-between text-sm">
                                        <button
                                            type="button"
                                            onClick={() => handleFilter('codec', filters?.codec === g.codec ? '' : g.codec)}
                                            className={`text-left hover:text-indigo-600 truncate max-w-[120px] ${filters?.codec === g.codec ? 'font-medium text-indigo-600' : 'text-gray-600'}`}
                                            title={g.codec}
                                        >
                                            {g.codec || '—'}
                                        </button>
                                        <span className="text-gray-500 flex-shrink-0">{g.count}</span>
                                    </li>
                                ))}
                                {(!groupedByCodec || groupedByCodec.length === 0) && (
                                    <li className="text-sm text-gray-400">No data</li>
                                )}
                            </ul>
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
                        </div>
                    </div>

                    {/* Table */}
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg border border-gray-200">
                        {!failures.data?.length ? (
                            <div className="p-12 text-center">
                                <PhotoIcon className="mx-auto h-12 w-12 text-gray-400" />
                                <h3 className="mt-2 text-sm font-medium text-gray-900">No derivative failures</h3>
                                <p className="mt-1 text-sm text-gray-500">
                                    No thumbnail, preview, or poster failures recorded.
                                </p>
                            </div>
                        ) : (
                            <>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Asset ID</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Processor</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reason</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Count</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">AI severity</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Escalated</th>
                                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last failed</th>
                                                <th className="px-4 py-3"></th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {failures.data.map((f) => (
                                                <tr
                                                    key={f.id}
                                                    className="hover:bg-gray-50 cursor-pointer"
                                                    onClick={() => openDrawer(f.id)}
                                                >
                                                    <td className="px-4 py-3 text-sm text-gray-600">{f.id}</td>
                                                    <td className="px-4 py-3 text-sm">
                                                        {f.asset_id ? (
                                                            <Link
                                                                href={`/app/admin/assets?asset_id=${encodeURIComponent(f.asset_id)}`}
                                                                onClick={(e) => e.stopPropagation()}
                                                                className="font-mono text-indigo-600 hover:text-indigo-800"
                                                            >
                                                                {f.asset_id.slice(0, 8)}…
                                                            </Link>
                                                        ) : (
                                                            <span className="font-mono text-gray-900">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{f.derivative_type ?? '—'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{f.processor ?? '—'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{f.failure_reason ?? '—'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-700">{f.failure_count ?? 0}</td>
                                                    <td className="px-4 py-3">
                                                        {f.ai_severity ? (
                                                            <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${getSeverityBadgeColor(f.ai_severity)}`}>
                                                                {f.ai_severity}
                                                            </span>
                                                        ) : (
                                                            <span className="text-gray-400">—</span>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm">{f.escalated ? 'Yes' : 'No'}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-600">{formatDate(f.last_failed_at)}</td>
                                                    <td className="px-4 py-3">
                                                        <ChevronRightIcon className="h-4 w-4 text-gray-400" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {(failures.prev_page_url || failures.next_page_url) && (
                                    <div className="px-4 py-3 border-t border-gray-200 flex justify-between">
                                        {failures.prev_page_url && (
                                            <Link
                                                href={failures.prev_page_url}
                                                className="text-sm text-indigo-600 hover:text-indigo-800"
                                            >
                                                ← Previous
                                            </Link>
                                        )}
                                        <span className="text-sm text-gray-500">
                                            Page {failures.current_page} of {failures.last_page}
                                        </span>
                                        {failures.next_page_url && (
                                            <Link
                                                href={failures.next_page_url}
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
                            <h3 className="text-lg font-semibold text-gray-900">Derivative Failure Details</h3>
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
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">ID</p>
                                            <p className="text-sm text-gray-900">{drawerData.id}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Asset ID</p>
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <p className="font-mono text-sm text-gray-900">{drawerData.asset_id}</p>
                                                {drawerData.asset_id && (
                                                    <Link
                                                        href={`/app/admin/assets?asset_id=${encodeURIComponent(drawerData.asset_id)}`}
                                                        className="inline-flex items-center gap-1 text-sm text-indigo-600 hover:text-indigo-800"
                                                    >
                                                        View asset
                                                        <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                                                    </Link>
                                                )}
                                            </div>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Derivative type</p>
                                            <p className="text-sm text-gray-900">{drawerData.derivative_type ?? '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">Processor</p>
                                            <p className="text-sm text-gray-900">{drawerData.processor ?? '—'}</p>
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
                                            <p className="text-xs font-medium text-gray-500 uppercase">Codec</p>
                                            <p className="text-sm text-gray-900">{drawerData.codec ?? '—'}</p>
                                        </div>
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase">MIME</p>
                                            <p className="text-sm text-gray-900">{drawerData.mime ?? '—'}</p>
                                        </div>
                                    </div>
                                    {drawerData.failure_trace && (
                                        <div>
                                            <p className="text-xs font-medium text-gray-500 uppercase mb-1">Exception trace</p>
                                            <pre className="text-xs bg-gray-50 p-3 rounded overflow-x-auto max-h-48 overflow-y-auto whitespace-pre-wrap font-mono text-gray-800">
                                                {drawerData.failure_trace}
                                            </pre>
                                        </div>
                                    )}
                                    {(drawerData.ai_summary || drawerData.ai_severity) && (
                                        <div className="space-y-2">
                                            <p className="text-xs font-medium text-gray-500 uppercase">AI assessment</p>
                                            {drawerData.ai_summary && (
                                                <p className="text-sm text-gray-900">{drawerData.ai_summary}</p>
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
