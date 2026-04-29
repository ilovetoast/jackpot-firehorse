import { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AppHead from '../../../Components/AppHead'
import AdminAiServicesNav from '../../../Components/Admin/AdminAiServicesNav'

function statusClass(status) {
    if (status === 'ready' || status === 'confirmed') return 'bg-emerald-100 text-emerald-900'
    if (status === 'failed') return 'bg-red-100 text-red-900'
    if (status === 'pending') return 'bg-amber-100 text-amber-900'
    return 'bg-slate-100 text-slate-800'
}

function serviceBadge(row) {
    const k = row.service_kind || (row.extraction_method === 'ai' ? 'ai' : row.extraction_method === 'local' ? 'local' : 'unknown')
    if (k === 'ai') {
        return (
            <span className="inline-flex rounded-md bg-violet-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800">
                AI (SAM)
            </span>
        )
    }
    if (k === 'local') {
        return (
            <span className="inline-flex rounded-md bg-slate-200/90 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-800">
                Local
            </span>
        )
    }
    return <span className="text-xs text-slate-500">—</span>
}

export default function AdminStudioLayerExtractionIndex({ rows = [], counts = {}, status_filter: statusFilter, admin_asset_url }) {
    const { auth } = usePage().props
    const [detail, setDetail] = useState(null)
    const [loadingDetail, setLoadingDetail] = useState(false)

    const applyStatus = (s) => {
        router.get(
            '/app/admin/ai/studio-layer-extraction',
            s ? { status: s } : {},
            { preserveState: true, preserveScroll: true }
        )
    }

    const openDetail = async (id) => {
        setLoadingDetail(true)
        setDetail(null)
        try {
            const r = await fetch(`/app/admin/ai/studio-layer-extraction/${id}`)
            if (r.ok) {
                setDetail(await r.json())
            } else {
                setDetail({ error: `HTTP ${r.status}` })
            }
        } catch (e) {
            setDetail({ error: e instanceof Error ? e.message : 'Request failed' })
        } finally {
            setLoadingDetail(false)
        }
    }

    const c = counts || {}

    return (
        <div className="min-h-full">
            <AppHead title="Studio layer extraction (admin)" suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <AdminAiServicesNav />
                    <div className="mb-6">
                        <h1 className="text-3xl font-bold tracking-tight text-slate-900">Studio layer extraction</h1>
                        <p className="mt-1 text-xs text-slate-500">
                            Listed under <strong>Generative &amp; Studio</strong> on the{' '}
                            <a href="/app/admin/ai/analyzed-content" className="text-indigo-600 hover:text-indigo-500">AI services hub</a>
                            . Each session is either <span className="font-medium">Local</span> (floodfill) or{' '}
                            <span className="font-medium">AI (SAM)</span> (Fal) — see the Service column.
                        </p>
                        <p className="mt-2 max-w-3xl text-sm text-slate-600">
                            Recent <strong>Extract layers</strong> sessions (local floodfill and remote Fal SAM). Rows come from{' '}
                            <code className="rounded bg-slate-100 px-1 text-xs">studio_layer_extraction_sessions</code>. Use{' '}
                            <strong>Session JSON</strong> for metadata, staged candidates, and error text when debugging SAM or
                            size limits. Server logs also tag <code className="rounded bg-slate-100 px-1 text-xs">[studio_layer_extraction]</code>{' '}
                            and <code className="rounded bg-slate-100 px-1 text-xs">[studio_layer_extraction_fal]</code>.
                        </p>
                    </div>

                    <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
                        {[
                            { label: 'Total', v: c.total },
                            { label: 'Ready', v: c.ready },
                            { label: 'Failed', v: c.failed },
                            { label: 'Pending', v: c.pending },
                            { label: 'Confirmed', v: c.confirmed },
                        ].map((x) => (
                            <div key={x.label} className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{x.label}</p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{x.v ?? 0}</p>
                            </div>
                        ))}
                    </div>

                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        <span className="text-sm text-slate-600">Filter:</span>
                        <button
                            type="button"
                            onClick={() => applyStatus(null)}
                            className={`rounded-lg px-3 py-1 text-sm font-medium ${!statusFilter ? 'bg-indigo-100 text-indigo-900' : 'bg-white text-slate-700 ring-1 ring-slate-200'}`}
                        >
                            All
                        </button>
                        {['failed', 'ready', 'pending', 'confirmed'].map((s) => (
                            <button
                                key={s}
                                type="button"
                                onClick={() => applyStatus(s)}
                                className={`rounded-lg px-3 py-1 text-sm font-medium capitalize ${statusFilter === s ? 'bg-indigo-100 text-indigo-900' : 'bg-white text-slate-700 ring-1 ring-slate-200'}`}
                            >
                                {s}
                            </button>
                        ))}
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Updated</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Status</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Service</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Tenant</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Method</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Provider / model</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Candidates</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Error</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Source asset</th>
                                        <th className="px-4 py-2 text-left font-medium text-slate-700">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {rows.length === 0 ? (
                                        <tr>
                                            <td colSpan={10} className="px-4 py-10 text-center text-slate-500">
                                                No layer extraction sessions yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        rows.map((row) => (
                                            <tr key={row.id} className="hover:bg-slate-50/80">
                                                <td className="whitespace-nowrap px-4 py-2 text-slate-600">
                                                    {row.updated_at ? new Date(row.updated_at).toLocaleString() : '—'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusClass(row.status)}`}>
                                                        {row.status}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-2">{serviceBadge(row)}</td>
                                                <td className="px-4 py-2 text-slate-700">{row.tenant_name ?? '—'}</td>
                                                <td className="max-w-[8rem] truncate px-4 py-2 font-mono text-xs text-slate-600" title={row.extraction_method || ''}>
                                                    {row.extraction_method ?? '—'}
                                                </td>
                                                <td className="max-w-[12rem] px-4 py-2 text-xs text-slate-600">
                                                    <div className="font-mono truncate" title={row.provider || ''}>
                                                        {row.provider ?? '—'}
                                                    </div>
                                                    <div className="font-mono truncate text-slate-500" title={row.model || ''}>
                                                        {row.model ?? '—'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-2 tabular-nums text-slate-800">{row.candidates_count}</td>
                                                <td className="max-w-[14rem] px-4 py-2 text-xs text-red-700 line-clamp-2" title={row.error_message || ''}>
                                                    {row.error_message ?? '—'}
                                                </td>
                                                <td className="max-w-[12rem] px-4 py-2">
                                                    {row.source_asset_id ? (
                                                        <Link
                                                            href={`${admin_asset_url}?asset_id=${encodeURIComponent(row.source_asset_id)}`}
                                                            className="font-mono text-xs text-indigo-600 hover:text-indigo-800 break-all"
                                                        >
                                                            {row.source_asset_id}
                                                        </Link>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => void openDetail(row.id)}
                                                        className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-medium text-indigo-800 hover:bg-indigo-100"
                                                    >
                                                        Session JSON
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
            <AppFooter />

            {(loadingDetail || detail) && (
                <div
                    className="fixed inset-0 z-[80] flex items-center justify-center bg-black/50 p-4"
                    role="dialog"
                    onClick={() => {
                        setDetail(null)
                        setLoadingDetail(false)
                    }}
                >
                    <div
                        className="max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-xl bg-white shadow-xl"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                            <h2 className="text-lg font-semibold text-slate-900">Session detail</h2>
                            <button
                                type="button"
                                onClick={() => {
                                    setDetail(null)
                                    setLoadingDetail(false)
                                }}
                                className="rounded-md px-2 py-1 text-sm text-slate-600 hover:bg-slate-100"
                            >
                                Close
                            </button>
                        </div>
                        <div className="max-h-[calc(90vh-3.5rem)] overflow-auto p-4">
                            {loadingDetail && <p className="text-sm text-slate-600">Loading…</p>}
                            {!loadingDetail && detail && (
                                <pre className="whitespace-pre-wrap break-words text-xs text-slate-800">
                                    {JSON.stringify(detail, null, 2)}
                                </pre>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </div>
    )
}
