import { Link, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminReliabilitySectionSidebar from '../../../Components/Admin/AdminReliabilitySectionSidebar'
import { ExclamationTriangleIcon, ArrowTopRightOnSquareIcon } from '@heroicons/react/24/outline'

function formatWhen(iso) {
    if (!iso) return '—'
    const d = new Date(iso)
    return d.toLocaleString()
}

export default function AdminAssetProcessingIssuesIndex({ assets, limit }) {
    const { auth } = usePage().props

    return (
        <div className="min-h-full">
            <AppHead title="Asset processing issues" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="reliability"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Reliability Center', href: '/app/admin/reliability' },
                        { label: 'Asset processing issues' },
                    ]}
                    title="Asset processing issues"
                    description={`Assets with failed thumbnails or promotion errors (newest activity first). Showing up to ${limit} rows — add ?limit=250 to the URL to raise the cap (max 500).`}
                    sidebar={<AdminReliabilitySectionSidebar />}
                >
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <Link
                            href="/app/admin/system-status"
                            className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            ← System status
                        </Link>
                        <Link
                            href="/app/admin/reliability?tab=failed-jobs"
                            className="text-sm text-slate-600 hover:text-slate-900"
                        >
                            Queue failed jobs
                        </Link>
                    </div>

                    {!assets?.length ? (
                        <div className="rounded-xl border border-slate-200 bg-white px-6 py-12 text-center text-sm text-slate-500">
                            No assets in this state right now.
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                            <div className="border-b border-slate-100 bg-slate-50 px-4 py-3">
                                <p className="text-sm text-slate-600">
                                    <ExclamationTriangleIcon className="-mt-0.5 mr-1 inline h-4 w-4 text-amber-500" aria-hidden />
                                    Errors below are copied from the database (column + JSON metadata + current version). They
                                    do not include raw worker stack traces unless the job wrote them into these fields.
                                </p>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th className="px-4 py-3">Asset</th>
                                            <th className="px-4 py-3">Issues</th>
                                            <th className="px-4 py-3">Status</th>
                                            <th className="px-4 py-3">Updated</th>
                                            <th className="min-w-[320px] px-4 py-3">Errors (full)</th>
                                            <th className="px-4 py-3">Open</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 bg-white">
                                        {assets.map((row) => (
                                            <tr key={row.id} className="align-top hover:bg-slate-50/80">
                                                <td className="px-4 py-3">
                                                    <div className="font-medium text-slate-900">{row.title}</div>
                                                    <div className="mt-0.5 font-mono text-xs text-slate-500">{row.id}</div>
                                                    {row.original_filename ? (
                                                        <div className="mt-1 text-xs text-slate-600">{row.original_filename}</div>
                                                    ) : null}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {row.issues?.includes('thumbnail_generation_failed') ? (
                                                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                                                Thumbnail failed
                                                            </span>
                                                        ) : null}
                                                        {row.issues?.includes('promotion_failed') ? (
                                                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">
                                                                Promotion failed
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3 text-xs text-slate-600">
                                                    <div>analysis: {row.analysis_status ?? '—'}</div>
                                                    <div>thumb: {row.thumbnail_status ?? '—'}</div>
                                                    {row.current_version ? (
                                                        <div className="mt-1 border-t border-slate-100 pt-1">
                                                            <div className="font-mono text-[10px] text-slate-400">v {row.current_version.id}</div>
                                                            <div>mime: {row.current_version.mime_type ?? '—'}</div>
                                                            <div>pipe: {row.current_version.pipeline_status ?? '—'}</div>
                                                        </div>
                                                    ) : null}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-xs text-slate-500">
                                                    {formatWhen(row.updated_at || row.created_at)}
                                                </td>
                                                <td className="px-4 py-3">
                                                    {row.error_details?.length ? (
                                                        <ul className="space-y-3">
                                                            {row.error_details.map((d, i) => (
                                                                <li key={i}>
                                                                    <div className="text-xs font-medium text-slate-500">{d.source}</div>
                                                                    <pre className="mt-0.5 max-h-48 overflow-auto whitespace-pre-wrap break-words rounded border border-slate-100 bg-slate-50 p-2 font-sans text-xs text-slate-800">
                                                                        {d.message}
                                                                    </pre>
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    ) : (
                                                        <span className="text-slate-400">No structured errors</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <a
                                                        href={row.admin_asset_console_url}
                                                        className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600 hover:text-indigo-800"
                                                    >
                                                        Admin console
                                                        <ArrowTopRightOnSquareIcon className="h-4 w-4" aria-hidden />
                                                    </a>
                                                    <div className="mt-2">
                                                        <Link
                                                            href={`/app/assets?asset=${row.id}`}
                                                            className="text-xs text-slate-500 hover:text-slate-800"
                                                        >
                                                            Open in app
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
