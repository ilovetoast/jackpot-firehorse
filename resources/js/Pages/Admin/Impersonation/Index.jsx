import { Link, router, useForm, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminSupportSectionSidebar from '../../../Components/Admin/AdminSupportSectionSidebar'

function StatusPill({ status }) {
    const map = {
        active: 'bg-emerald-100 text-emerald-900 ring-1 ring-emerald-200',
        ended: 'bg-slate-100 text-slate-800 ring-1 ring-slate-200',
        expired: 'bg-amber-100 text-amber-900 ring-1 ring-amber-200',
    }
    const cls = map[status] || 'bg-gray-100 text-gray-800'
    return <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>{status}</span>
}

function ModePill({ mode, label }) {
    const full = mode === 'full'
    return (
        <span
            className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${
                full ? 'bg-red-100 text-red-900 ring-1 ring-red-200' : 'bg-slate-100 text-slate-800 ring-1 ring-slate-200'
            }`}
            title={mode}
        >
            {label || mode}
        </span>
    )
}

export default function AdminImpersonationIndex({
    capabilities = { view_all_sessions: false, force_end_sessions: false },
    stats = {},
    sessions = [],
    pagination = {},
    filters = {},
    tenant_options = [],
}) {
    const { auth, flash } = usePage().props

    const { data, setData, processing } = useForm({
        status: filters.status ?? 'all',
        mode: filters.mode ?? 'all',
        tenant_id: filters.tenant_id ?? '',
        initiator_user_id: filters.initiator_user_id ?? '',
        target_user_id: filters.target_user_id ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
        search: filters.search ?? '',
    })

    const applyFilters = (e) => {
        e?.preventDefault()
        const q = {}
        Object.entries(data).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined && v !== 'all') {
                q[k] = v
            }
        })
        router.get('/app/admin/impersonation', q, { preserveState: true, replace: true })
    }

    const buildListUrl = (page) => {
        const q = new URLSearchParams()
        Object.entries(data).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined && v !== 'all') {
                q.set(k, String(v))
            }
        })
        if (page > 1) {
            q.set('page', String(page))
        }
        const s = q.toString()
        return s ? `/app/admin/impersonation?${s}` : '/app/admin/impersonation'
    }

    return (
        <div className="min-h-full">
            <AppHead title="Support access" suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="support"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Support', href: '/app/admin/support' },
                        { label: 'Support access' },
                    ]}
                    title="Support access"
                    description="Internal support sessions — start, review audits, and (for site admins) force-end active access."
                    sidebar={<AdminSupportSectionSidebar />}
                >
                    {flash?.success ? (
                        <p className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200">{flash.success}</p>
                    ) : null}
                    {flash?.warning ? (
                        <p className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{flash.warning}</p>
                    ) : null}

                    <div className="mb-6 flex flex-wrap items-center gap-3">
                        <Link
                            href="/app/admin/impersonation/enter"
                            className="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                        >
                            Start support session
                        </Link>
                        <p className="text-sm text-slate-600">
                            Use this only to troubleshoot a customer issue. All access is logged.
                        </p>
                    </div>

                    <div className="mb-6 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <p className="font-medium text-slate-900">Who can see what</p>
                        <p className="mt-1">
                            <strong>site_support</strong> can start <strong>read-only</strong> sessions and review <strong>their own</strong> session
                            history.
                        </p>
                        <p className="mt-2">
                            <strong>site_admin / site_owner</strong> can start read-only or <strong>full</strong> sessions, see <strong>all</strong>{' '}
                            sessions, and force-end active sessions. Customer company admins cannot impersonate from Team.
                        </p>
                    </div>

                    <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Active sessions</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{stats.active ?? 0}</p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Read-only (30d)</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{stats.read_only_30d ?? 0}</p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Full access (30d)</p>
                            <p className="mt-1 text-2xl font-bold text-red-800">{stats.full_30d ?? 0}</p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Closed (30d)</p>
                            <p className="mt-1 text-2xl font-bold text-slate-900">{stats.closed_30d ?? 0}</p>
                        </div>
                    </div>

                    <form onSubmit={applyFilters} className="mb-6 space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <label className="block text-sm">
                                <span className="font-medium text-slate-700">Status</span>
                                <select
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                >
                                    <option value="all">All</option>
                                    <option value="active">Active</option>
                                    <option value="ended">Ended</option>
                                    <option value="expired">Expired</option>
                                </select>
                            </label>
                            <label className="block text-sm">
                                <span className="font-medium text-slate-700">Mode</span>
                                <select
                                    value={data.mode}
                                    onChange={(e) => setData('mode', e.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                >
                                    <option value="all">All</option>
                                    <option value="read_only">read_only</option>
                                    <option value="full">full</option>
                                    <option value="assisted">assisted</option>
                                </select>
                            </label>
                            <label className="block text-sm sm:col-span-2">
                                <span className="font-medium text-slate-700">Company</span>
                                <select
                                    value={data.tenant_id}
                                    onChange={(e) => setData('tenant_id', e.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                >
                                    <option value="">Any</option>
                                    {tenant_options.map((t) => (
                                        <option key={t.id} value={t.id}>
                                            {t.name} ({t.slug})
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {capabilities.view_all_sessions ? (
                                <label className="block text-sm">
                                    <span className="font-medium text-slate-700">Initiator user ID</span>
                                    <input
                                        type="number"
                                        min={1}
                                        value={data.initiator_user_id}
                                        onChange={(e) => setData('initiator_user_id', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                        placeholder="e.g. 42"
                                    />
                                </label>
                            ) : null}
                            <label className="block text-sm">
                                <span className="font-medium text-slate-700">Target user ID</span>
                                <input
                                    type="number"
                                    min={1}
                                    value={data.target_user_id}
                                    onChange={(e) => setData('target_user_id', e.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                    placeholder="e.g. 99"
                                />
                            </label>
                            <label className="block text-sm">
                                <span className="font-medium text-slate-700">Started from</span>
                                <input
                                    type="date"
                                    value={data.date_from}
                                    onChange={(e) => setData('date_from', e.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                />
                            </label>
                            <label className="block text-sm">
                                <span className="font-medium text-slate-700">Started to</span>
                                <input
                                    type="date"
                                    value={data.date_to}
                                    onChange={(e) => setData('date_to', e.target.value)}
                                    className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                />
                            </label>
                        </div>
                        <label className="block text-sm">
                            <span className="font-medium text-slate-700">Search reason or ticket ID</span>
                            <input
                                type="search"
                                value={data.search}
                                onChange={(e) => setData('search', e.target.value)}
                                className="mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm"
                                placeholder="Ticket id, keywords…"
                            />
                        </label>
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                disabled={processing}
                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                            >
                                Apply filters
                            </button>
                            <Link
                                href="/app/admin/impersonation"
                                className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                            >
                                Reset
                            </Link>
                        </div>
                    </form>

                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                        <table className="min-w-full divide-y divide-slate-200">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Status</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Mode</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Tenant</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Target</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Initiator</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Started</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Expires / ended</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Ticket</th>
                                    <th className="px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Reason</th>
                                    <th className="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {sessions.length === 0 && (
                                    <tr>
                                        <td colSpan={10} className="px-3 py-10 text-center text-sm text-slate-500">
                                            No sessions match these filters.
                                        </td>
                                    </tr>
                                )}
                                {sessions.map((s) => (
                                    <tr key={s.id} className="hover:bg-slate-50/80">
                                        <td className="whitespace-nowrap px-3 py-3">
                                            <StatusPill status={s.status} />
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-3">
                                            <ModePill mode={s.mode} label={s.mode_label} />
                                        </td>
                                        <td className="px-3 py-3 text-sm text-slate-800">
                                            {s.tenant ? (
                                                <>
                                                    <span className="font-medium">{s.tenant.name}</span>
                                                    <span className="block text-xs text-slate-500">#{s.tenant.id}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-sm text-slate-800">
                                            {s.target ? (
                                                <>
                                                    <span className="font-medium">{s.target.name}</span>
                                                    <span className="block text-xs text-slate-500">{s.target.email}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="px-3 py-3 text-sm text-slate-800">
                                            {s.initiator ? (
                                                <>
                                                    <span className="font-medium">{s.initiator.name}</span>
                                                    <span className="block text-xs text-slate-500">{s.initiator.email}</span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-3 text-xs text-slate-600">
                                            {s.started_at ? new Date(s.started_at).toLocaleString() : '—'}
                                        </td>
                                        <td className="px-3 py-3 text-xs text-slate-600">
                                            <div>Exp: {s.expires_at ? new Date(s.expires_at).toLocaleString() : '—'}</div>
                                            <div>End: {s.ended_at ? new Date(s.ended_at).toLocaleString() : '—'}</div>
                                        </td>
                                        <td className="max-w-[8rem] truncate px-3 py-3 font-mono text-xs text-slate-700" title={s.ticket_id || ''}>
                                            {s.ticket_id || '—'}
                                        </td>
                                        <td className="max-w-xs truncate px-3 py-3 text-xs text-slate-600" title={s.reason_preview}>
                                            {s.reason_preview}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-3 text-right text-sm">
                                            <Link href={`/app/admin/impersonation/${s.id}`} className="font-medium text-indigo-700 hover:text-indigo-900">
                                                View
                                            </Link>
                                            {s.is_active && capabilities.force_end_sessions ? (
                                                <>
                                                    {' · '}
                                                    <button
                                                        type="button"
                                                        className="font-medium text-red-700 hover:text-red-900"
                                                        onClick={() => {
                                                            if (!window.confirm('Force-end this active session?')) return
                                                            router.post(`/app/admin/impersonation/${s.id}/end`)
                                                        }}
                                                    >
                                                        End
                                                    </button>
                                                </>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {pagination.last_page > 1 ? (
                        <div className="mt-4 flex flex-wrap items-center justify-between gap-2 text-sm text-slate-600">
                            <span>
                                Page {pagination.current_page} of {pagination.last_page} ({pagination.total} total)
                            </span>
                            <div className="flex gap-2">
                                {pagination.current_page > 1 ? (
                                    <Link
                                        href={buildListUrl(pagination.current_page - 1)}
                                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Previous
                                    </Link>
                                ) : null}
                                {pagination.current_page < pagination.last_page ? (
                                    <Link
                                        href={buildListUrl(pagination.current_page + 1)}
                                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50"
                                    >
                                        Next
                                    </Link>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
