import { Link, router } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AdminAiCenterPage from '../../../Components/Admin/AdminAiCenterPage'

function levelBadgeClass(level) {
    const l = (level || '').toLowerCase()
    if (l === 'high') return 'bg-emerald-100 text-emerald-800'
    if (l === 'medium') return 'bg-amber-100 text-amber-800'
    if (l === 'low') return 'bg-red-100 text-red-800'
    return 'bg-slate-100 text-slate-700'
}

export default function BrandIntelligenceIndex({ rows }) {
    const data = rows?.data ?? []

    return (
        <>
            <AppHead title="Brand Intelligence (admin)" suffix="Admin" />
            <AdminAiCenterPage
                breadcrumbs={[
                    { label: 'Admin', href: '/app/admin' },
                    { label: 'AI Control Center', href: '/app/admin/ai' },
                    { label: 'Brand intelligence' },
                ]}
                title="Brand intelligence"
                description="Recent assets with EBI scores (developer / tuning view)."
            >
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    Asset
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    Category
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    Level
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    Confidence
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    Engine version
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    Updated
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-500">
                                        No brand intelligence scores yet.
                                    </td>
                                </tr>
                            )}
                            {data.map((row) => (
                                <tr key={row.asset_id} className="hover:bg-slate-50/80">
                                    <td className="px-4 py-3">
                                        <Link
                                            href={`/app/admin/brand-intelligence/assets/${row.asset_id}`}
                                            className="font-medium text-indigo-600 hover:text-indigo-500"
                                        >
                                            {row.asset_name}
                                        </Link>
                                        <div className="text-xs text-slate-400 font-mono truncate max-w-xs">{row.asset_id}</div>
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-700">{row.category_name}</td>
                                    <td className="px-4 py-3">
                                        <span
                                            className={`inline-flex rounded px-2 py-0.5 text-xs font-medium ${levelBadgeClass(row.level)}`}
                                        >
                                            {row.level ?? '—'}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-sm text-slate-700">{row.confidence ?? '—'}</td>
                                    <td className="px-4 py-3 text-sm text-slate-700">{row.engine_version ?? '—'}</td>
                                    <td className="px-4 py-3 text-sm text-slate-600">
                                        {row.updated_at ? new Date(row.updated_at).toLocaleString() : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {rows?.last_page > 1 && rows?.links && (
                    <nav className="mt-4 flex flex-wrap justify-center gap-1">
                        {rows.links.map((link, i) => (
                            <button
                                key={i}
                                type="button"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                className={`px-3 py-1 rounded text-sm border ${
                                    link.active
                                        ? 'bg-indigo-600 text-white border-indigo-600'
                                        : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                                } disabled:opacity-40`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </nav>
                )}
            </AdminAiCenterPage>
        </>
    )
}
