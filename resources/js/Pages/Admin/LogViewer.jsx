import { useState, useEffect } from 'react'
import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import {
    DocumentTextIcon,
    ServerStackIcon,
    ComputerDesktopIcon,
    RocketLaunchIcon,
} from '@heroicons/react/24/outline'

export default function AdminLogViewer() {
    const { auth } = usePage().props
    const [activeTab, setActiveTab] = useState('web')
    const [logs, setLogs] = useState([])
    const [deployPayload, setDeployPayload] = useState({ path: '', lines: [], error: null })
    const [loading, setLoading] = useState(true)

    useEffect(() => {
        setLoading(true)
        fetch(`/app/admin/logs/${activeTab}`)
            .then((res) => res.json())
            .then((data) => {
                if (data.kind === 'deploy') {
                    setDeployPayload({
                        path: data.path || '',
                        lines: data.lines || [],
                        error: data.error || null,
                    })
                    setLogs([])
                } else {
                    setLogs(data.logs || [])
                    setDeployPayload({ path: '', lines: [], error: null })
                }
                setLoading(false)
            })
            .catch(() => {
                setLogs([])
                setDeployPayload({ path: '', lines: [], error: 'Request failed.' })
                setLoading(false)
            })
    }, [activeTab])

    const formatTimestamp = (ts) => {
        if (!ts) return '-'
        try {
            const date = new Date(ts)
            return date.toLocaleString('en-US', {
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                second: '2-digit',
            })
        } catch (e) {
            return ts
        }
    }

    const levelBadge = (level) => {
        const c = level === 'error' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800'
        return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${c}`}>{level}</span>
    }

    const contextFields = (entry) => {
        const skip = ['timestamp', 'level', 'message']
        return Object.entries(entry || {}).filter(([k]) => !skip.includes(k))
    }

    const isDeploy = activeTab === 'deploy'

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-gray-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 mb-4 inline-block"
                        >
                            ← Back to Admin Dashboard
                        </Link>
                        <h1 className="text-3xl font-bold tracking-tight text-gray-900">Admin Logs</h1>
                        <p className="mt-2 text-sm text-gray-700">
                            Web and worker errors/warnings (Redis, last 50). Deploy shows the last lines of the
                            server deploy script log.
                        </p>
                    </div>

                    {/* Tabs */}
                    <div className="mb-6 border-b border-gray-200">
                        <nav className="-mb-px flex flex-wrap gap-x-6 gap-y-1">
                            <button
                                type="button"
                                onClick={() => setActiveTab('web')}
                                className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium ${
                                    activeTab === 'web'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <ComputerDesktopIcon className="h-5 w-5" />
                                Web Logs
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('worker')}
                                className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium ${
                                    activeTab === 'worker'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <ServerStackIcon className="h-5 w-5" />
                                Worker Logs
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('deploy')}
                                className={`flex items-center gap-2 border-b-2 px-1 py-4 text-sm font-medium ${
                                    activeTab === 'deploy'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                                }`}
                            >
                                <RocketLaunchIcon className="h-5 w-5" />
                                Deploy
                            </button>
                        </nav>
                    </div>

                    {/* Log list */}
                    <div className="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
                        <div className="p-6">
                            {loading ? (
                                <p className="text-sm text-gray-500">Loading...</p>
                            ) : isDeploy ? (
                                <>
                                    {deployPayload.path && (
                                        <p className="mb-3 text-xs text-gray-500 font-mono break-all">
                                            {deployPayload.path}
                                        </p>
                                    )}
                                    {deployPayload.error ? (
                                        <div className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                            {deployPayload.error}
                                        </div>
                                    ) : deployPayload.lines.length === 0 ? (
                                        <div className="flex flex-col items-center justify-center py-12 text-gray-500">
                                            <DocumentTextIcon className="h-12 w-12 mb-4 text-gray-300" />
                                            <p className="text-sm">Deploy log is empty</p>
                                            <p className="mt-1 text-xs">Run a deploy to append output here</p>
                                        </div>
                                    ) : (
                                        <pre className="max-h-[70vh] overflow-auto rounded-md bg-gray-900 p-4 text-left text-xs text-gray-100 whitespace-pre-wrap break-words">
                                            {deployPayload.lines.join('\n')}
                                        </pre>
                                    )}
                                </>
                            ) : logs.length === 0 ? (
                                <div className="flex flex-col items-center justify-center py-12 text-gray-500">
                                    <DocumentTextIcon className="h-12 w-12 mb-4 text-gray-300" />
                                    <p className="text-sm">No recent logs</p>
                                    <p className="mt-1 text-xs">Errors and warnings will appear here when they occur</p>
                                </div>
                            ) : (
                                <ul className="space-y-4 divide-y divide-gray-100">
                                    {logs.map((entry, i) => (
                                        <li key={i} className="pt-4 first:pt-0">
                                            <div className="flex items-start gap-3">
                                                <span className="text-xs text-gray-400 shrink-0">
                                                    {formatTimestamp(entry.timestamp)}
                                                </span>
                                                <span className="shrink-0">{levelBadge(entry.level || 'warning')}</span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-medium text-gray-900">{entry.message || '-'}</p>
                                                    {contextFields(entry).length > 0 && (
                                                        <dl className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600">
                                                            {contextFields(entry).map(([k, v]) => (
                                                                <div key={k} className="flex gap-1">
                                                                    <dt className="font-medium">{k}:</dt>
                                                                    <dd>{typeof v === 'object' ? JSON.stringify(v) : String(v)}</dd>
                                                                </div>
                                                            ))}
                                                        </dl>
                                                    )}
                                                </div>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
