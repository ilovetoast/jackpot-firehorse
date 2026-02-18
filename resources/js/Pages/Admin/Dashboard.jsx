import { useState } from 'react'
import { Link } from '@inertiajs/react'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import { useAdminPermissions } from '../../hooks/useAdminPermissions'
import {
    ExclamationTriangleIcon,
    CheckCircleIcon,
    XCircleIcon,
    ClockIcon,
    QueueListIcon,
    ChartBarIcon,
    UsersIcon,
    BuildingOffice2Icon,
    CogIcon,
    BoltIcon,
    CreditCardIcon,
    DocumentTextIcon,
    ArrowDownTrayIcon,
    CloudArrowUpIcon,
    TagIcon,
    BookOpenIcon,
    BellIcon,
    EnvelopeIcon,
    LockClosedIcon,
    FolderIcon,
    ChevronDownIcon,
    ChevronUpIcon,
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
    const wrapperClass = "rounded-2xl border border-slate-200 bg-white p-4 shadow-sm hover:border-slate-300 transition-colors"
    return href ? (
        <Link href={href} className={`block ${wrapperClass}`}>
            {content}
        </Link>
    ) : (
        <div className={wrapperClass}>{content}</div>
    )
}

function SectionCard({ title, description, items, defaultExpanded = true }) {
    const [expanded, setExpanded] = useState(defaultExpanded)
    return (
        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
            <button
                type="button"
                onClick={() => setExpanded(!expanded)}
                className="w-full px-5 py-4 flex items-center justify-between text-left hover:bg-slate-50"
            >
                <div>
                    <h3 className="text-base font-semibold text-slate-900">{title}</h3>
                    <p className="text-sm text-slate-500">{description}</p>
                </div>
                {expanded ? (
                    <ChevronUpIcon className="h-5 w-5 text-slate-400" />
                ) : (
                    <ChevronDownIcon className="h-5 w-5 text-slate-400" />
                )}
            </button>
            {expanded && (
                <div className="border-t border-slate-200 px-5 py-4">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {items.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className="flex items-center gap-3 rounded-xl border border-slate-200 p-4 hover:border-indigo-200 hover:bg-indigo-50/30 transition-colors"
                            >
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                                    <item.icon className="h-5 w-5 text-slate-600" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium text-slate-900">{item.name}</p>
                                    <p className="text-sm text-slate-500">{item.description}</p>
                                </div>
                            </Link>
                        ))}
                    </div>
                </div>
            )}
        </div>
    )
}

export default function AdminDashboard({ auth, metrics: initialMetrics }) {
    const perms = useAdminPermissions()
    const [metrics, setMetrics] = useState(initialMetrics || {})

    const m = metrics
    const incidents = m.incidents || {}
    const queue = m.queue || {}
    const scheduler = m.scheduler || {}
    const autoRecovery = m.auto_recovery || {}
    const support = m.support || {}
    const ai = m.ai || {}
    const failures = m.failures || {}
    const org = m.organization || {}
    const health = m.health_score || {}
    const healthStatusClass = STATUS_COLORS[health.status] || STATUS_COLORS.unknown

    const operationsItems = [
        { name: 'Operations Center', description: `Critical: ${incidents.critical ?? 0} | Errors: ${incidents.error ?? 0}`, href: '/app/admin/operations-center', icon: ExclamationTriangleIcon },
        { name: 'System Status', description: 'Monitor system health', href: '/app/admin/system-status', icon: CogIcon },
        { name: 'Logs', description: 'Web & worker logs', href: '/app/admin/logs', icon: DocumentTextIcon },
        { name: 'Failed Jobs', description: `${queue.failed_count ?? 0} failed`, href: '/app/admin/operations-center?tab=failed-jobs', icon: QueueListIcon },
        { name: 'Derivative Failures', description: `${failures.derivative_escalated ?? 0} escalated`, href: '/app/admin/derivative-failures', icon: ExclamationTriangleIcon },
        { name: 'Upload Failures', description: `${failures.upload_failures_24h ?? 0} (24h)`, href: '/app/admin/upload-failures', icon: CloudArrowUpIcon },
        { name: 'Download Failures', description: `${failures.download_failures_24h ?? 0} (24h)`, href: '/app/admin/download-failures', icon: ArrowDownTrayIcon },
    ].filter(Boolean)

    const supportItems = [
        { name: 'Support Tickets', description: `${support.open_tickets ?? 0} open`, href: '/app/admin/support/tickets', icon: ChartBarIcon },
        { name: 'Engineering Tickets', description: `${support.engineering_tickets ?? 0} open`, href: '/app/admin/support/tickets?type=engineering', icon: CogIcon },
    ]

    const aiItems = perms.canViewAI
        ? [
            { name: 'AI Dashboard', description: 'Costs, models, agents', href: '/app/admin/ai', icon: BoltIcon },
            { name: 'AI Agent Health', description: `${ai.failures_24h ?? 0} failures (24h)`, href: '/app/admin/ai-agents', icon: BoltIcon },
            { name: 'AI Budgets', description: `$${ai.cost_24h_usd ?? 0} (24h)`, href: '/app/admin/ai/budgets', icon: ChartBarIcon },
        ]
        : []

    const billingItems = [
        { name: 'Stripe Management', description: 'Subscriptions & billing', href: '/app/admin/stripe-status', icon: CreditCardIcon },
        { name: 'Billing Overview', description: 'Income, MRR, reports', href: '/app/admin/billing', icon: ChartBarIcon },
    ]

    const configItems = [
        { name: 'Permissions', description: 'Role permissions', href: '/app/admin/permissions', icon: LockClosedIcon },
        ...(perms.canViewMetadataRegistry ? [{ name: 'Metadata Registry', description: 'System metadata fields', href: '/app/admin/metadata/registry', icon: TagIcon }] : []),
        { name: 'System Categories', description: 'Category templates', href: '/app/admin/system-categories', icon: FolderIcon },
        { name: 'Notifications', description: 'Email templates', href: '/app/admin/notifications', icon: BellIcon },
        { name: 'Email Test', description: 'Test email sending', href: '/app/admin/email-test', icon: EnvelopeIcon },
    ]

    return (
        <div className="min-h-full">
            <AppNav brand={auth.activeBrand} tenant={null} />
            <main className="bg-slate-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
                    <div className="mb-8">
                        <h1 className="text-3xl font-bold tracking-tight text-slate-900">Command Center</h1>
                        <p className="mt-2 text-sm text-slate-600">Executive system overview and operations</p>
                    </div>

                    {/* 1. Executive KPI Header */}
                    <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <MetricCard
                            title="Open Incidents"
                            value={incidents.total_unresolved ?? 0}
                            subtitle={`${incidents.critical ?? 0} critical`}
                            status={incidents.critical > 0 ? 'critical' : incidents.error > 0 ? 'error' : 'healthy'}
                            href="/app/admin/operations-center"
                            icon={ExclamationTriangleIcon}
                        />
                        <MetricCard
                            title="Queue Health"
                            value={queue.status ?? '—'}
                            subtitle={`${queue.pending_count ?? 0} pending, ${queue.failed_count ?? 0} failed`}
                            status={queue.status || 'unknown'}
                            href="/app/admin/operations-center?tab=queue"
                            icon={QueueListIcon}
                        />
                        <MetricCard
                            title="Scheduler"
                            value={scheduler.status ?? '—'}
                            subtitle={scheduler.heartbeat_age_minutes != null ? `Heartbeat ${scheduler.heartbeat_age_minutes}m ago` : 'No heartbeat'}
                            status={scheduler.status || 'unknown'}
                            icon={ClockIcon}
                        />
                        <MetricCard
                            title="Active Tenants / Users"
                            value={`${org.total_tenants ?? 0} / ${org.total_users ?? 0}`}
                            subtitle={`${org.active_subscriptions ?? 0} active subscriptions`}
                            status="healthy"
                            href="/app/admin/organization"
                            icon={UsersIcon}
                        />
                    </div>

                    {/* System Health Score + Auto-Recovery */}
                    <div className="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wide">System Health Score</h3>
                            <div className="mt-3 flex items-baseline gap-3">
                                <span className={`text-4xl font-bold ${healthStatusClass.split(' ')[0]}`}>
                                    {health.score ?? '—'}
                                </span>
                                <span className="text-lg font-medium text-slate-600">/ 100</span>
                                <span className={`rounded-full px-2.5 py-0.5 text-sm font-medium ${healthStatusClass}`}>
                                    {health.status ?? 'Unknown'}
                                </span>
                            </div>
                            {health.deductions?.length > 0 && (
                                <ul className="mt-3 text-sm text-slate-500 list-disc list-inside">
                                    {health.deductions.map((d, i) => (
                                        <li key={i}>{d}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h3 className="text-sm font-semibold text-slate-500 uppercase tracking-wide">Auto Recovery (24h)</h3>
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
                                href="/app/admin/operations-center"
                                className="mt-4 inline-flex text-sm font-medium text-indigo-600 hover:text-indigo-500"
                            >
                                View Operations Center →
                            </Link>
                        </div>
                    </div>

                    {/* Metadata strip */}
                    <div className="mb-8 flex flex-wrap gap-4 text-sm text-slate-500">
                        {m.last_deploy && (
                            <span>Last deploy: {m.last_deploy}</span>
                        )}
                        {m.horizon_workers != null && (
                            <span>Horizon workers: {m.horizon_workers}</span>
                        )}
                    </div>

                    {/* 2. Grouped Sections */}
                    <div className="space-y-6">
                        {perms.canViewOperations && (
                            <SectionCard
                                title="Operations & Recovery"
                                description="Incidents, queue, scheduler, logs, failures"
                                items={operationsItems}
                                defaultExpanded={true}
                            />
                        )}
                        {perms.canViewSupport && (
                            <SectionCard
                                title="Support & SLA"
                                description={`${support.open_tickets ?? 0} open tickets`}
                                items={supportItems}
                                defaultExpanded={true}
                            />
                        )}
                        {perms.canViewAI && (
                            <SectionCard
                                title="AI & Automation"
                                description={`${ai.runs_24h ?? 0} runs (24h), $${ai.cost_24h_usd ?? 0} cost`}
                                items={aiItems}
                                defaultExpanded={true}
                            />
                        )}
                        <SectionCard
                            title="Billing & Stripe"
                            description="Subscriptions, MRR, storage"
                            items={billingItems}
                            defaultExpanded={false}
                        />
                        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                            <div className="px-5 py-4">
                                <h3 className="text-base font-semibold text-slate-900">Organization Management</h3>
                                <p className="text-sm text-slate-500">Companies and users</p>
                            </div>
                            <div className="border-t border-slate-200 px-5 py-4">
                                <Link
                                    href="/app/admin/organization"
                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-4 py-3 font-medium text-slate-900 hover:border-indigo-200 hover:bg-indigo-50/30"
                                >
                                    <BuildingOffice2Icon className="h-5 w-5" />
                                    View Companies & Users
                                </Link>
                            </div>
                        </div>
                        <SectionCard
                            title="System Configuration"
                            description="Permissions, metadata, categories, notifications"
                            items={configItems}
                            defaultExpanded={false}
                        />
                        <SectionCard
                            title="Documentation"
                            description="System documentation"
                            items={[{ name: 'Documentation', description: 'View docs', href: '/app/admin/documentation', icon: BookOpenIcon }]}
                            defaultExpanded={false}
                        />
                    </div>
                </div>
            </main>
            <AppFooter />
        </div>
    )
}
