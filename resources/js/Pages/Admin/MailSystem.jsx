import { Link, usePage } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { InboxIcon, ArrowTopRightOnSquareIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/24/outline'

function BoolBadge({ value, trueLabel = 'Enabled', falseLabel = 'Disabled' }) {
    const on = Boolean(value)
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium ${
                on ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'
            }`}
        >
            {on ? (
                <CheckCircleIcon className="h-4 w-4" aria-hidden />
            ) : (
                <XCircleIcon className="h-4 w-4" aria-hidden />
            )}
            {on ? trueLabel : falseLabel}
        </span>
    )
}

export default function MailSystem({
    view_mode,
    app_env,
    mailpit_url,
    mail_summary,
    staging,
}) {
    const { auth } = usePage().props
    const transport = mail_summary?.transport || {}
    const hostPort =
        transport.transport === 'smtp' && transport.host
            ? `${transport.host}:${transport.port ?? '—'}`
            : transport.transport

    return (
        <div className="min-h-full">
            <AppHead title="Mail system" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-admin-shell px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-6">
                        <Link
                            href="/app/admin"
                            className="text-sm font-medium text-indigo-600 hover:text-indigo-500"
                        >
                            ← Command Center
                        </Link>
                    </div>

                    <div className="flex items-start gap-3 mb-6">
                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100">
                            <InboxIcon className="h-6 w-6 text-indigo-700" />
                        </div>
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900">Mail system</h1>
                            <p className="mt-1 text-sm text-slate-600">
                                Outbound email, automations, and how they relate to in-app notifications.
                            </p>
                            <p className="mt-2 text-xs text-slate-500">
                                Environment: <span className="font-mono text-slate-700">{app_env}</span>
                            </p>
                        </div>
                    </div>

                    {view_mode === 'local' && (
                        <div className="space-y-6">
                            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 className="text-sm font-semibold text-slate-900 uppercase tracking-wide">
                                    Local development
                                </h2>
                                <p className="mt-3 text-sm text-slate-600 leading-relaxed">
                                    Laravel delivers mail to the configured transport (typically SMTP to Mailpit in Sail).
                                    User-initiated messages (invites, password resets, and similar) are sent while you
                                    develop. Automated or system-class messages can be gated with{' '}
                                    <code className="rounded bg-slate-100 px-1 py-0.5 text-xs font-mono">
                                        MAIL_AUTOMATIONS_ENABLED
                                    </code>{' '}
                                    so background mail does not leave your machine unintentionally.
                                </p>
                                {mailpit_url && (
                                    <a
                                        href={mailpit_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="mt-4 inline-flex items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-medium text-indigo-800 hover:bg-indigo-100"
                                    >
                                        Open Mailpit
                                        <ArrowTopRightOnSquareIcon className="h-4 w-4" />
                                    </a>
                                )}
                                <p className="mt-3 text-xs text-slate-500">
                                    Default UI port comes from{' '}
                                    <code className="font-mono">FORWARD_MAILPIT_DASHBOARD_PORT</code> (8025 if unset).
                                </p>
                            </section>

                            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 className="text-sm font-semibold text-slate-900 uppercase tracking-wide">
                                    Current transport (this app)
                                </h2>
                                <dl className="mt-3 grid gap-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-slate-500">Mailer</dt>
                                        <dd className="font-mono text-slate-800">{transport.mailer}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-slate-500">Target</dt>
                                        <dd className="font-mono text-slate-800 text-right break-all">{hostPort}</dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-slate-500">Automations</dt>
                                        <dd>
                                            <BoolBadge value={mail_summary?.automations_enabled} trueLabel="On" falseLabel="Off" />
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        </div>
                    )}

                    {view_mode === 'staging' && staging && (
                        <div className="space-y-6">
                            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 className="text-sm font-semibold text-slate-900 uppercase tracking-wide">
                                    Email and automations
                                </h2>
                                <dl className="mt-4 grid gap-3 text-sm">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <dt className="text-slate-600">System / automated mail</dt>
                                        <dd>
                                            <BoolBadge
                                                value={staging.mail_automations_enabled}
                                                trueLabel="Sending"
                                                falseLabel="Blocked"
                                            />
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <dt className="text-slate-600">SMTP / transport target</dt>
                                        <dd className="font-mono text-xs text-slate-800 text-right break-all">
                                            {staging.transport?.transport === 'smtp' && staging.transport?.host
                                                ? `${staging.transport.transport} → ${staging.transport.host}:${staging.transport.port ?? '—'}`
                                                : (staging.transport?.transport ?? '—')}
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <dt className="text-slate-600">Tenant mail branding (staging)</dt>
                                        <dd className="flex flex-wrap items-center gap-2 justify-end">
                                            <BoolBadge
                                                value={staging.tenant_mail_branding?.effective}
                                                trueLabel="Active"
                                                falseLabel="Off"
                                            />
                                        </dd>
                                    </div>
                                    {staging.tenant_mail_branding?.effective && (
                                        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
                                            <dt className="text-slate-600">Verified From (branded sends)</dt>
                                            <dd className="font-mono text-xs text-slate-800 break-all">
                                                {staging.tenant_mail_branding?.staging_from_address}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            </section>

                            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 className="text-sm font-semibold text-slate-900 uppercase tracking-wide">
                                    Plan-based email notifications
                                </h2>
                                <p className="mt-2 text-sm text-slate-600">
                                    Companies whose plan includes approval / notification features (
                                    <code className="rounded bg-slate-100 px-1 text-xs">notifications.enabled</code>
                                    ).
                                </p>
                                <p className="mt-3 text-lg font-semibold text-slate-900">
                                    {staging.plan_email_notifications?.tenants_with_feature ?? 0}
                                    <span className="text-sm font-normal text-slate-500">
                                        {' '}
                                        / {staging.plan_email_notifications?.tenants_total ?? 0} tenants
                                    </span>
                                </p>
                                {staging.plan_email_notifications?.by_plan &&
                                    Object.keys(staging.plan_email_notifications.by_plan).length > 0 && (
                                        <ul className="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-100">
                                            {Object.entries(staging.plan_email_notifications.by_plan).map(
                                                ([plan, count]) => (
                                                    <li
                                                        key={plan}
                                                        className="flex justify-between gap-4 px-3 py-2 text-sm"
                                                    >
                                                        <span className="font-mono text-slate-700">{plan}</span>
                                                        <span className="text-slate-600">{count}</span>
                                                    </li>
                                                )
                                            )}
                                        </ul>
                                    )}
                            </section>

                            <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h2 className="text-sm font-semibold text-slate-900 uppercase tracking-wide">
                                    In-app notification rows
                                </h2>
                                <p className="mt-2 text-sm text-slate-600">
                                    Grouped rows in the notifications table (bell), not outbound email volume.
                                </p>
                                <dl className="mt-4 grid grid-cols-2 gap-4 text-sm">
                                    <div className="rounded-lg bg-slate-50 p-3">
                                        <dt className="text-slate-500">Total rows</dt>
                                        <dd className="mt-1 text-xl font-semibold text-slate-900">
                                            {staging.in_app_notification_rows?.total ?? 0}
                                        </dd>
                                    </div>
                                    <div className="rounded-lg bg-slate-50 p-3">
                                        <dt className="text-slate-500">Unread</dt>
                                        <dd className="mt-1 text-xl font-semibold text-slate-900">
                                            {staging.in_app_notification_rows?.unread ?? 0}
                                        </dd>
                                    </div>
                                </dl>
                            </section>
                        </div>
                    )}

                    {view_mode === 'default' && (
                        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <p className="text-sm text-slate-600 leading-relaxed">
                                This environment does not show the local Mailpit shortcut or the staging-only dashboard.
                                Outbound mail uses the configured transport for your deployment. Use{' '}
                                <Link href="/app/admin/notifications" className="font-medium text-indigo-600 hover:text-indigo-500">
                                    Notifications
                                </Link>{' '}
                                for template copy and{' '}
                                <Link href="/app/admin/email-test" className="font-medium text-indigo-600 hover:text-indigo-500">
                                    Email test
                                </Link>{' '}
                                if you need to verify delivery.
                            </p>
                            <dl className="mt-6 grid gap-2 text-sm border-t border-slate-100 pt-4">
                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">Default mailer</dt>
                                    <dd className="font-mono text-slate-800">{transport.mailer}</dd>
                                </div>
                                <div className="flex justify-between gap-4">
                                    <dt className="text-slate-500">Transport</dt>
                                    <dd className="font-mono text-slate-800 text-right break-all">{hostPort}</dd>
                                </div>
                            </dl>
                        </section>
                    )}
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
