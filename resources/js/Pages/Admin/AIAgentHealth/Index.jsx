import { Link } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AuthenticatedLayout from '../../../Layouts/AuthenticatedLayout'
import { BoltIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline'

export default function AIAgentHealthIndex({ auth, lastRunPerAgent, failuresLast24h, bySeverity, stats }) {
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
        if (s === 'info') return 'bg-blue-100 text-blue-800'
        return 'bg-gray-100 text-gray-700'
    }

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        AI Agent Health
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
            <AppHead title="AI Agent Health" suffix="Admin" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Stats */}
                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Runs (24h)</p>
                            <p className="text-2xl font-semibold text-gray-900">{stats?.total_runs_24h ?? 0}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Success (24h)</p>
                            <p className="text-2xl font-semibold text-green-600">{stats?.success_24h ?? 0}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                            <p className="text-sm font-medium text-gray-500">Failures (24h)</p>
                            <p className="text-2xl font-semibold text-red-600">{stats?.failures_24h ?? 0}</p>
                        </div>
                    </div>

                    {/* Breakdown by severity */}
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-6">
                        <h3 className="text-sm font-medium text-gray-700 mb-3">Breakdown by severity</h3>
                        <div className="flex flex-wrap gap-3">
                            {(bySeverity || []).map((s) => (
                                <span
                                    key={s.severity}
                                    className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${getSeverityBadgeColor(s.severity)}`}
                                >
                                    {s.severity}: {s.count}
                                </span>
                            ))}
                            {(!bySeverity || bySeverity.length === 0) && (
                                <span className="text-sm text-gray-400">No severity data</span>
                            )}
                        </div>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Last run per agent */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div className="px-4 py-3 border-b border-gray-200 bg-gray-50">
                                <h3 className="text-sm font-medium text-gray-700">Last run per agent</h3>
                            </div>
                            <div className="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                                {(lastRunPerAgent || []).length === 0 ? (
                                    <div className="p-6 text-center text-sm text-gray-500">
                                        No agent runs recorded.
                                    </div>
                                ) : (
                                    (lastRunPerAgent || []).map((a) => (
                                        <div key={a.agent_id} className="px-4 py-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium text-gray-900 truncate">
                                                        {a.agent_name || a.agent_id}
                                                    </p>
                                                    <p className="text-xs text-gray-500 mt-0.5">
                                                        Last run: {formatDate(a.last_run_at)}
                                                    </p>
                                                    {a.last_summary && (
                                                        <p className="text-sm text-gray-600 mt-1 line-clamp-2">
                                                            {a.last_summary}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-1 flex-shrink-0">
                                                    {a.last_status === 'success' ? (
                                                        <CheckCircleIcon className="h-5 w-5 text-green-500" title="Last run succeeded" />
                                                    ) : (
                                                        <XCircleIcon className="h-5 w-5 text-red-500" title="Last run failed" />
                                                    )}
                                                    {a.last_severity && (
                                                        <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium ${getSeverityBadgeColor(a.last_severity)}`}>
                                                            {a.last_severity}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>

                        {/* Failures in last 24h */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                            <div className="px-4 py-3 border-b border-gray-200 bg-gray-50">
                                <h3 className="text-sm font-medium text-gray-700">Failures in last 24h</h3>
                            </div>
                            <div className="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                                {(failuresLast24h || []).length === 0 ? (
                                    <div className="p-6 text-center text-sm text-gray-500">
                                        No failures in the last 24 hours.
                                    </div>
                                ) : (
                                    (failuresLast24h || []).map((f) => (
                                        <div key={f.id} className="px-4 py-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <p className="font-medium text-gray-900 truncate">
                                                        {f.agent_name || f.agent_id}
                                                    </p>
                                                    <p className="text-xs text-gray-500 mt-0.5">
                                                        {formatDate(f.started_at)}
                                                        {f.entity_type && ` • ${f.entity_type}`}
                                                    </p>
                                                    {(f.summary || f.error_message) && (
                                                        <p className="text-sm text-gray-600 mt-1 line-clamp-2">
                                                            {f.summary || f.error_message}
                                                        </p>
                                                    )}
                                                </div>
                                                {f.severity && (
                                                    <span className={`inline-flex px-2 py-0.5 rounded text-xs font-medium flex-shrink-0 ${getSeverityBadgeColor(f.severity)}`}>
                                                        {f.severity}
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    )
}
