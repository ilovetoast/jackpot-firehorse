import { Link, router, usePage } from '@inertiajs/react'
import AppHead from '../../../Components/AppHead'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import AdminSupportSectionSidebar from '../../../Components/Admin/AdminSupportSectionSidebar'
import { ExclamationTriangleIcon } from '@heroicons/react/24/solid'

function TimelineItem({ row }) {
    const label =
        row.event === 'session_started'
            ? 'Session started'
            : row.event === 'session_ended'
                ? row.meta_cause === 'admin_forced'
                    ? 'Session ended (admin forced)'
                    : row.meta_cause === 'expired'
                        ? 'Session ended (expired)'
                        : 'Session ended'
                : row.event
    return (
        <div className="relative pl-6">
            <span className="absolute left-0 top-1.5 h-2 w-2 rounded-full bg-indigo-500 ring-4 ring-indigo-50" />
            <p className="text-sm font-medium text-slate-900">{label}</p>
            <p className="text-xs text-slate-500">{row.created_at ? new Date(row.created_at).toLocaleString() : '—'}</p>
            {row.meta_admin_user_id ? (
                <p className="mt-0.5 text-xs text-slate-600">Admin user ID: {row.meta_admin_user_id}</p>
            ) : null}
        </div>
    )
}

export default function AdminImpersonationShow({
    session = {},
    audit_timeline = [],
    audit_requests = [],
    capabilities = { force_end_sessions: false },
}) {
    const { auth, flash } = usePage().props
    const isFull = session.mode === 'full'

    const forceEnd = () => {
        if (!window.confirm('Force-end this session? The initiator’s browser will drop impersonation on the next request.')) {
            return
        }
        router.post(`/app/admin/impersonation/${session.id}/end`)
    }

    return (
        <div className="min-h-full">
            <AppHead title={`Support access #${session.id}`} suffix="Admin" />
            <AppNav brand={auth?.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="support"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Support', href: '/app/admin/support' },
                        { label: 'Support access', href: '/app/admin/impersonation' },
                        { label: `#${session.id}` },
                    ]}
                    title={`Session #${session.id}`}
                    description="Internal impersonation record — not visible to tenant users."
                    sidebar={<AdminSupportSectionSidebar />}
                >
                    {flash?.success ? (
                        <p className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200">{flash.success}</p>
                    ) : null}
                    {flash?.warning ? (
                        <p className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{flash.warning}</p>
                    ) : null}

                    <p className="mb-6 text-sm text-slate-600">
                        New sessions are started from{' '}
                        <Link href="/app/admin/impersonation/enter" className="font-medium text-indigo-700 hover:text-indigo-900 underline">
                            Start support session
                        </Link>{' '}
                        (Command Center). Customer company admins cannot start impersonation from Team.
                    </p>

                    <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                        <Link href="/app/admin/impersonation" className="text-sm font-medium text-indigo-700 hover:text-indigo-900">
                            ← Back to list
                        </Link>
                        {session.is_active && capabilities.force_end_sessions ? (
                            <button
                                type="button"
                                onClick={forceEnd}
                                className="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-red-500"
                            >
                                Force-end session
                            </button>
                        ) : null}
                    </div>

                    {isFull ? (
                        <div className="mb-6 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                            <ExclamationTriangleIcon className="h-5 w-5 shrink-0" aria-hidden />
                            <span>
                                <strong>Full access</strong> — this session allowed actions under the target user’s role. Review the
                                reason and audit trail carefully.
                            </span>
                        </div>
                    ) : null}

                    <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-900">Summary</h2>
                        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            <div>
                                <dt className="text-slate-500">Status</dt>
                                <dd className="font-medium text-slate-900">{session.status}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Mode</dt>
                                <dd className="font-medium text-slate-900">
                                    {session.mode_label} ({session.mode})
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Company</dt>
                                <dd className="font-medium text-slate-900">
                                    {session.tenant ? `${session.tenant.name} (#${session.tenant.id})` : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Client IP (session start)</dt>
                                <dd className="font-mono text-xs text-slate-800">{session.ip_address || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Target</dt>
                                <dd className="text-slate-900">
                                    {session.target ? (
                                        <>
                                            {session.target.name} <span className="text-slate-500">({session.target.email})</span>
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Initiator</dt>
                                <dd className="text-slate-900">
                                    {session.initiator ? (
                                        <>
                                            {session.initiator.name}{' '}
                                            <span className="text-slate-500">({session.initiator.email})</span>
                                        </>
                                    ) : (
                                        '—'
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Started</dt>
                                <dd className="text-slate-800">{session.started_at ? new Date(session.started_at).toLocaleString() : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Expires</dt>
                                <dd className="text-slate-800">{session.expires_at ? new Date(session.expires_at).toLocaleString() : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Ended</dt>
                                <dd className="text-slate-800">{session.ended_at ? new Date(session.ended_at).toLocaleString() : '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-slate-500">Duration (approx.)</dt>
                                <dd className="text-slate-800">
                                    {session.duration_seconds != null ? `${Math.round(session.duration_seconds)}s` : '—'}
                                </dd>
                            </div>
                            {session.is_active ? (
                                <div>
                                    <dt className="text-slate-500">Remaining (approx.)</dt>
                                    <dd className="text-slate-800">{session.remaining_seconds != null ? `${session.remaining_seconds}s` : '—'}</dd>
                                </div>
                            ) : null}
                        </dl>
                        <div className="mt-4 border-t border-slate-100 pt-4">
                            <dt className="text-sm font-medium text-slate-700">User agent (session start)</dt>
                            <dd className="mt-1 break-all font-mono text-xs text-slate-600">{session.user_agent || '—'}</dd>
                        </div>
                    </div>

                    <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-900">Ticket / case ID</h2>
                        <p className="mt-2 font-mono text-sm text-slate-800">
                            {String(session.ticket_id ?? '').trim() ? String(session.ticket_id).trim() : '—'}
                        </p>
                    </div>

                    <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-900">Reason</h2>
                        <pre className="mt-3 whitespace-pre-wrap break-words rounded-md bg-slate-50 p-4 text-sm text-slate-800 ring-1 ring-slate-200">
                            {session.reason || '—'}
                        </pre>
                    </div>

                    <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-900">Audit timeline</h2>
                        <p className="mt-1 text-xs text-slate-500">Lifecycle events (excludes per-request noise).</p>
                        <div className="mt-4 space-y-4 border-l border-slate-200 pl-2">
                            {audit_timeline.length === 0 ? <p className="text-sm text-slate-500">No timeline events.</p> : null}
                            {audit_timeline.map((row) => (
                                <TimelineItem key={row.id} row={row} />
                            ))}
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-900">Request audit</h2>
                        <p className="mt-1 text-xs text-slate-500">
                            Method, path, and route only — no bodies or tokens are stored. Per-row IP is not recorded; see client IP in
                            summary.
                        </p>
                        <div className="mt-4 overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">Time</th>
                                        <th className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">Method</th>
                                        <th className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">Path</th>
                                        <th className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">Route</th>
                                        <th className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">HTTP</th>
                                        <th className="px-2 py-2 text-left text-xs font-semibold uppercase text-slate-500">IP</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {audit_requests.length === 0 ? (
                                        <tr>
                                            <td colSpan={6} className="px-2 py-6 text-center text-slate-500">
                                                No request rows logged.
                                            </td>
                                        </tr>
                                    ) : null}
                                    {audit_requests.map((r) => (
                                        <tr key={r.id} className="hover:bg-slate-50/80">
                                            <td className="whitespace-nowrap px-2 py-2 text-xs text-slate-600">
                                                {r.created_at ? new Date(r.created_at).toLocaleString() : '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-2 py-2 font-mono text-xs">{r.http_method || '—'}</td>
                                            <td className="max-w-md truncate px-2 py-2 font-mono text-xs text-slate-800" title={r.path || ''}>
                                                {r.path || '—'}
                                            </td>
                                            <td className="max-w-[10rem] truncate px-2 py-2 text-xs text-slate-600" title={r.route_name || ''}>
                                                {r.route_name || '—'}
                                            </td>
                                            <td className="whitespace-nowrap px-2 py-2 text-xs">{r.http_status ?? '—'}</td>
                                            <td className="whitespace-nowrap px-2 py-2 text-xs text-slate-500">—</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
