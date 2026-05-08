import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'

const BADGE_CLASS = {
    pending: 'bg-slate-100 text-slate-800 ring-slate-200',
    cloning: 'bg-sky-100 text-sky-900 ring-sky-200',
    active: 'bg-emerald-100 text-emerald-900 ring-emerald-200',
    expired: 'bg-amber-100 text-amber-950 ring-amber-200',
    failed: 'bg-rose-100 text-rose-900 ring-rose-200',
    archived: 'bg-neutral-200 text-neutral-800 ring-neutral-300',
}

function StatusBadge({ value }) {
    const cls = BADGE_CLASS[value] || 'bg-slate-100 text-slate-700 ring-slate-200'
    return (
        <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ${cls}`}>
            {value}
        </span>
    )
}

function formatIso(iso) {
    if (!iso) return '—'
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return iso
        return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
    } catch {
        return iso
    }
}

function InfoCard({ title, children }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <h3 className="mb-2 text-sm font-semibold text-slate-900">{title}</h3>
            <div className="space-y-1 text-sm text-slate-700">{children}</div>
        </div>
    )
}

export default function AdminDemoWorkspaceShow({
    tenant,
    display_badge: displayBadge,
    demo_template: demoTemplate,
    created_by: createdBy,
    counts,
    storage,
    users,
    demo_access_url: demoAccessUrl,
    timeline,
    actions,
    cleanup = {},
}) {
    const { auth, flash } = usePage().props

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="support"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Support', href: '/app/admin/support' },
                        { label: 'Demo workspaces', href: route('admin.demo-workspaces.index') },
                        { label: tenant.name },
                    ]}
                    title={tenant.name}
                    description="Disposable demo workspace — lifecycle, storage footprint, and members."
                >
                    {flash?.success ? (
                        <p className="mb-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900 ring-1 ring-emerald-200">{flash.success}</p>
                    ) : null}
                    {flash?.warning ? (
                        <p className="mb-4 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">{flash.warning}</p>
                    ) : null}

                    <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <StatusBadge value={displayBadge} />
                            <span className="text-sm text-slate-600">
                                #{tenant.id} · slug <code className="rounded bg-slate-100 px-1">{tenant.slug}</code>
                            </span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {actions.can_expire ? (
                                <button
                                    type="button"
                                    className="rounded-md bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700"
                                    onClick={() => {
                                        if (confirm('Mark this demo as expired now?')) {
                                            router.post(route('admin.demo-workspaces.expire', tenant.id))
                                        }
                                    }}
                                >
                                    Manually expire
                                </button>
                            ) : null}
                            {actions.can_extend ? (
                                <>
                                    <button
                                        type="button"
                                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50"
                                        onClick={() => router.post(route('admin.demo-workspaces.extend', tenant.id), { days: 7 })}
                                    >
                                        +7 days
                                    </button>
                                    <button
                                        type="button"
                                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 hover:bg-slate-50"
                                        onClick={() => router.post(route('admin.demo-workspaces.extend', tenant.id), { days: 14 })}
                                    >
                                        +14 days
                                    </button>
                                </>
                            ) : null}
                            {actions.can_archive_failed ? (
                                <button
                                    type="button"
                                    className="rounded-md bg-neutral-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-800"
                                    onClick={() => {
                                        if (confirm('Archive this failed demo? (No data deletion.)')) {
                                            router.post(route('admin.demo-workspaces.archive-failed', tenant.id))
                                        }
                                    }}
                                >
                                    Archive failed demo
                                </button>
                            ) : null}
                            {demoAccessUrl ? (
                                <button
                                    type="button"
                                    className="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-900 hover:bg-indigo-100"
                                    onClick={() => navigator.clipboard.writeText(demoAccessUrl)}
                                >
                                    Copy access URL
                                </button>
                            ) : null}
                            {actions.can_delete_now ? (
                                <button
                                    type="button"
                                    className="rounded-md bg-red-700 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-800"
                                    onClick={() => {
                                        const phrase = tenant.slug
                                        const typed = window.prompt(
                                            `Delete this demo permanently (database + ${cleanup.storage_prefix || 'tenants/{uuid}/'} only)? Type the company slug "${phrase}" to confirm.`,
                                        )
                                        if (typed !== phrase) {
                                            return
                                        }
                                        router.post(route('admin.demo-workspaces.delete-now', tenant.id), { acknowledge: true })
                                    }}
                                >
                                    Delete demo now
                                </button>
                            ) : null}
                        </div>
                    </div>

                    <div className="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        <InfoCard title="Tenant">
                            <p>
                                <span className="text-slate-500">Label:</span> {tenant.demo_label || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">UUID:</span> {tenant.uuid || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Billing:</span> {tenant.billing_status || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Raw status:</span> {tenant.demo_status || '—'}
                            </p>
                        </InfoCard>
                        <InfoCard title="Plan & expiration">
                            <p>
                                <span className="text-slate-500">Plan key:</span> {tenant.demo_plan_key || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Manual plan override:</span> {tenant.manual_plan_override || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Expires:</span> {formatIso(tenant.demo_expires_at)}
                            </p>
                        </InfoCard>
                        <InfoCard title="Source & ownership">
                            <p>
                                <span className="text-slate-500">Template:</span>{' '}
                                {demoTemplate ? (
                                    <Link className="text-indigo-600 hover:text-indigo-800" href={route('admin.demo-workspaces.clone-plan', demoTemplate.id)}>
                                        {demoTemplate.name} (#{demoTemplate.id})
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </p>
                            <p>
                                <span className="text-slate-500">Created by:</span>{' '}
                                {createdBy ? `${createdBy.name} <${createdBy.email}>` : '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Record created:</span> {formatIso(tenant.created_at)}
                            </p>
                        </InfoCard>
                        <InfoCard title="Cloned content (counts)">
                            <p>Brands: {counts.brands}</p>
                            <p>Assets: {counts.assets}</p>
                            <p>Collections: {counts.collections}</p>
                            <p>Users: {counts.users}</p>
                        </InfoCard>
                        <InfoCard title="Storage">
                            <p>
                                <span className="text-slate-500">Object prefix:</span>{' '}
                                {storage.object_key_prefix ? <code className="break-all text-xs">{storage.object_key_prefix}</code> : '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Config bucket (app):</span> {storage.config_bucket || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Dedicated bucket (tenant):</span> {storage.dedicated_bucket || '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Storage mode:</span> {storage.storage_mode || '—'}
                            </p>
                            {storage.buckets?.length ? (
                                <ul className="mt-2 list-inside list-disc text-xs">
                                    {storage.buckets.map((b) => (
                                        <li key={b.id}>
                                            {b.name} ({b.region}) — {b.status}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-slate-500">No storage bucket rows.</p>
                            )}
                        </InfoCard>
                        <InfoCard title="Cleanup (Phase 4)">
                            <p>
                                <span className="text-slate-500">Automatic cleanup enabled:</span>{' '}
                                {cleanup.cleanup_enabled ? 'yes' : 'no'}
                            </p>
                            <p>
                                <span className="text-slate-500">Grace days:</span> {cleanup.grace_days ?? '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Batch chunk size:</span> {cleanup.chunk_size ?? '—'}
                            </p>
                            <p>
                                <span className="text-slate-500">Scheduler dry-run only:</span>{' '}
                                {cleanup.cleanup_dry_run ? 'yes' : 'no'}
                            </p>
                            <p>
                                <span className="text-slate-500">Eligible for scheduled cleanup now:</span>{' '}
                                {cleanup.scheduled_eligible_now ? 'yes' : 'no'}
                            </p>
                            <p>
                                <span className="text-slate-500">Manual delete (expired/archived):</span>{' '}
                                {cleanup.manual_delete_eligible ? 'allowed' : 'not yet'}
                            </p>
                            <p className="text-xs text-slate-600">{cleanup.scheduled_note}</p>
                        </InfoCard>
                    </div>

                    {tenant.demo_cleanup_failure_message ? (
                        <section className="mb-8 rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <h3 className="mb-2 text-sm font-semibold text-amber-950">Last cleanup failure</h3>
                            <pre className="max-h-48 overflow-auto whitespace-pre-wrap break-words text-xs text-amber-900">
                                {tenant.demo_cleanup_failure_message}
                            </pre>
                        </section>
                    ) : null}

                    {tenant.demo_clone_failure_message ? (
                        <section className="mb-8 rounded-lg border border-rose-200 bg-rose-50 p-4">
                            <h3 className="mb-2 text-sm font-semibold text-rose-950">Clone failure message</h3>
                            <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-words text-xs text-rose-900">{tenant.demo_clone_failure_message}</pre>
                        </section>
                    ) : null}

                    <section className="mb-8">
                        <h3 className="mb-3 text-sm font-semibold text-slate-900">Status timeline</h3>
                        <ul className="space-y-3 border-l-2 border-slate-200 pl-4">
                            {timeline.map((row, i) => (
                                <li key={i} className="relative">
                                    <span className="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-slate-400" />
                                    <p className="text-sm font-medium text-slate-900">{row.label}</p>
                                    {row.at ? <p className="text-xs text-slate-600">{formatIso(row.at)}</p> : null}
                                    {row.detail ? <p className="text-xs text-slate-600">Detail: {row.detail}</p> : null}
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section>
                        <h3 className="mb-3 text-sm font-semibold text-slate-900">Demo users (tenant members)</h3>
                        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                            <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="px-4 py-2 font-medium text-slate-700">User</th>
                                        <th className="px-4 py-2 font-medium text-slate-700">Email</th>
                                        <th className="px-4 py-2 font-medium text-slate-700">Company role</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {users.length === 0 ? (
                                        <tr>
                                            <td colSpan={3} className="px-4 py-4 text-slate-500">
                                                No users attached yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        users.map((u) => (
                                            <tr key={u.id}>
                                                <td className="px-4 py-2">{u.name}</td>
                                                <td className="px-4 py-2">{u.email}</td>
                                                <td className="px-4 py-2">{u.tenant_role || '—'}</td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
