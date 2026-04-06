import { useState, useEffect } from 'react'
import { usePage, Link, router, useForm } from '@inertiajs/react'
import {
    TrophyIcon,
    BuildingOfficeIcon,
    CheckCircleIcon,
    ClockIcon,
    ChartBarIcon,
    ExclamationTriangleIcon,
    SparklesIcon,
    LinkIcon,
    Squares2X2Icon,
    ArrowTrendingUpIcon,
    UsersIcon,
} from '@heroicons/react/24/outline'
import AppNav from '../../Components/AppNav'
import AppHead from '../../Components/AppHead'
import DashboardLinksRow from '../../Components/DashboardLinksRow'
import ManagedCompaniesClientList from '../../Components/dashboard/ManagedCompaniesClientList'
import AgencyBrandQuickJump from '../../Components/agency/AgencyBrandQuickJump'
import { showWorkspaceSwitchingOverlay } from '../../utils/workspaceSwitchOverlay'

/**
 * Agency Dashboard — Overview: brand quick-jump only; Progress: tier, stats, clients, rewards.
 */
const DASH_TABS = [
    { id: 'overview', label: 'Overview', icon: Squares2X2Icon },
    { id: 'clients', label: 'Clients', icon: BuildingOfficeIcon },
    { id: 'progress', label: 'Progress', icon: ArrowTrendingUpIcon },
    { id: 'team', label: 'Team', icon: UsersIcon },
]

export default function AgencyDashboard({
    auth,
    tenant,
    agency = {},
    rewards = [],
    clients = {},
    referrals = {},
    managed_clients = [],
    brands_readiness = [],
    readiness_summary = null,
    managed_agency = null,
    dashboard_links = {},
    can_create_incubated_client = false,
    agency_team = [],
    agency_team_external = [],
    agency_brands_summary = [],
}) {
    const [dashTab, setDashTab] = useState('overview')
    const [readinessToast, setReadinessToast] = useState(null)
    const [agencySyncToast, setAgencySyncToast] = useState(null)
    const [syncingTenantAgencyId, setSyncingTenantAgencyId] = useState(null)

    useEffect(() => {
        const tab = new URLSearchParams(window.location.search).get('tab')
        if (tab === 'clients' || tab === 'overview' || tab === 'progress' || tab === 'team') {
            setDashTab(tab)
        }
    }, [])

    const goDashTab = (id) => {
        setDashTab(id)
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href)
            url.searchParams.set('tab', id)
            window.history.replaceState({}, '', url)
        }
    }
    const [readinessAnimateKey, setReadinessAnimateKey] = useState(0)
    const page = usePage()
    const { auth: authFromPage, flash = {} } = page.props
    const brandColor = authFromPage?.activeBrand?.primary_color || '#6366f1'
    const activeBrand = authFromPage?.activeBrand

    const incubationPlanOptions = agency.incubation_plan_options || []

    const incubateForm = useForm({
        company_name: '',
        incubation_target_plan_key:
            incubationPlanOptions.find((o) => o.key === 'pro')?.key || incubationPlanOptions[0]?.key || 'pro',
    })

    const [extensionNoteByClient, setExtensionNoteByClient] = useState({})

    const submitIncubatedClient = (e) => {
        e.preventDefault()
        incubateForm.post('/app/agency/incubated-clients', {
            preserveScroll: true,
        })
    }

    const submitExtensionRequest = (clientId) => {
        router.post(
            `/app/agency/incubated-clients/${clientId}/extension-request`,
            { note: extensionNoteByClient[clientId] || '' },
            { preserveScroll: true }
        )
    }

    const changeTargetPlan = (clientId, planKey) => {
        router.patch(`/app/agency/incubated-clients/${clientId}`, { incubation_target_plan_key: planKey }, { preserveScroll: true })
    }

    const openAgencyDefaultOverview = () => {
        const db = managed_agency?.default_brand
        if (!db?.id) {
            router.visit('/app/overview')
            return
        }
        if (activeBrand?.id === db.id) {
            router.visit('/app/overview')
            return
        }
        showWorkspaceSwitchingOverlay('brand')
        router.post(
            `/app/brands/${db.id}/switch`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    window.location.href = '/app/overview'
                },
                onError: () => {
                    window.location.href = '/app/overview'
                },
            }
        )
    }
    const dashLinks = dashboard_links && typeof dashboard_links === 'object' ? dashboard_links : {}
    const hasDashboardLinks = Boolean(dashLinks.company || dashLinks.brand)

    const effectivePerms = Array.isArray(authFromPage?.effective_permissions) ? authFromPage.effective_permissions : []
    const canManageAgencyTeam = effectivePerms.includes('team.manage')

    const { incubated = [], activated = [], pending_transfers = [] } = clients
    const { total: totalReferrals = 0, activated: activatedReferrals = [], pending: pendingReferrals = [] } = referrals

    const formatDate = (isoString) => {
        if (!isoString) return 'N/A'
        return new Date(isoString).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        })
    }

    const formatCompanyRole = (r) => {
        if (!r || typeof r !== 'string') return 'Member'
        return r.charAt(0).toUpperCase() + r.slice(1)
    }

    /** Human copy for incubation window deadline (advisory). */
    const incubationDeadlineLine = (client) => {
        if (!client.incubation_expires_at) {
            return client.incubation_locked ? 'Support window ended' : null
        }
        const d = client.days_remaining
        const dateStr = formatDate(client.incubation_expires_at)
        if (client.incubation_locked || (typeof d === 'number' && d < 0)) {
            return `Window ended ${dateStr}`
        }
        if (typeof d === 'number' && d === 0) {
            return `Last day of window · ${dateStr}`
        }
        if (typeof d === 'number' && d > 0) {
            return `${d} day${d === 1 ? '' : 's'} left · ends ${dateStr}`
        }
        return `Ends ${dateStr}`
    }

    const hasExpiringSoon = incubated.some((client) => client.expiring_soon)

    useEffect(() => {
        if (!brands_readiness?.length) {
            return
        }
        const map = {}
        brands_readiness.forEach((row) => {
            map[row.brand.id] = row.brand.readiness?.readiness_score ?? 0
        })
        const raw = typeof sessionStorage !== 'undefined' ? sessionStorage.getItem('agency_readiness_snapshot') : null
        if (raw) {
            try {
                const prev = JSON.parse(raw)
                Object.keys(map).forEach((id) => {
                    const idn = Number(id)
                    const before = prev[id] ?? prev[idn]
                    const after = map[idn]
                    if (typeof before === 'number' && typeof after === 'number' && after > before) {
                        setReadinessToast(`Readiness improved: ${before}/5 → ${after}/5`)
                        setReadinessAnimateKey((k) => k + 1)
                    }
                })
            } catch {
                // ignore
            }
        }
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('agency_readiness_snapshot', JSON.stringify(map))
        }
    }, [brands_readiness])

    useEffect(() => {
        if (!readinessToast) {
            return
        }
        const t = setTimeout(() => setReadinessToast(null), 4500)
        return () => clearTimeout(t)
    }, [readinessToast])

    useEffect(() => {
        if (!agencySyncToast) {
            return
        }
        const t = setTimeout(() => setAgencySyncToast(null), 6000)
        return () => clearTimeout(t)
    }, [agencySyncToast])

    const csrfToken = () =>
        typeof document !== 'undefined'
            ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            : ''

    const handleSyncAgencyUsers = async (tenantAgencyId, clientName) => {
        setSyncingTenantAgencyId(tenantAgencyId)
        setAgencySyncToast(null)
        try {
            const res = await fetch(`/app/api/agency/tenant-agencies/${tenantAgencyId}/sync-users`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            })
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                setAgencySyncToast(data.error || 'Could not sync agency users.')
                return
            }
            const added = data.added ?? 0
            const skipped = data.skipped_existing_membership ?? 0
            setAgencySyncToast(
                added > 0
                    ? `Added ${added} team member${added !== 1 ? 's' : ''} to ${clientName}.`
                    : `No new members to add. (${skipped} already had access to ${clientName}.)`
            )
        } catch {
            setAgencySyncToast('Network error while syncing.')
        } finally {
            setSyncingTenantAgencyId(null)
        }
    }

    useEffect(() => {
        if (dashTab !== 'clients') {
            return
        }
        const onVis = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['brands_readiness', 'managed_clients', 'readiness_summary'] })
            }
        }
        document.addEventListener('visibilitychange', onVis)
        return () => document.removeEventListener('visibilitychange', onVis)
    }, [dashTab])

    const glassPanel =
        'rounded-xl bg-gradient-to-br from-white/[0.07] to-white/[0.02] ring-1 ring-white/10 backdrop-blur-sm'
    const statLabel = 'text-[10px] font-medium uppercase tracking-wider text-white/40'
    const bodyMuted = 'text-sm text-white/50'
    const bodySmall = 'text-xs text-white/40'

    return (
        <div className="relative min-h-screen bg-[#0B0B0D]">
            <AppHead title="Agency dashboard" />

            <div className="absolute top-0 left-0 right-0 z-50">
                <AppNav
                    brand={authFromPage?.activeBrand || auth?.activeBrand}
                    tenant={tenant}
                    variant="transparent"
                    hideWorkspaceAppNav
                />
            </div>

            <div className="relative">
                <div
                    className="pointer-events-none absolute inset-0 min-h-screen"
                    style={{
                        background:
                            'radial-gradient(circle at 20% 20%, #6366f133, transparent), radial-gradient(circle at 80% 80%, #8b5cf633, transparent), #0B0B0D',
                    }}
                />
                <div
                    className="pointer-events-none absolute inset-0 min-h-screen"
                    style={{
                        background: `radial-gradient(circle at 30% 40%, ${brandColor}14, transparent 60%)`,
                    }}
                />
                <div className="pointer-events-none absolute inset-0 min-h-screen bg-black/30" />
                <div className="pointer-events-none absolute inset-0 min-h-screen bg-gradient-to-b from-black/20 via-transparent to-black/55" />

                <main className="relative z-10">
                    {readinessToast && (
                        <div
                            className="fixed bottom-6 left-1/2 z-[100] max-w-md -translate-x-1/2 rounded-lg border border-emerald-500/30 bg-emerald-950/95 px-4 py-3 text-center text-sm text-emerald-100 shadow-lg ring-1 ring-emerald-500/20"
                            role="status"
                        >
                            {readinessToast}
                        </div>
                    )}
                    {agencySyncToast && (
                        <div
                            className={`fixed left-1/2 z-[100] max-w-md -translate-x-1/2 rounded-lg border border-sky-500/30 bg-sky-950/95 px-4 py-3 text-center text-sm text-sky-100 shadow-lg ring-1 ring-sky-500/20 ${
                                readinessToast ? 'bottom-24' : 'bottom-6'
                            }`}
                            role="status"
                        >
                            {agencySyncToast}
                        </div>
                    )}
                    <div className="mx-auto w-full max-w-7xl px-4 pb-16 pt-[calc(8rem+env(safe-area-inset-top))] sm:px-6 sm:pt-[calc(8.5rem+env(safe-area-inset-top))] lg:px-12">
                        <header className="mb-8 w-full max-w-none">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                <div className="min-w-0">
                                    <span className="text-[10px] font-medium uppercase tracking-wider text-white/35">
                                        Agency
                                    </span>
                                    <h1 className="mt-2 text-3xl font-semibold tracking-tight text-white md:text-4xl">
                                        Agency dashboard
                                    </h1>
                                    <p className="mt-2 text-sm text-white/50">
                                        Overview lists your client brands. Use Clients for onboarding and readiness; Progress for partner
                                        tier, rewards, and incubation status; Team for your agency workspace members and brand access.
                                    </p>
                                </div>
                                {hasDashboardLinks && (
                                    <DashboardLinksRow
                                        links={dashLinks}
                                        variant="dark"
                                        className="shrink-0 sm:pt-0.5 sm:text-right"
                                    />
                                )}
                            </div>
                        </header>

                        {flash.success && (
                            <div
                                className="mb-6 rounded-lg border border-emerald-500/30 bg-emerald-950/40 px-4 py-3 text-sm text-emerald-100/95"
                                role="status"
                            >
                                {flash.success}
                            </div>
                        )}

                        <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
                            <nav
                                className="flex shrink-0 flex-wrap gap-1 rounded-xl border border-white/10 bg-white/[0.04] p-1 lg:w-48 lg:flex-col"
                                aria-label="Agency dashboard sections"
                            >
                                {DASH_TABS.map((t) => {
                                    const Icon = t.icon
                                    const active = dashTab === t.id
                                    return (
                                        <button
                                            key={t.id}
                                            type="button"
                                            onClick={() => goDashTab(t.id)}
                                            className={`flex items-center gap-2 rounded-md px-3 py-2.5 text-left text-sm font-medium transition ${
                                                active
                                                    ? 'bg-white/10 text-white ring-1 ring-white/15'
                                                    : 'text-white/50 hover:bg-white/[0.06] hover:text-white/85'
                                            }`}
                                        >
                                            <Icon className="h-4 w-4 shrink-0 opacity-80" aria-hidden />
                                            {t.label}
                                        </button>
                                    )
                                })}
                            </nav>

                            <div className="min-w-0 flex-1 space-y-8">
                        {dashTab === 'overview' && (
                            <section className="mb-2">
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-[10px] font-medium uppercase tracking-wider text-white/35">Brands</span>
                                </div>
                                <AgencyBrandQuickJump clients={managed_clients} brandColor={brandColor} />
                            </section>
                        )}

                        {dashTab === 'clients' && (
                            <section className="mb-2">
                                <div className="mb-3 flex items-center gap-2">
                                    <span className="text-[10px] font-medium uppercase tracking-wider text-white/35">
                                        Managed companies
                                    </span>
                                </div>
                                {can_create_incubated_client && (
                                    <div className={`${glassPanel} mb-6 p-6 sm:p-8`}>
                                        <h3 className="text-lg font-semibold text-white">Start incubating a company</h3>
                                        <p className={`mt-1 ${bodyMuted}`}>
                                            Creates a new client workspace linked to your agency. You can transfer ownership later from
                                            company settings.
                                        </p>
                                        <form onSubmit={submitIncubatedClient} className="mt-5 flex flex-col gap-4">
                                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end">
                                            <div className="min-w-0 flex-1">
                                                <label htmlFor="incubate-company-name" className="sr-only">
                                                    Company name
                                                </label>
                                                <input
                                                    id="incubate-company-name"
                                                    type="text"
                                                    value={incubateForm.data.company_name}
                                                    onChange={(e) => incubateForm.setData('company_name', e.target.value)}
                                                    placeholder="Client company name"
                                                    className="w-full rounded-lg border border-white/15 bg-white/[0.06] px-4 py-2.5 text-sm text-white placeholder:text-white/35 focus:border-white/30 focus:outline-none focus:ring-2 focus:ring-white/20"
                                                    autoComplete="organization"
                                                />
                                                {incubateForm.errors.company_name && (
                                                    <p className="mt-2 text-xs text-rose-300/90">{incubateForm.errors.company_name}</p>
                                                )}
                                            </div>
                                            <div className="w-full sm:w-56">
                                                <label htmlFor="incubate-target-plan" className="mb-1 block text-xs text-white/45">
                                                    Target plan (limits during incubation)
                                                </label>
                                                <select
                                                    id="incubate-target-plan"
                                                    value={incubateForm.data.incubation_target_plan_key}
                                                    onChange={(e) =>
                                                        incubateForm.setData('incubation_target_plan_key', e.target.value)
                                                    }
                                                    className="w-full rounded-lg border border-white/15 bg-white/[0.06] px-3 py-2.5 text-sm text-white focus:border-white/30 focus:outline-none focus:ring-2 focus:ring-white/20"
                                                >
                                                    {incubationPlanOptions.map((opt) => (
                                                        <option key={opt.key} value={opt.key} className="bg-[#1a1a1f]">
                                                            {opt.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {incubateForm.errors.incubation_target_plan_key && (
                                                    <p className="mt-2 text-xs text-rose-300/90">
                                                        {incubateForm.errors.incubation_target_plan_key}
                                                    </p>
                                                )}
                                            </div>
                                            </div>
                                            <button
                                                type="submit"
                                                disabled={incubateForm.processing}
                                                className="inline-flex shrink-0 items-center justify-center rounded-lg bg-white/10 px-5 py-2.5 text-sm font-semibold text-white ring-1 ring-white/15 transition hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {incubateForm.processing ? 'Creating…' : 'Create incubated company'}
                                            </button>
                                        </form>
                                    </div>
                                )}
                                <div className={`${glassPanel} p-6 sm:p-8`}>
                                    <p className="text-sm text-white/60">
                                        Client workspaces linked to{' '}
                                        <span className="font-medium text-white/55">{managed_agency?.name ?? 'your agency'}</span>.
                                        Expand a brand for the full readiness summary, tasks, and checklist — use Open workspace to switch
                                        into that client.
                                    </p>
                                    {managed_agency?.name && (
                                        <div className="mt-5 rounded-lg border border-white/10 bg-white/[0.04] px-4 py-4">
                                            <p className="text-[10px] font-medium uppercase tracking-wide text-white/40">
                                                Your agency workspace
                                            </p>
                                            <p className="mt-1 text-sm text-white/50">
                                                Open your agency home at the default brand overview (
                                                {managed_agency.default_brand?.name ?? 'home'}).
                                            </p>
                                            <button
                                                type="button"
                                                onClick={openAgencyDefaultOverview}
                                                className="mt-3 inline-flex items-center rounded text-sm font-semibold text-white/90 underline decoration-white/30 underline-offset-2 hover:decoration-white/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                                            >
                                                {managed_agency.default_brand?.name
                                                    ? `Open ${managed_agency.default_brand.name} overview`
                                                    : 'Open agency overview'}
                                            </button>
                                        </div>
                                    )}
                                    {managed_clients.length > 0 ? (
                                        <div className="mt-6">
                                            <ManagedCompaniesClientList
                                                clients={managed_clients}
                                                theme="dark"
                                                brandColor={brandColor}
                                                showReadiness
                                                readinessSummary={readiness_summary}
                                                brandsReadiness={brands_readiness}
                                                readinessAnimateKey={readinessAnimateKey}
                                                onSyncAgencyUsers={handleSyncAgencyUsers}
                                                syncingTenantAgencyId={syncingTenantAgencyId}
                                            />
                                        </div>
                                    ) : (
                                        <div className="mt-6 rounded-lg border border-white/10 bg-white/[0.03] px-6 py-10 text-center">
                                            <p className="text-sm text-white/55">No linked client companies yet.</p>
                                            <p className="mt-2 text-xs text-white/35">
                                                Link clients from{' '}
                                                <Link
                                                    href="/app/companies/settings#agencies"
                                                    className="font-medium text-white/70 underline decoration-white/25 underline-offset-2 hover:text-white"
                                                >
                                                    Company settings → Agencies
                                                </Link>
                                                .
                                            </p>
                                        </div>
                                    )}
                                </div>
                            </section>
                        )}

                        {dashTab === 'team' && (
                            <section className="mb-2 space-y-6">
                                <div className={`${glassPanel} p-6 sm:p-8`}>
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <span className="text-[10px] font-medium uppercase tracking-wider text-white/35">
                                                Agency workspace
                                            </span>
                                            <h2 className="mt-1 text-lg font-semibold text-white">Brands and access</h2>
                                            <p className={`mt-2 max-w-2xl ${bodyMuted}`}>
                                                Brands in your agency company and how many team members have active access. Client-workspace
                                                brands are managed from the Clients tab.
                                            </p>
                                        </div>
                                        {canManageAgencyTeam && (
                                            <Link
                                                href="/app/companies/team"
                                                className="inline-flex shrink-0 items-center justify-center rounded-md bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/15 transition hover:bg-white/15"
                                            >
                                                Manage team
                                            </Link>
                                        )}
                                    </div>
                                    {agency_brands_summary.length > 0 ? (
                                        <ul className="mt-6 divide-y divide-white/10 rounded-md border border-white/10">
                                            {agency_brands_summary.map((b) => (
                                                <li
                                                    key={b.id}
                                                    className="flex flex-col gap-1 px-4 py-3 transition-colors hover:bg-white/[0.03] sm:flex-row sm:items-center sm:justify-between"
                                                >
                                                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                                                        <span className="font-medium text-white/90">{b.name}</span>
                                                        {b.is_default ? (
                                                            <span
                                                                className="rounded-sm border border-white/10 py-0.5 pl-2 pr-1.5 text-[10px] font-semibold uppercase tracking-wide text-white/80"
                                                                style={{ borderLeftWidth: 3, borderLeftColor: brandColor }}
                                                            >
                                                                Default
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <span className="text-sm tabular-nums text-white/45">
                                                        {b.members_with_access} member{b.members_with_access !== 1 ? 's' : ''}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className={`mt-6 ${bodyMuted}`}>No brands in this workspace yet.</p>
                                    )}
                                </div>

                                <div className={`${glassPanel} p-6 sm:p-8`}>
                                    <h2 className="text-lg font-semibold text-white">Team</h2>
                                    <p className={`mt-2 ${bodyMuted}`}>
                                        Agency workspace members (company + brand roles) are listed first. External guests—invited with
                                        collection-only access and no brand membership—are not agency staff; they appear separately with the
                                        collections they can open. Rows marked “synced” were added via client workspace access from another
                                        agency tenant.
                                    </p>
                                    {agency_team.length === 0 && agency_team_external.length === 0 ? (
                                        <p className={`mt-6 ${bodyMuted}`}>No team members loaded.</p>
                                    ) : (
                                        <>
                                            <h3 className="mt-6 text-sm font-semibold text-white/85">Agency team</h3>
                                            {agency_team.length > 0 ? (
                                                <div className="mt-3 overflow-x-auto rounded-md border border-white/10">
                                                    <table className="min-w-full text-left text-sm">
                                                        <thead>
                                                            <tr className="border-b border-white/10 text-[10px] font-semibold uppercase tracking-wider text-white/40">
                                                                <th className="px-4 py-3 font-semibold">Member</th>
                                                                <th className="hidden px-4 py-3 font-semibold sm:table-cell">Company role</th>
                                                                <th className="px-4 py-3 font-semibold">Brand access</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody className="divide-y divide-white/10">
                                                            {agency_team.map((m) => (
                                                                <tr key={m.id} className="transition-colors hover:bg-white/[0.04]">
                                                                    <td className="px-4 py-3 align-top">
                                                                        <div className="font-medium text-white/90">{m.name}</div>
                                                                        <div className="text-xs text-white/45">{m.email}</div>
                                                                        {m.is_agency_managed && m.agency_tenant_name ? (
                                                                            <div className="mt-1 text-[11px] text-sky-200/80">
                                                                                Synced · {m.agency_tenant_name}
                                                                            </div>
                                                                        ) : null}
                                                                        <div className="mt-1 text-xs text-white/40 sm:hidden">
                                                                            {formatCompanyRole(m.company_role)}
                                                                        </div>
                                                                    </td>
                                                                    <td className="hidden px-4 py-3 align-top text-white/70 sm:table-cell">
                                                                        {formatCompanyRole(m.company_role)}
                                                                    </td>
                                                                    <td className="px-4 py-3 align-top">
                                                                        {m.brand_roles?.length ? (
                                                                            <div className="flex flex-wrap gap-1.5">
                                                                                {m.brand_roles.map((br) => (
                                                                                    <span
                                                                                        key={`${m.id}-${br.brand_id}`}
                                                                                        className="inline-flex max-w-full items-center gap-1 rounded-sm border border-white/10 bg-white/[0.05] px-2 py-0.5 text-xs text-white/75 transition-colors hover:border-white/20 hover:[border-left-width:3px] hover:[border-left-style:solid] hover:[border-left-color:var(--agency-team-accent)]"
                                                                                        style={
                                                                                            {
                                                                                                '--agency-team-accent': brandColor,
                                                                                            }
                                                                                        }
                                                                                    >
                                                                                        <span className="max-w-[140px] truncate">{br.brand_name}</span>
                                                                                        <span className="text-white/40">·</span>
                                                                                        <span className="shrink-0 capitalize text-white/50">
                                                                                            {br.role}
                                                                                        </span>
                                                                                    </span>
                                                                                ))}
                                                                            </div>
                                                                        ) : (
                                                                            <span className="text-xs text-white/35">No brand access</span>
                                                                        )}
                                                                    </td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            ) : (
                                                <p className={`mt-3 ${bodyMuted}`}>No agency team members in this list.</p>
                                            )}

                                            {agency_team_external.length > 0 ? (
                                                <>
                                                    <h3 className="mt-8 text-sm font-semibold text-amber-200/90">External access</h3>
                                                    <p className={`mt-2 max-w-2xl ${bodySmall}`}>
                                                        Collection-only guests: they have a company login for this workspace but no brand
                                                        membership. They are not part of your agency team.
                                                    </p>
                                                    <div className="mt-3 overflow-x-auto rounded-md border border-amber-500/20 bg-amber-500/[0.04]">
                                                        <table className="min-w-full text-left text-sm">
                                                            <thead>
                                                                <tr className="border-b border-white/10 text-[10px] font-semibold uppercase tracking-wider text-white/40">
                                                                    <th className="px-4 py-3 font-semibold">Member</th>
                                                                    <th className="hidden px-4 py-3 font-semibold sm:table-cell">Company role</th>
                                                                    <th className="px-4 py-3 font-semibold">Collections</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody className="divide-y divide-white/10">
                                                                {agency_team_external.map((m) => (
                                                                    <tr key={m.id} className="transition-colors hover:bg-white/[0.04]">
                                                                        <td className="px-4 py-3 align-top">
                                                                            <div className="font-medium text-white/90">{m.name}</div>
                                                                            <div className="text-xs text-white/45">{m.email}</div>
                                                                            <div className="mt-1 text-xs text-white/40 sm:hidden">
                                                                                {formatCompanyRole(m.company_role)}
                                                                            </div>
                                                                        </td>
                                                                        <td className="hidden px-4 py-3 align-top text-white/70 sm:table-cell">
                                                                            {formatCompanyRole(m.company_role)}
                                                                        </td>
                                                                        <td className="px-4 py-3 align-top">
                                                                            {m.collections?.length ? (
                                                                                <div className="flex flex-wrap gap-1.5">
                                                                                    {m.collections.map((c) => (
                                                                                        <span
                                                                                            key={`${m.id}-col-${c.id}`}
                                                                                            className="inline-flex max-w-full items-center rounded-sm border border-amber-500/25 bg-amber-500/10 px-2 py-0.5 text-xs text-amber-100/90"
                                                                                        >
                                                                                            <span className="max-w-[200px] truncate">{c.name}</span>
                                                                                        </span>
                                                                                    ))}
                                                                                </div>
                                                                            ) : (
                                                                                <span className="text-xs text-white/35">Collection access</span>
                                                                            )}
                                                                        </td>
                                                                    </tr>
                                                                ))}
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </>
                                            ) : null}
                                        </>
                                    )}
                                    {!canManageAgencyTeam && (agency_team.length > 0 || agency_team_external.length > 0) ? (
                                        <p className={`mt-4 ${bodySmall}`}>
                                            Ask an owner or admin to change roles or invites in Company settings → Team.
                                        </p>
                                    ) : null}
                                </div>
                            </section>
                        )}

                        {dashTab === 'progress' && (
                            <>
                        <div className="flex w-full min-w-0 flex-col gap-6">
                            {/* Partner tier + snapshot stats (moved from Overview) */}
                            <div className={`${glassPanel} p-6 sm:p-8`}>
                                <div
                                    className={`flex flex-col gap-6 sm:flex-row sm:items-start ${
                                        agency.tier.reward_percentage ? 'sm:justify-between' : ''
                                    }`}
                                >
                                    <div className="flex items-start gap-4">
                                        <div
                                            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-white/[0.08] ring-1 ring-white/10"
                                            style={{ boxShadow: `0 0 32px ${brandColor}22` }}
                                        >
                                            <TrophyIcon className="h-8 w-8 text-white/90" aria-hidden />
                                        </div>
                                        <div>
                                            <h2 className="text-2xl font-semibold text-white">{agency.tier.name} Partner</h2>
                                            <p className="mt-1 text-sm text-white/50">
                                                {agency.activated_client_count} activated client
                                                {agency.activated_client_count !== 1 ? 's' : ''}
                                            </p>
                                        </div>
                                    </div>
                                    {agency.tier.reward_percentage && (
                                        <div className="text-left sm:text-right">
                                            <p className="text-xs text-white/40">Partner reward rate</p>
                                            <p className="text-2xl font-semibold tabular-nums" style={{ color: brandColor }}>
                                                {agency.tier.reward_percentage}%
                                            </p>
                                            <p className="mt-1 text-xs text-white/45">
                                                Earn up to {agency.tier.reward_percentage}% in partner credits
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {agency.next_tier && (
                                    <div className="mt-8 border-t border-white/10 pt-6">
                                        <div className="mb-2 flex items-center justify-between gap-4">
                                            <p className="text-sm font-medium text-white/80">
                                                Progress to {agency.next_tier.name}
                                            </p>
                                            <p className="text-sm tabular-nums text-white/50">
                                                {agency.activated_client_count} / {agency.next_tier.threshold}
                                            </p>
                                        </div>
                                        <div className="h-2 w-full overflow-hidden rounded-full bg-white/10">
                                            <div
                                                className="h-full rounded-full transition-all duration-300"
                                                style={{
                                                    width: `${agency.next_tier.progress_percentage}%`,
                                                    backgroundColor: brandColor,
                                                }}
                                            />
                                        </div>
                                        {agency.next_tier.activations_to_next_tier > 0 && (
                                            <p className="mt-3 flex items-start gap-2 text-sm text-white/55">
                                                <SparklesIcon className="h-4 w-4 inline shrink-0 mt-0.5" style={{ color: brandColor }} />
                                                <span>
                                                    Complete {agency.next_tier.activations_to_next_tier} more client activation
                                                    {agency.next_tier.activations_to_next_tier !== 1 ? 's' : ''} to reach{' '}
                                                    {agency.next_tier.name}
                                                </span>
                                            </p>
                                        )}
                                    </div>
                                )}

                                {!agency.next_tier && agency.activated_client_count > 0 && (
                                    <div className="mt-6 rounded-lg border border-white/10 bg-white/[0.04] px-4 py-3">
                                        <p className="text-sm text-white/75 flex items-center gap-2">
                                            <TrophyIcon className="h-4 w-4 shrink-0 text-amber-300/90" />
                                            You&apos;ve reached the highest partner tier. Thank you for your partnership!
                                        </p>
                                    </div>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                                {[
                                    {
                                        icon: BuildingOfficeIcon,
                                        label: 'Incubated clients',
                                        value: incubated.length,
                                        hint: 'Prepared by your agency',
                                        warn: hasExpiringSoon,
                                    },
                                    {
                                        icon: CheckCircleIcon,
                                        label: 'Activated clients',
                                        value: activated.length,
                                        hint: 'Ownership transferred — partner rewards active',
                                        accent: 'text-emerald-300/90',
                                    },
                                    {
                                        icon: ClockIcon,
                                        label: 'Pending billing',
                                        value: pending_transfers.length,
                                        hint: pending_transfers.length > 0 ? 'Transfer completes once billing is activated' : null,
                                    },
                                    {
                                        icon: LinkIcon,
                                        label: 'Referrals',
                                        value: totalReferrals,
                                        hint: `${activatedReferrals.length} activated, ${pendingReferrals.length} pending`,
                                        accent: 'text-violet-300/90',
                                    },
                                ].map((s, i) => (
                                    <div key={i} className={`${glassPanel} p-6`}>
                                        <div className="mb-3 flex items-center gap-2">
                                            <s.icon className={`h-5 w-5 ${s.accent || 'text-white/40'}`} aria-hidden />
                                            <span className={statLabel}>{s.label}</span>
                                        </div>
                                        <p className="text-3xl font-semibold tabular-nums text-white">{s.value}</p>
                                        {s.hint && <p className={`mt-2 ${bodySmall}`}>{s.hint}</p>}
                                        {s.warn && (
                                            <p className="mt-2 flex items-center gap-1 text-xs text-amber-300/90">
                                                <ExclamationTriangleIcon className="h-3.5 w-3.5" />
                                                Some windows ending soon
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>

                            {/* Client overview — full width */}
                            <div className={`${glassPanel} w-full min-w-0 overflow-hidden`}>
                                <div className="border-b border-white/10 px-6 py-5">
                                    <h3 className="text-lg font-semibold text-white">Client overview</h3>
                                    <p className={`mt-1 ${bodyMuted}`}>
                                        Incubated and activated clients.{' '}
                                        <span className="text-white/40">
                                            Sync agency users adds your agency team to the client workspace (same as on the Clients tab).
                                        </span>
                                    </p>
                                </div>
                                <div className="p-6">
                                    <div className="mb-8">
                                        <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold text-white/85">
                                            <CheckCircleIcon className="h-4 w-4 text-emerald-400/90" />
                                            Activated ({activated.length})
                                        </h4>
                                        {activated.length === 0 ? (
                                            <div className="ml-6">
                                                <p className="text-sm text-white/50">No activated clients yet</p>
                                                <p className={`mt-1 ${bodySmall}`}>Transfer to client to activate ownership</p>
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {activated.map((client) => (
                                                    <div
                                                        key={client.id}
                                                        className="flex items-center justify-between gap-4 rounded-lg py-2 pl-2 pr-2 transition hover:bg-white/[0.04]"
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <p className="text-sm font-medium text-white">{client.name}</p>
                                                            <span className="inline-flex items-center rounded-md bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-200 ring-1 ring-emerald-500/25">
                                                                Activated
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-white/40">{client.activated_at_human}</p>
                                                    </div>
                                                ))}
                                                <p className={`ml-6 mt-2 ${bodySmall}`}>
                                                    Client owns this company — partner rewards active
                                                </p>
                                            </div>
                                        )}
                                    </div>

                                    {pending_transfers.length > 0 && (
                                        <div className="mb-8">
                                            <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold text-white/85">
                                                <ClockIcon className="h-4 w-4 text-amber-300/90" />
                                                Pending billing ({pending_transfers.length})
                                            </h4>
                                            <div className="space-y-2">
                                                {pending_transfers.map((transfer) => (
                                                    <div
                                                        key={transfer.id}
                                                        className="flex items-center justify-between gap-4 rounded-lg py-2 pl-2 pr-2 transition hover:bg-white/[0.04]"
                                                    >
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <p className="text-sm font-medium text-white">{transfer.client_name}</p>
                                                            <span className="inline-flex items-center rounded-md bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-200 ring-1 ring-amber-500/25">
                                                                Pending billing
                                                            </span>
                                                        </div>
                                                        <p className="text-xs text-white/40">{transfer.accepted_at_human}</p>
                                                    </div>
                                                ))}
                                                <p className="ml-6 mt-2 text-xs text-amber-300/80">
                                                    Ownership transfer will complete once billing is activated
                                                </p>
                                            </div>
                                        </div>
                                    )}

                                    {incubated.length > 0 && (
                                        <div>
                                            <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold text-white/85">
                                                <BuildingOfficeIcon className="h-4 w-4 text-white/40" />
                                                Incubated ({incubated.length})
                                            </h4>
                                            <div className="space-y-4">
                                                {incubated.map((client) => {
                                                    const deadlineText = incubationDeadlineLine(client)
                                                    return (
                                                    <div
                                                        key={client.id}
                                                        className="rounded-lg border border-white/10 bg-white/[0.03] p-4"
                                                    >
                                                        <div className="min-w-0">
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <p className="text-sm font-medium text-white">{client.name}</p>
                                                                <span className="inline-flex items-center rounded-md bg-white/[0.08] px-2 py-0.5 text-xs font-medium text-white/70 ring-1 ring-white/10">
                                                                    Incubated
                                                                </span>
                                                                {client.incubation_locked && (
                                                                    <span className="inline-flex items-center rounded-md bg-rose-500/15 px-2 py-0.5 text-xs font-medium text-rose-200 ring-1 ring-rose-500/25">
                                                                        Window ended — locked
                                                                    </span>
                                                                )}
                                                                {client.expiring_soon && !client.incubation_locked && (
                                                                    <span className="inline-flex items-center rounded-md bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-200 ring-1 ring-amber-500/25">
                                                                        <ExclamationTriangleIcon className="mr-1 h-3 w-3" />
                                                                        {client.days_remaining} day
                                                                        {client.days_remaining !== 1 ? 's' : ''} left
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {(deadlineText || client.incubation_extension_requested_at) && (
                                                                <div className="mt-2 flex flex-col gap-1.5 text-xs text-white/50">
                                                                    {deadlineText && (
                                                                        <p className="inline-flex flex-wrap items-center gap-1.5">
                                                                            <ClockIcon className="h-3.5 w-3.5 shrink-0 text-white/35" aria-hidden />
                                                                            <span className="text-white/65">{deadlineText}</span>
                                                                        </p>
                                                                    )}
                                                                    {client.incubation_extension_requested_at && (
                                                                        <p className="text-amber-200/85">
                                                                            Extension requested{' '}
                                                                            {formatDate(client.incubation_extension_requested_at)}
                                                                        </p>
                                                                    )}
                                                                </div>
                                                            )}
                                                            {typeof client.tenant_agency_id === 'number' && (
                                                                <div className="mt-3">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() =>
                                                                            handleSyncAgencyUsers(client.tenant_agency_id, client.name)
                                                                        }
                                                                        disabled={syncingTenantAgencyId === client.tenant_agency_id}
                                                                        className="shrink-0 rounded-lg bg-white/[0.08] px-3 py-1.5 text-xs font-semibold text-white/90 ring-1 ring-white/15 hover:bg-white/[0.12] disabled:cursor-not-allowed disabled:opacity-50"
                                                                        title="Add agency staff who are not yet members of this client workspace"
                                                                    >
                                                                        {syncingTenantAgencyId === client.tenant_agency_id
                                                                            ? 'Syncing…'
                                                                            : 'Sync agency users'}
                                                                    </button>
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)] sm:items-end">
                                                            <div className="min-w-0">
                                                                <label
                                                                    className="mb-1 block text-[10px] font-medium uppercase tracking-wide text-white/35"
                                                                    htmlFor={`target-plan-${client.id}`}
                                                                >
                                                                    Target plan
                                                                </label>
                                                                <select
                                                                    id={`target-plan-${client.id}`}
                                                                    value={client.incubation_target_plan_key || 'pro'}
                                                                    onChange={(e) =>
                                                                        changeTargetPlan(client.id, e.target.value)
                                                                    }
                                                                    className="w-full rounded-lg border border-white/15 bg-white/[0.06] px-3 py-2 text-sm text-white focus:border-white/30 focus:outline-none focus:ring-2 focus:ring-white/20"
                                                                >
                                                                    {incubationPlanOptions.map((opt) => (
                                                                        <option key={opt.key} value={opt.key} className="bg-[#1a1a1f]">
                                                                            {opt.label}
                                                                        </option>
                                                                    ))}
                                                                </select>
                                                            </div>
                                                            <div className="min-w-0">
                                                                <label
                                                                    className="mb-1 block text-[10px] font-medium uppercase tracking-wide text-white/35"
                                                                    htmlFor={`ext-note-${client.id}`}
                                                                >
                                                                    Request deadline extension (support)
                                                                </label>
                                                                <div className="flex gap-2">
                                                                    <input
                                                                        id={`ext-note-${client.id}`}
                                                                        type="text"
                                                                        value={extensionNoteByClient[client.id] || ''}
                                                                        onChange={(e) =>
                                                                            setExtensionNoteByClient((prev) => ({
                                                                                ...prev,
                                                                                [client.id]: e.target.value,
                                                                            }))
                                                                        }
                                                                        placeholder="Optional note for support"
                                                                        className="min-w-0 flex-1 rounded-lg border border-white/15 bg-white/[0.06] px-3 py-2 text-sm text-white placeholder:text-white/35"
                                                                    />
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => submitExtensionRequest(client.id)}
                                                                        className="shrink-0 rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold text-white ring-1 ring-white/15 hover:bg-white/15"
                                                                    >
                                                                        Submit request
                                                                    </button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    );
                                                })}
                                                <p className={`ml-6 mt-2 ${bodySmall}`}>
                                                    Prepared by your agency — transfer to activate ownership
                                                </p>
                                                {hasExpiringSoon && (
                                                    <div className="ml-6 mt-3 rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2">
                                                        <p className="text-xs text-amber-200/90 flex items-center gap-2">
                                                            <ExclamationTriangleIcon className="h-4 w-4 shrink-0" />
                                                            Incubation window ending soon — transfer to activate
                                                        </p>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {incubated.length === 0 && activated.length === 0 && pending_transfers.length === 0 && (
                                        <div className="py-10 text-center">
                                            <BuildingOfficeIcon className="mx-auto mb-3 h-12 w-12 text-white/20" />
                                            <p className="text-white/55">No clients yet</p>
                                            <p className={`mt-1 ${bodySmall}`}>Incubate a client company to get started</p>
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Partner rewards ledger — full width below */}
                            <div className={`${glassPanel} w-full min-w-0 overflow-hidden`}>
                                <div className="border-b border-white/10 px-6 py-5">
                                    <h3 className="text-lg font-semibold text-white">Partner rewards ledger</h3>
                                    <p className={`mt-1 ${bodyMuted}`}>History of earned partner rewards</p>
                                </div>
                                <div className="p-6">
                                    {rewards.length === 0 ? (
                                        <div className="py-10 text-center">
                                            <ChartBarIcon className="mx-auto mb-3 h-12 w-12 text-white/20" />
                                            <p className="text-white/55">No partner rewards earned yet</p>
                                            <p className={`mt-2 ${bodySmall}`}>
                                                Transfer to client and activate billing to earn partner rewards
                                            </p>
                                            {agency.tier.reward_percentage && (
                                                <p className="mt-3 text-xs" style={{ color: brandColor }}>
                                                    Earn up to {agency.tier.reward_percentage}% in partner credits
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="space-y-4">
                                            {rewards.map((reward) => (
                                                <div
                                                    key={reward.id}
                                                    className="flex items-center justify-between gap-4 border-b border-white/[0.06] py-3 last:border-0"
                                                >
                                                    <div>
                                                        <p className="font-medium text-white">{reward.client_name}</p>
                                                        <p className="text-xs text-white/40">{reward.reward_type}</p>
                                                    </div>
                                                    <p className="text-xs text-white/45">{reward.created_at_human}</p>
                                                </div>
                                            ))}
                                            <div className="border-t border-white/10 pt-4">
                                                <p className="text-sm text-white/50">Total partner rewards: {rewards.length}</p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>

                        {totalReferrals > 0 && (
                            <div className={`${glassPanel} mt-8 overflow-hidden`}>
                                <div className="border-b border-white/10 px-6 py-5">
                                    <h3 className="flex items-center gap-2 text-lg font-semibold text-white">
                                        <LinkIcon className="h-5 w-5 text-violet-300/90" />
                                        Referrals
                                    </h3>
                                    <p className={`mt-1 ${bodyMuted}`}>Clients referred to the platform by your agency</p>
                                </div>
                                <div className="p-6">
                                    <div className="grid grid-cols-1 gap-8 md:grid-cols-2">
                                        <div>
                                            <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold text-white/85">
                                                <CheckCircleIcon className="h-4 w-4 text-emerald-400/90" />
                                                Activated referrals ({activatedReferrals.length})
                                            </h4>
                                            {activatedReferrals.length === 0 ? (
                                                <p className="ml-6 text-sm text-white/45">No activated referrals yet</p>
                                            ) : (
                                                <div className="space-y-2">
                                                    {activatedReferrals.map((referral) => (
                                                        <div
                                                            key={referral.id}
                                                            className="flex items-center justify-between gap-4 rounded-lg py-2 pl-2 pr-2 transition hover:bg-white/[0.04]"
                                                        >
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <p className="text-sm font-medium text-white">{referral.client_name}</p>
                                                                <span className="inline-flex items-center rounded-md bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-200 ring-1 ring-emerald-500/25">
                                                                    Activated
                                                                </span>
                                                            </div>
                                                            <p className="text-xs text-white/40">{referral.activated_at_human}</p>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                        <div>
                                            <h4 className="mb-3 flex items-center gap-2 text-sm font-semibold text-white/85">
                                                <ClockIcon className="h-4 w-4 text-white/40" />
                                                Pending referrals ({pendingReferrals.length})
                                            </h4>
                                            {pendingReferrals.length === 0 ? (
                                                <p className="ml-6 text-sm text-white/45">No pending referrals</p>
                                            ) : (
                                                <div className="space-y-2">
                                                    {pendingReferrals.map((referral) => (
                                                        <div
                                                            key={referral.id}
                                                            className="flex items-center justify-between gap-4 rounded-lg py-2 pl-2 pr-2 transition hover:bg-white/[0.04]"
                                                        >
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <p className="text-sm font-medium text-white">{referral.client_name}</p>
                                                                <span className="inline-flex items-center rounded-md bg-white/[0.08] px-2 py-0.5 text-xs font-medium text-white/70 ring-1 ring-white/10">
                                                                    Pending
                                                                </span>
                                                                {referral.source && (
                                                                    <span className="text-xs text-white/35">via {referral.source}</span>
                                                                )}
                                                            </div>
                                                            <p className="text-xs text-white/40">{referral.created_at_human}</p>
                                                        </div>
                                                    ))}
                                                    <p className={`ml-6 mt-2 ${bodySmall}`}>
                                                        Referrals activate when clients add billing
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        )}
                            </>
                        )}

                        </div>
                        </div>

                        <footer className="mt-12 border-t border-white/10 pt-8 text-center text-xs text-white/30">
                            <span>Jackpot</span> © {new Date().getFullYear()} —{' '}
                            <a
                                href="https://velvetysoft.com"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-white/45 hover:text-white/70"
                            >
                                Velvetysoft
                            </a>
                        </footer>
                    </div>
                </main>
            </div>
        </div>
    )
}
