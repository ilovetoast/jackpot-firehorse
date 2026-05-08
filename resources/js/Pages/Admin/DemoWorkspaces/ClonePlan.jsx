import { useEffect, useState } from 'react'
import { Link, router, useForm, usePage } from '@inertiajs/react'
import AppNav from '../../../Components/AppNav'
import AppFooter from '../../../Components/AppFooter'
import AdminShell from '../../../Components/Admin/AdminShell'

function StatGrid({ title, entries }) {
    if (!entries || typeof entries !== 'object') {
        return null
    }
    const rows = Object.entries(entries).filter(([, v]) => typeof v !== 'object' || v === null)
    if (rows.length === 0) {
        return null
    }
    return (
        <section className="mb-8">
            <h3 className="mb-3 text-base font-semibold text-slate-900">{title}</h3>
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <dl className="divide-y divide-slate-100">
                    {rows.map(([k, v]) => (
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

function JsonBlock({ data }) {
    if (!data) {
        return null
    }
    return (
        <pre className="max-h-[28rem] overflow-auto rounded-lg border border-slate-200 bg-slate-900 p-4 text-xs text-slate-100">
            {JSON.stringify(data, null, 2)}
        </pre>
    )
}

export default function AdminDemoClonePlan({
    template,
    plan_options: planOptions = [],
    expiration_days_options: expirationDaysOptions = [],
    clone_plan: clonePlan,
    form_defaults: formDefaults = {},
    cloning_enabled: cloningEnabled = false,
}) {
    const { auth } = usePage().props
    const [creatingDemo, setCreatingDemo] = useState(false)

    const { data, setData, post, processing, errors } = useForm({
        target_demo_label: formDefaults.target_demo_label ?? '',
        plan_key: formDefaults.plan_key ?? '',
        expiration_days: formDefaults.expiration_days ?? 7,
        invited_emails_text: formDefaults.invited_emails_text ?? '',
    })

    useEffect(() => {
        setData({
            target_demo_label: formDefaults.target_demo_label ?? '',
            plan_key: formDefaults.plan_key ?? '',
            expiration_days: Number(formDefaults.expiration_days ?? 7),
            invited_emails_text: formDefaults.invited_emails_text ?? '',
        })
    }, [
        formDefaults.target_demo_label,
        formDefaults.plan_key,
        formDefaults.expiration_days,
        formDefaults.invited_emails_text,
        setData,
    ])

    const submit = (e) => {
        e.preventDefault()
        post(route('admin.demo-workspaces.clone-plan.preview', template.id))
    }

    const submitCreateDemo = (e) => {
        e.preventDefault()
        if (!cloningEnabled) {
            return
        }
        setCreatingDemo(true)
        router.post(route('admin.demo-workspaces.create-demo', { sourceTemplate: template.id }), data, {
            onFinish: () => setCreatingDemo(false),
        })
    }

    const meta = clonePlan?.meta ?? {}

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
                        { label: 'Clone plan (dry-run)' },
                    ]}
                    title="Create clone plan"
                    description="Dry-run only: no tenant is created, no files are copied, and no emails are sent."
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
                            Template: {template.name} <span className="font-normal text-slate-500">#{template.id}</span>
                        </p>
                        <p className="mt-1 text-slate-600">Slug: {template.slug}</p>
                    </div>

                    {!cloningEnabled && (
                        <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                            Demo cloning is disabled in configuration (<code className="rounded bg-amber-100 px-1">demo.cloning_enabled</code>
                            ). You can still run a dry-run plan; enable cloning to queue a real workspace.
                        </div>
                    )}

                    <form onSubmit={submit} className="mb-10 space-y-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="target_demo_label">
                                Target demo label
                            </label>
                            <input
                                id="target_demo_label"
                                type="text"
                                className="w-full max-w-xl rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                value={data.target_demo_label}
                                onChange={(e) => setData('target_demo_label', e.target.value)}
                            />
                            {errors.target_demo_label && (
                                <p className="mt-1 text-sm text-red-600">{errors.target_demo_label}</p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="plan_key">
                                Plan key
                            </label>
                            <select
                                id="plan_key"
                                className="w-full max-w-xl rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                value={data.plan_key}
                                onChange={(e) => setData('plan_key', e.target.value)}
                            >
                                {planOptions.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label} ({o.value})
                                    </option>
                                ))}
                            </select>
                            {errors.plan_key && <p className="mt-1 text-sm text-red-600">{errors.plan_key}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="expiration_days">
                                Expiration (days)
                            </label>
                            <select
                                id="expiration_days"
                                className="w-full max-w-xs rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                value={data.expiration_days}
                                onChange={(e) => setData('expiration_days', Number(e.target.value))}
                            >
                                {expirationDaysOptions.map((d) => (
                                    <option key={d} value={d}>
                                        {d} days
                                    </option>
                                ))}
                            </select>
                            {errors.expiration_days && <p className="mt-1 text-sm text-red-600">{errors.expiration_days}</p>}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="invited_emails_text">
                                Invited user emails (optional)
                            </label>
                            <textarea
                                id="invited_emails_text"
                                rows={3}
                                placeholder="one@example.com, other@example.com"
                                className="w-full max-w-xl rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                value={data.invited_emails_text}
                                onChange={(e) => setData('invited_emails_text', e.target.value)}
                            />
                            {errors.invited_emails_text && (
                                <p className="mt-1 text-sm text-red-600">{errors.invited_emails_text}</p>
                            )}
                            {Object.keys(errors)
                                .filter((k) => k.startsWith('invited_emails.'))
                                .map((k) => (
                                    <p key={k} className="mt-1 text-sm text-red-600">
                                        {errors[k]}
                                    </p>
                                ))}
                        </div>

                        <div className="flex flex-wrap gap-3">
                            <button
                                type="submit"
                                disabled={processing || creatingDemo}
                                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-500 disabled:opacity-50"
                            >
                                {processing ? 'Building plan…' : 'Create clone plan'}
                            </button>
                            <button
                                type="button"
                                disabled={!clonePlan || !cloningEnabled || processing || creatingDemo}
                                onClick={submitCreateDemo}
                                className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50"
                                title={!clonePlan ? 'Run “Create clone plan” first' : !cloningEnabled ? 'Enable demo.cloning_enabled' : ''}
                            >
                                {creatingDemo ? 'Queueing demo…' : 'Create demo (queue clone)'}
                            </button>
                        </div>
                    </form>

                    {clonePlan && (
                        <>
                            <div className="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                                <p className="font-semibold">Dry-run result</p>
                                <p className="mt-1">
                                    Plan <span className="font-mono">{meta.plan_key}</span> · expires in {meta.expiration_days} days ·
                                    preview <span className="font-mono">{meta.demo_expires_at_preview}</span>
                                </p>
                                <p className="mt-1">
                                    Estimated clone size:{' '}
                                    <strong>{clonePlan.storage_strategy?.estimated_clone_human ?? '—'}</strong> (
                                    {clonePlan.storage_strategy?.recommended ?? '—'})
                                </p>
                            </div>

                            <StatGrid title="Would clone — content row counts" entries={clonePlan.would_clone?.content_row_counts ?? {}} />
                            <StatGrid title="Would skip — excluded row counts" entries={clonePlan.would_skip?.excluded_row_counts ?? {}} />

                            <section className="mb-8">
                                <h3 className="mb-2 text-base font-semibold text-slate-900">Storage strategy</h3>
                                <div className="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800">
                                    <p>
                                        <strong>Recommended:</strong> {clonePlan.storage_strategy?.recommended}
                                    </p>
                                    <p className="mt-2">{clonePlan.storage_strategy?.summary}</p>
                                    <p className="mt-2 text-slate-600">
                                        Rejected: {clonePlan.storage_strategy?.rejected_alternative?.name} —{' '}
                                        {clonePlan.storage_strategy?.rejected_alternative?.reason}
                                    </p>
                                </div>
                            </section>

                            <BulletList title="Blockers" items={clonePlan.blockers} />
                            <BulletList title="Warnings" items={clonePlan.warnings} />

                            <section className="mb-8">
                                <h3 className="mb-2 text-base font-semibold text-slate-900">Full plan (JSON)</h3>
                                <JsonBlock data={clonePlan} />
                            </section>
                        </>
                    )}
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
