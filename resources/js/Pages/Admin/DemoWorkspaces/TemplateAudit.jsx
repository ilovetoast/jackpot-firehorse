import { Link, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'

function StatGrid({ title, entries }) {
    return (
        <section className="mb-8">
            <h3 className="mb-3 text-base font-semibold text-slate-900">{title}</h3>
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <dl className="divide-y divide-slate-100">
                    {Object.entries(entries).map(([k, v]) => (
                        <div key={k} className="grid grid-cols-1 gap-1 px-4 py-2 sm:grid-cols-3 sm:gap-4">
                            <dt className="text-sm font-medium text-slate-500">{k.replace(/_/g, ' ')}</dt>
                            <dd className="text-sm text-slate-900 sm:col-span-2">{v === null || v === '' ? '—' : String(v)}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    )
}

function BulletList({ title, items }) {
    if (!items?.length) {
        return null
    }
    return (
        <section className="mb-8">
            <h3 className="mb-2 text-base font-semibold text-slate-900">{title}</h3>
            <ul className="list-inside list-disc space-y-1 text-sm text-slate-700">
                {items.map((x) => (
                    <li key={x}>{x}</li>
                ))}
            </ul>
        </section>
    )
}

export default function AdminDemoTemplateAudit({ report }) {
    const { auth } = usePage().props
    const meta = report?.meta ?? {}

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
                        { label: 'Template audit' },
                    ]}
                    title="Demo template audit"
                    description="Read-only inventory for clone planning. Nothing is copied or modified."
                >
                    <div className="mb-6 flex flex-wrap items-center gap-3">
                        <Link
                            href={route('admin.demo-workspaces.index')}
                            className="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            ← Back to demo workspaces
                        </Link>
                    </div>

                    <div className="mb-8 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800">
                        <p className="font-semibold text-slate-900">
                            {meta.tenant_name} <span className="font-normal text-slate-500">#{meta.tenant_id}</span>
                        </p>
                        <p className="mt-1 text-slate-600">Audited at {meta.audited_at ?? '—'}</p>
                    </div>

                    <StatGrid title="Clone-ready (counts)" entries={report.clone_ready ?? {}} />
                    <StatGrid title="Excluded from clone (counts)" entries={report.excluded_from_clone ?? {}} />
                    <StatGrid title="Storage references" entries={report.storage ?? {}} />

                    <BulletList title="Warnings" items={report.warnings} />
                    <BulletList title="Unsupported relationships for naive clone" items={report.unsupported_relationships} />
                    <BulletList title="Missing or risky data" items={report.missing_required_data} />
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
