import { useState } from 'react'
import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import AppFooter from '../../Components/AppFooter'
import AdminShell from '../../Components/Admin/AdminShell'
import { useAdminPermissions } from '../../hooks/useAdminPermissions'
import {
    ExclamationTriangleIcon,
    ClockIcon,
    QueueListIcon,
    ChartBarIcon,
    UsersIcon,
    BuildingOffice2Icon,
    CogIcon,
    BoltIcon,
    CreditCardIcon,
    InboxIcon,
} from '@heroicons/react/24/outline'

const STATUS_COLORS = {
    critical: 'text-red-600 bg-red-50',
    error: 'text-orange-600 bg-orange-50',
    warning: 'text-amber-600 bg-amber-50',
    healthy: 'text-emerald-600 bg-emerald-50',
    stable: 'text-emerald-600 bg-emerald-50',
    degraded: 'text-amber-600 bg-amber-50',
    unhealthy: 'text-red-600 bg-red-50',
    not_running: 'text-red-600 bg-red-50',
    delayed: 'text-amber-600 bg-amber-50',
    unknown: 'text-slate-600 bg-slate-50',
}

function MetricCard({ title, value, subtitle, status, href, icon: Icon }) {
    const statusClass = STATUS_COLORS[status] || STATUS_COLORS.unknown
    const content = (
        <div className="flex items-start gap-3">
            <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${statusClass}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-xs font-medium text-slate-500 uppercase tracking-wide">{title}</p>
                <p className="mt-0.5 text-2xl font-bold text-slate-900">{value}</p>
                <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>
            </div>
        </div>
    )
    const wrapperClass =
        'rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-slate-300 transition-colors'
    return href ? (
        <Link href={href} className={`block ${wrapperClass}`}>
            {content}
        </Link>
    ) : (
        <div className={wrapperClass}>{content}</div>
    )
}

const hubCardClass =
    'flex items-start gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition-colors hover:border-indigo-200 hover:bg-indigo-50/30'

/** Matches System status / admin date display (local browser timezone). */
const ADMIN_DATETIME_FORMAT = {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
}

/**
 * @returns {{ display: string | null, title: string | null, machineOk: boolean, iso?: string }}
 */
function formatReleaseTimestamp(value) {
    if (value == null || value === '') {
        return { display: null, title: null, machineOk: false }
    }
    const raw = String(value).trim()
    const d = new Date(raw)
    if (Number.isNaN(d.getTime())) {
        return { display: raw, title: 'Could not parse as a date; showing raw value.', machineOk: false }
    }
    return {
        display: d.toLocaleString('en-US', ADMIN_DATETIME_FORMAT),
        title: `UTC: ${d.toISOString()}`,
        machineOk: true,
        iso: d.toISOString(),
    }
}

function hasReleaseInfo(release) {
    if (!release || typeof release !== 'object') {
        return false
    }
    return !!(release.commit || release.message || release.committed_at || release.deployed_at || release.status_url)
}

function ReleaseInfoPanel({ release }) {
    const r = release || {}
    if (!hasReleaseInfo(r)) {
        return null
    }

    const committed = formatReleaseTimestamp(r.committed_at)
    const deployed = formatReleaseTimestamp(r.deployed_at)

    const commitDisplay = r.commit && String(r.commit).trim() !== '' ? String(r.commit).trim() : null
    const messageDisplay = r.message && String(r.message).trim() !== '' ? String(r.message).trim() : null

    return (
        <div className="flex min-h-[5.5rem] flex-col rounded-2xl border border-slate-200 bg-slate-50/90 p-4 shadow-sm ring-1 ring-slate-100/80">
            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-slate-400">Release &amp; deploy</p>

            <div className="mt-3 space-y-3">
                <div className="rounded-lg border border-slate-200/80 bg-white/70 p-3 shadow-sm">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Source (build)</p>
                    <dl className="mt-2 space-y-2">
                        <div className="flex gap-2 sm:gap-3">
                            <dt className="w-[5.25rem] shrink-0 text-[10px] font-medium uppercase tracking-wide text-slate-400">
                                Commit
                            </dt>
                            <dd className="min-w-0 flex-1">
                                {commitDisplay ? (
                                    <span className="break-all font-mono text-[11px] font-medium text-slate-800" title="Revision">
                                        {commitDisplay}
                                    </span>
                                ) : (
                                    <span className="text-[11px] text-slate-500">Not available</span>
                                )}
                            </dd>
                        </div>
                        <div className="flex gap-2 sm:gap-3">
                            <dt className="w-[5.25rem] shrink-0 text-[10px] font-medium uppercase tracking-wide text-slate-400">
                                Committed
                            </dt>
                            <dd className="min-w-0 flex-1 text-[11px] text-slate-700">
                                {committed.display ? (
                                    committed.machineOk ? (
                                        <time dateTime={committed.iso} title={committed.title || undefined}>
                                            {committed.display}
                                        </time>
                                    ) : (
                                        <span className="font-mono text-[10px] text-slate-600" title={committed.title || undefined}>
                                            {committed.display}
                                        </span>
                                    )
                                ) : (
                                    <span className="text-slate-500">Not reported</span>
                                )}
                            </dd>
                        </div>
                        <div className="flex gap-2 sm:gap-3">
                            <dt className="w-[5.25rem] shrink-0 text-[10px] font-medium uppercase tracking-wide text-slate-400">
                                Message
                            </dt>
                            <dd className="min-w-0 flex-1">
                                {messageDisplay ? (
                                    <p className="line-clamp-3 text-[11px] leading-snug text-slate-700" title={messageDisplay}>
                                        {messageDisplay}
                                    </p>
                                ) : (
                                    <span className="text-[11px] text-slate-500">No message recorded</span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="rounded-lg border border-slate-200/80 bg-white/70 p-3 shadow-sm">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Deployment</p>
                    <dl className="mt-2 space-y-2">
                        <div className="flex gap-2 sm:gap-3">
                            <dt className="w-[5.25rem] shrink-0 text-[10px] font-medium uppercase tracking-wide text-slate-400">
                                Deployed
                            </dt>
                            <dd className="min-w-0 flex-1 text-[11px] text-slate-700">
                                {deployed.display ? (
                                    deployed.machineOk ? (
                                        <time dateTime={deployed.iso} title={deployed.title || undefined}>
                                            {deployed.display}
                                        </time>
                                    ) : (
                                        <span className="font-mono text-[10px] text-slate-600" title={deployed.title || undefined}>
                                            {deployed.display}
                                        </span>
                                    )
                                ) : (
                                    <span className="text-slate-500">Not reported</span>
                                )}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div className="rounded-lg border border-slate-200/80 bg-white/70 p-3 shadow-sm">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Status &amp; uptime</p>
                    <div className="mt-2">
                        {r.status_url && String(r.status_url).trim() !== '' ? (
                            <a
                                href={String(r.status_url).trim()}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex text-[11px] font-medium text-indigo-600 hover:text-indigo-700"
                            >
                                Open status page →
                            </a>
                        ) : (
                            <span className="text-[11px] text-slate-500">Not configured</span>
                        )}
                    </div>
                </div>
            </div>
        </div>
    )
}

export default function AdminDashboard({ auth, metrics: initialMetrics }) {
    const perms = useAdminPermissions()
    const [metrics] = useState(initialMetrics || {})

    const m = metrics
    const incidents = m.incidents || {}
    const queue = m.queue || {}
    const scheduler = m.scheduler || {}
    const autoRecovery = m.auto_recovery || {}
    const ai = m.ai || {}
    const org = m.organization || {}
    const health = m.health_score || {}
    const healthStatusClass = STATUS_COLORS[health.status] || STATUS_COLORS.unknown
    const release = m.release || {}
    const showRelease = hasReleaseInfo(release)

    const hubs = [
        {
            name: 'Reliability Center',
            description: 'Health, queues, incidents, failures, raw logs, and recovery tools.',
            href: '/app/admin/reliability',
            icon: ExclamationTriangleIcon,
            show: perms.canViewOperations,
        },
        {
            name: 'AI Control Center',
            description: 'Runs, spend, models, audits, Studio tooling, and feature gates.',
            href: '/app/admin/ai',
            icon: BoltIcon,
            show: perms.canViewAI,
        },
        {
            name: 'Organization',
            description: 'Companies, users, brands, and structured activity history.',
            href: '/app/admin/organization',
            icon: BuildingOffice2Icon,
            show: true,
        },
        {
            name: 'Billing & plans',
            description: 'Stripe, subscriptions, usage, storage, and plan limits.',
            href: '/app/admin/billing',
            icon: CreditCardIcon,
            show: true,
        },
        {
            name: 'Platform configuration',
            description: 'Permissions, metadata registry, categories, mail, and notifications.',
            href: '/app/admin/platform',
            icon: CogIcon,
            show: true,
        },
        {
            name: 'Support',
            description: 'Tickets, engineering queue, and SLA workflows.',
            href: '/app/admin/support',
            icon: InboxIcon,
            show: perms.canViewSupport || perms.canViewEngineering,
        },
    ].filter((h) => h.show)

    return (
        <div className="min-h-full">
            <AppHead title="Command Center" suffix="Admin" />
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="min-h-0">
                <AdminShell
                    centerKey="command"
                    breadcrumbs={[{ label: 'Admin' }]}
                    title="Command Center"
                    description="Executive entry point: KPIs below, then jump into a center for deeper work."
                >
                    <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <MetricCard
                            title="Open incidents"
                            value={incidents.total_unresolved ?? 0}
                            subtitle={`${incidents.critical ?? 0} critical`}
                            status={incidents.critical > 0 ? 'critical' : incidents.error > 0 ? 'error' : 'healthy'}
                            href="/app/admin/reliability"
                            icon={ExclamationTriangleIcon}
                        />
                        <MetricCard
                            title="Queue health"
                            value={queue.status ?? '—'}
                            subtitle={`${queue.pending_count ?? 0} pending · ${queue.failed_count ?? 0} failed`}
                            status={queue.status || 'unknown'}
                            href="/app/admin/reliability?tab=queue"
                            icon={QueueListIcon}
                        />
                        <MetricCard
                            title="Scheduler"
                            value={scheduler.status ?? '—'}
                            subtitle={
                                scheduler.heartbeat_age_minutes != null
                                    ? `Heartbeat ${scheduler.heartbeat_age_minutes}m ago`
                                    : 'No heartbeat'
                            }
                            status={scheduler.status || 'unknown'}
                            href="/app/admin/reliability?tab=queue"
                            icon={ClockIcon}
                        />
                        <MetricCard
                            title="Tenants / users"
                            value={`${org.total_tenants ?? 0} / ${org.total_users ?? 0}`}
                            subtitle={`${org.active_subscriptions ?? 0} active subscriptions`}
                            status="healthy"
                            href="/app/admin/organization"
                            icon={UsersIcon}
                        />
                    </div>

                    {(perms.canViewAI || showRelease) && (
                        <div
                            className={`mb-8 grid grid-cols-1 gap-4 ${
                                perms.canViewAI && showRelease ? 'lg:grid-cols-2 lg:items-stretch' : ''
                            }`}
                        >
                            {perms.canViewAI ? (
                                <MetricCard
                                    title="AI spend (24h)"
                                    value={`$${Number(ai.cost_24h_usd ?? 0).toFixed(2)}`}
                                    subtitle={`${ai.runs_24h ?? 0} runs`}
                                    status="healthy"
                                    href="/app/admin/ai/budgets"
                                    icon={ChartBarIcon}
                                />
                            ) : null}
                            {showRelease ? <ReleaseInfoPanel release={release} /> : null}
                        </div>
                    )}

                    <div className="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wide">System health score</h3>
                            <div className="mt-3 flex items-baseline gap-3">
                                <span className={`text-4xl font-bold ${healthStatusClass.split(' ')[0]}`}>{health.score ?? '—'}</span>
                                <span className="text-lg font-medium text-slate-600">/ 100</span>
                                <span className={`rounded-full px-2.5 py-0.5 text-sm font-medium ${healthStatusClass}`}>
                                    {health.status ?? 'Unknown'}
                                </span>
                            </div>
                            {health.deductions?.length > 0 && (
                                <ul className="mt-3 list-inside list-disc text-sm text-slate-500">
                                    {health.deductions.map((d, i) => (
                                        <li key={i}>{d}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wide">Auto recovery (24h)</h3>
                            <div className="mt-3 grid grid-cols-2 gap-4">
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">{autoRecovery.auto_recovered_24h ?? 0}</p>
                                    <p className="text-sm text-slate-500">Incidents resolved</p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">{autoRecovery.escalated_to_ticket_24h ?? 0}</p>
                                    <p className="text-sm text-slate-500">Escalated to ticket</p>
                                </div>
                                <div>
                                    <p className="text-2xl font-bold text-slate-900">{autoRecovery.success_rate ?? 100}%</p>
                                    <p className="text-sm text-slate-500">Success rate</p>
                                </div>
                            </div>
                            <Link
                                href="/app/admin/reliability"
                                className="mt-4 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                Open Reliability Center →
                            </Link>
                        </div>
                    </div>

                    {m.horizon_workers != null && (
                        <div className="mb-6 text-xs text-slate-500">
                            Horizon workers: <span className="font-medium text-slate-700">{m.horizon_workers}</span>
                        </div>
                    )}

                    <h2 className="mb-4 text-lg font-semibold text-slate-900">Centers</h2>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {hubs.map((h) => {
                            const HubIcon = h.icon
                            return (
                                <Link key={h.href} href={h.href} className={hubCardClass}>
                                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-100">
                                        <HubIcon className="h-6 w-6 text-slate-700" />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="font-semibold text-slate-900">{h.name}</p>
                                        <p className="mt-1 text-sm text-slate-600">{h.description}</p>
                                    </div>
                                </Link>
                            )
                        })}
                    </div>

                    <div className="mt-10 flex flex-wrap gap-4 border-t border-slate-200 pt-6 text-sm">
                        <Link href="/app/admin/documentation" className="font-medium text-indigo-600 hover:text-indigo-800">
                            Documentation
                        </Link>
                        <span className="text-slate-300">|</span>
                        <Link href="/app/admin/assets" className="font-medium text-indigo-600 hover:text-indigo-800">
                            Asset operations
                        </Link>
                        <span className="text-slate-300">|</span>
                        <Link href="/app/admin/onboarding/defaults" className="font-medium text-indigo-600 hover:text-indigo-800">
                            Onboarding defaults
                        </Link>
                    </div>
                </AdminShell>
            </main>
            <AppFooter />
        </div>
    )
}
