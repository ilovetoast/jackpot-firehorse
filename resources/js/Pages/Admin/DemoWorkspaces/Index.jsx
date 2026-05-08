import { Link, router, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'
import QuickCreateDemoModal from './QuickCreateDemoModal'

function formatIsoDate(iso) {
    if (!iso) return '—'
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return iso
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
    } catch {
        return iso
    }
}

const BADGE_CLASS = {
    template: 'bg-violet-100 text-violet-900 ring-violet-200',
    pending: 'bg-slate-100 text-slate-800 ring-slate-200',
    cloning: 'bg-sky-100 text-sky-900 ring-sky-200',
    active: 'bg-emerald-100 text-emerald-900 ring-emerald-200',
    expired: 'bg-amber-100 text-amber-950 ring-amber-200',
    failed: 'bg-rose-100 text-rose-900 ring-rose-200',
    archived: 'bg-neutral-200 text-neutral-800 ring-neutral-300',
}

function StatusBadge({ value }) {
    const v = value || '—'
    const cls = BADGE_CLASS[v] || 'bg-slate-100 text-slate-700 ring-slate-200'
    return (
        <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${cls}`}>
            {v}
        </span>
    )
}

function DemoTable({ title, rows, columns, emptyHint = 'No rows yet.' }) {
    return (
        <section className="mb-10">
            <h2 className="mb-3 text-lg font-semibold text-slate-900">{title}</h2>
            <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white shadow-sm">
                <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            {columns.map((c) => (
                                <th key={c.key} className="whitespace-nowrap px-4 py-2 font-medium text-slate-700">
                                    {c.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={columns.length} className="px-4 py-6 text-center text-slate-500">
                                    {emptyHint}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr key={row.id} className="hover:bg-slate-50/80">
                                    {columns.map((c) => (
                                        <td key={c.key} className="whitespace-nowrap px-4 py-2 text-slate-800">
                                            {c.render ? c.render(row) : row[c.key] ?? '—'}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    )
}

export default function AdminDemoWorkspacesIndex({
    demo_templates: demoTemplates = [],
    demo_instances: demoInstances = [],
    filters = {},
    plan_options: planOptions = [],
    creator_options: creatorOptions = [],
    instance_scope_options: instanceScopeOptions = [],
    focus_options: focusOptions = [],
    quick_create: quickCreate = {
        cloning_enabled: false,
        templates: [],
        default_template_id: null,
        allowed_expiration_days: [7, 14],
        default_plan_key: 'pro',
        default_expiration_days: 7,
    },
}) {
    const page = usePage()
    const { auth } = page.props
    const currentFilters = page.props.filters || {}

    function applyIndexFilters(partial) {
        router.get(
            route('admin.demo-workspaces.index'),
            { ...currentFilters, ...partial },
            { preserveState: true, replace: true },
        )
    }

    const templateColumns = [
        { key: 'id', label: 'ID' },
        { key: 'name', label: 'Name' },
        { key: 'slug', label: 'Slug' },
        { key: 'demo_label', label: 'Label' },
        { key: 'demo_plan_key', label: 'Plan' },
        {
            key: 'display_badge',
            label: 'Kind',
            render: (r) => <StatusBadge value={r.display_badge} />,
        },
        {
            key: 'demo_expires_at',
            label: 'Expires',
            render: (r) => formatIsoDate(r.demo_expires_at),
        },
        {
            key: 'audit',
            label: 'Actions',
            render: (r) => (
                <span className="flex flex-wrap gap-x-3 gap-y-1">
                    <Link
                        href={route('admin.demo-workspaces.template-audit', r.id)}
                        className="font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        Audit template
                    </Link>
                    <Link
                        href={route('admin.demo-workspaces.clone-plan', r.id)}
                        className="font-medium text-indigo-600 hover:text-indigo-800"
                    >
                        Dry-run clone plan
                    </Link>
                </span>
            ),
        },
    ]

    const instanceColumns = [
        { key: 'id', label: 'ID' },
        {
            key: 'detail',
            label: 'Detail',
            render: (r) => (
                <Link href={route('admin.demo-workspaces.show', r.id)} className="font-medium text-indigo-600 hover:text-indigo-800">
                    Open
                </Link>
            ),
        },
        { key: 'name', label: 'Name' },
        { key: 'slug', label: 'Slug' },
        { key: 'demo_label', label: 'Label' },
        { key: 'demo_plan_key', label: 'Plan' },
        {
            key: 'display_badge',
            label: 'Status',
            render: (r) => <StatusBadge value={r.display_badge} />,
        },
        {
            key: 'demo_clone_failure_message',
            label: 'Clone error',
            render: (r) =>
                r.display_badge === 'failed' && r.demo_clone_failure_message
                    ? String(r.demo_clone_failure_message).slice(0, 72) + (String(r.demo_clone_failure_message).length > 72 ? '…' : '')
                    : '—',
        },
        {
            key: 'demo_expires_at',
            label: 'Expires',
            render: (r) => formatIsoDate(r.demo_expires_at),
        },
        {
            key: 'template',
            label: 'Template',
            render: (r) => (r.demo_template ? `${r.demo_template.name} (#${r.demo_template.id})` : '—'),
        },
        {
            key: 'created_by',
            label: 'Created by',
            render: (r) => (r.created_by ? r.created_by.email || r.created_by.name : '—'),
        },
        {
            key: 'access',
            label: 'Access URL',
            render: (r) =>
                r.demo_access_url ? (
                    <button
                        type="button"
                        className="font-medium text-indigo-600 hover:text-indigo-800"
                        onClick={() => navigator.clipboard.writeText(r.demo_access_url)}
                    >
                        Copy
                    </button>
                ) : (
                    '—'
                ),
        },
    ]

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="support"
                    breadcrumbs={[
                        { label: 'Admin', href: '/app/admin' },
                        { label: 'Support', href: '/app/admin/support' },
                        { label: 'Demo workspaces' },
                    ]}
                    title="Demo workspaces"
                    description="Templates, disposable demo instances, filters, and lifecycle actions. Deletion and S3 cleanup are not available here yet."
                >
                    <div className="mb-4 flex flex-wrap items-center justify-end gap-3">
                        <QuickCreateDemoModal quick_create={quickCreate} plan_options={planOptions} />
                    </div>
                    <div className="mb-8 flex flex-col gap-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex flex-wrap items-end gap-4">
                            <label className="flex min-w-[200px] flex-col gap-1 text-xs font-medium text-slate-600">
                                View
                                <select
                                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    value={filters.focus || 'both'}
                                    onChange={(e) => applyIndexFilters({ focus: e.target.value })}
                                >
                                    {focusOptions.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex min-w-[220px] flex-col gap-1 text-xs font-medium text-slate-600">
                                Instance scope
                                <select
                                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    value={filters.instance_scope || 'all'}
                                    onChange={(e) => applyIndexFilters({ instance_scope: e.target.value })}
                                >
                                    {instanceScopeOptions.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex min-w-[180px] flex-col gap-1 text-xs font-medium text-slate-600">
                                Plan
                                <select
                                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    value={filters.plan_key || ''}
                                    onChange={(e) => applyIndexFilters({ plan_key: e.target.value || null })}
                                >
                                    <option value="">Any plan</option>
                                    {planOptions.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex min-w-[220px] flex-col gap-1 text-xs font-medium text-slate-600">
                                Created by
                                <select
                                    className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-900"
                                    value={filters.created_by_user_id ?? ''}
                                    onChange={(e) =>
                                        applyIndexFilters({
                                            created_by_user_id: e.target.value ? Number(e.target.value) : null,
                                        })
                                    }
                                >
                                    <option value="">Anyone</option>
                                    {creatorOptions.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name} ({u.email})
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                    </div>

                    {(filters.focus === 'both' || filters.focus === 'templates') && (
                        <DemoTable title="Demo templates" rows={demoTemplates} columns={templateColumns} />
                    )}
                    {(filters.focus === 'both' || filters.focus === 'instances') && (
                        <DemoTable
                            title="Demo instances"
                            rows={demoInstances}
                            columns={instanceColumns}
                            emptyHint="No demo instances match the current filters."
                        />
                    )}
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
