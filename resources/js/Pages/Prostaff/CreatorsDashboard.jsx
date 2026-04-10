import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import AddCreatorModal from '../../Components/prostaff/AddCreatorModal'
import CreatorModuleRequiredModal from '../../Components/prostaff/CreatorModuleRequiredModal'
import CreatorPerformanceKpis from '../../Components/prostaff/CreatorPerformanceKpis'
import CreatorStatsCards from '../../Components/prostaff/CreatorStatsCards'
import CreatorsTable from '../../Components/prostaff/CreatorsTable'
import PendingCreatorInvitesTable from '../../Components/prostaff/PendingCreatorInvitesTable'
import { parseProstaffDashboardResponse } from '../../utils/parseProstaffDashboardResponse'

function overviewDefaultBackdrop(primaryHex, secondaryHex) {
    const p = /^#?([0-9a-fA-F]{6})/i.exec(String(primaryHex || '').trim())
    const s = /^#?([0-9a-fA-F]{6})/i.exec(String(secondaryHex || '').trim())
    const p6 = p ? p[1] : '6366f1'
    const s6 = s ? s[1] : '8b5cf6'
    return `radial-gradient(circle at 20% 20%, #${p6}33, transparent), radial-gradient(circle at 80% 80%, #${s6}33, transparent), #0B0B0D`
}

function tenantNavFromAuth(auth) {
    const c = auth?.activeCompany
    if (!c) return null
    return { id: c.id, name: c.name, slug: c.slug }
}

/**
 * @param {{
 *   brand: { id: number, name: string, slug?: string, logo_path?: string|null, primary_color?: string|null, secondary_color?: string|null, accent_color?: string|null },
 *   canManageCreators?: boolean,
 *   creatorModuleEnabled?: boolean,
 *   creatorApproversConfigured?: boolean,
 * }} props
 */
export default function CreatorsDashboard({
    brand,
    canManageCreators = false,
    creatorModuleEnabled = true,
    creatorApproversConfigured = false,
}) {
    const page = usePage()
    const { auth } = page.props
    const activeBrand = auth?.activeBrand
    const tenant = tenantNavFromAuth(auth)

    const brandColor = brand?.primary_color || activeBrand?.primary_color || '#6366f1'
    const secondaryForBackdrop =
        brand?.secondary_color || activeBrand?.secondary_color || activeBrand?.accent_color || brandColor
    const backdropBackground = overviewDefaultBackdrop(brandColor, secondaryForBackdrop)

    const [creators, setCreators] = useState([])
    const [pendingInvites, setPendingInvites] = useState([])
    const [loading, setLoading] = useState(true)
    const [moduleInactive, setModuleInactive] = useState(false)
    const [loadError, setLoadError] = useState(null)
    const [modalOpen, setModalOpen] = useState(false)
    const [moduleModalOpen, setModuleModalOpen] = useState(false)
    const [approversModalOpen, setApproversModalOpen] = useState(false)

    const fetchDashboard = useCallback(async () => {
        if (!brand?.id) return
        setLoading(true)
        setLoadError(null)
        setModuleInactive(false)
        try {
            const res = await fetch(route('api.brands.prostaff.dashboard', { brand: brand.id }), {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
            const data = await res.json().catch(() => ({}))
            if (res.status === 403 && data?.error === 'creator_module_inactive') {
                setModuleInactive(true)
                setCreators([])
                setPendingInvites([])
                return
            }
            if (!res.ok) {
                setLoadError(data?.error || data?.message || 'Could not load creators.')
                setCreators([])
                setPendingInvites([])
                return
            }
            const parsed = parseProstaffDashboardResponse(data)
            setCreators(parsed.active)
            setPendingInvites(parsed.pendingInvitations)
        } catch {
            setLoadError('Network error.')
            setCreators([])
            setPendingInvites([])
        } finally {
            setLoading(false)
        }
    }, [brand?.id])

    useEffect(() => {
        fetchDashboard()
    }, [fetchDashboard])

    const existingCreatorEmails = useMemo(() => {
        const fromRows = creators.map((r) => r.email).filter(Boolean)
        const fromPending = pendingInvites.map((p) => p.email).filter(Boolean)
        return [...new Set([...fromRows, ...fromPending].map((e) => String(e).toLowerCase()))]
    }, [creators, pendingInvites])

    const openDamForCreator = useCallback((userId) => {
        router.get('/app/assets', { prostaff_user_id: userId }, { preserveState: false })
    }, [])

    const openCreatorProfile = useCallback(
        (prostaffMembershipId) => {
            router.visit(
                route('brands.creators.show', { brand: brand.id, membership: prostaffMembershipId }),
            )
        },
        [brand.id],
    )

    const hasAgencyQuickLink = Array.isArray(auth?.companies)
        ? auth.companies.some((company) => company?.is_agency === true)
        : false
    const mobileTopPaddingClass = hasAgencyQuickLink
        ? 'pt-[calc(9rem+env(safe-area-inset-top))] lg:pt-[calc(9rem+1.5rem+env(safe-area-inset-top))] xl:pt-[calc(9rem+2rem+env(safe-area-inset-top))]'
        : 'pt-[calc(5.75rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]'

    const openAddFlow = () => {
        if (!creatorModuleEnabled || moduleInactive) {
            setModuleModalOpen(true)
            return
        }
        if (!creatorApproversConfigured) {
            setApproversModalOpen(true)
            return
        }
        setModalOpen(true)
    }

    const addButton = (
        <button
            type="button"
            onClick={openAddFlow}
            className="inline-flex items-center justify-center rounded-xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 transition hover:bg-white"
        >
            Add Creator
        </button>
    )

    const settingsUrl =
        typeof route === 'function'
            ? `${route('brands.edit', { brand: brand.id })}?tab=creators`
            : `/app/brands/${brand.id}/edit?tab=creators`

    return (
        <div className="relative min-h-[100dvh] overflow-x-hidden bg-[#0B0B0D] pb-28 sm:pb-16">
            <AppHead title={`Creators — ${brand?.name || 'Brand'}`} />

            <div className="absolute left-0 right-0 top-0 z-50 overflow-visible">
                <AppNav brand={activeBrand} tenant={tenant} variant="transparent" />
            </div>

            <div
                className="pointer-events-none fixed inset-0"
                style={{ background: backdropBackground }}
            />
            <div
                className="pointer-events-none fixed inset-0"
                style={{
                    background: `radial-gradient(circle at 30% 40%, ${brandColor}14, transparent 60%)`,
                }}
            />
            <div className="pointer-events-none fixed inset-0 bg-black/35" />
            <div className="pointer-events-none fixed inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/55" />

            <main
                className={`relative z-10 mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-10 ${mobileTopPaddingClass} pb-12`}
            >
                {moduleInactive ? (
                    <div className="mb-8 flex flex-col gap-4 rounded-2xl border border-amber-400/25 bg-amber-500/10 px-5 py-4 backdrop-blur-xl sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p className="text-sm font-semibold text-amber-50">Creator module is not active</p>
                            <p className="mt-1 text-xs text-amber-100/80">
                                Enable the Creator add-on for this workspace to manage creators and performance.
                            </p>
                        </div>
                        <Link
                            href={route('companies.settings')}
                            className="inline-flex shrink-0 items-center justify-center rounded-xl border border-amber-200/40 bg-amber-400/20 px-4 py-2 text-sm font-semibold text-amber-50 transition hover:bg-amber-400/30"
                        >
                            Upgrade
                        </Link>
                    </div>
                ) : null}

                {loadError && !moduleInactive ? (
                    <div className="mb-6 rounded-2xl border border-rose-400/25 bg-rose-500/10 px-4 py-3 text-sm text-rose-100 backdrop-blur-md">
                        {loadError}
                    </div>
                ) : null}

                {!moduleInactive &&
                creatorModuleEnabled &&
                canManageCreators &&
                !creatorApproversConfigured ? (
                    <div className="mb-6 rounded-2xl border border-amber-400/30 bg-amber-500/[0.12] px-4 py-3 text-sm text-amber-50 backdrop-blur-md">
                        <p className="font-semibold text-amber-100">Approvers required to activate creator workflow</p>
                        <p className="mt-1 text-xs text-amber-100/85">
                            Choose at least one creator approver in Brand Settings → Creators before you can add creators
                            or run approvals. This avoids blocked uploads and surprise errors later.
                        </p>
                        <div className="mt-3">
                            <Link
                                href={settingsUrl}
                                className="inline-flex text-xs font-semibold text-amber-100 underline decoration-amber-300/50 underline-offset-2 hover:text-white"
                            >
                                Configure approvers
                            </Link>
                        </div>
                    </div>
                ) : null}

                <header className="flex flex-col gap-6 border-b border-white/10 pb-8 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Creators</h1>
                        <p className="mt-2 max-w-xl text-sm text-white/55">
                            {canManageCreators
                                ? "Manage and track your brand's creators"
                                : 'Your creator performance for this brand'}
                        </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-3">
                        {!moduleInactive && canManageCreators ? addButton : null}
                    </div>
                </header>

                <div className="mt-8 space-y-8">
                    {canManageCreators && !moduleInactive ? (
                        <CreatorPerformanceKpis
                            rows={creators}
                            loading={loading}
                            brandColor={brandColor}
                        />
                    ) : null}

                    <CreatorStatsCards
                        creators={creators}
                        pendingInviteCount={canManageCreators ? pendingInvites.length : 0}
                        loading={loading && !moduleInactive}
                    />

                    {!moduleInactive && canManageCreators && pendingInvites.length > 0 ? (
                        <PendingCreatorInvitesTable invites={pendingInvites} />
                    ) : null}

                    <CreatorsTable
                        creators={creators}
                        loading={loading && !moduleInactive}
                        onRowClick={openCreatorProfile}
                        onDamClick={openDamForCreator}
                        emptySuppressed={!loading && creators.length === 0 && pendingInvites.length > 0}
                        emptyCta={!moduleInactive && canManageCreators && creatorApproversConfigured ? addButton : null}
                        emptyReadOnlyHint={
                            !canManageCreators
                                ? 'Performance data will appear here once your targets are set for the current period.'
                                : !creatorApproversConfigured && canManageCreators
                                  ? 'Configure creator approvers in Brand Settings, then add creators to start tracking performance here.'
                                  : null
                        }
                    />
                </div>
            </main>

            <div className="relative z-10">
                <AppFooter variant="dark" />
            </div>

            <CreatorModuleRequiredModal open={moduleModalOpen} onClose={() => setModuleModalOpen(false)} />

            {approversModalOpen ? (
                <div className="fixed inset-0 z-[210] flex items-end justify-center p-4 sm:items-center">
                    <button
                        type="button"
                        className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                        aria-label="Close"
                        onClick={() => setApproversModalOpen(false)}
                    />
                    <div className="relative w-full max-w-md rounded-2xl border border-white/10 bg-[#12141a]/95 p-6 shadow-2xl backdrop-blur-2xl">
                        <h2 className="text-lg font-semibold text-white">Assign approvers first</h2>
                        <p className="mt-2 text-sm text-white/60">
                            Choose at least one creator approver in Brand Settings before adding creators.
                        </p>
                        <div className="mt-6 flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setApproversModalOpen(false)}
                                className="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-medium text-white/80"
                            >
                                Close
                            </button>
                            <Link
                                href={settingsUrl}
                                className="inline-flex rounded-xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-gray-900"
                            >
                                Brand Settings → Creators
                            </Link>
                        </div>
                    </div>
                </div>
            ) : null}

            {canManageCreators ? (
                <AddCreatorModal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    brandId={brand.id}
                    existingCreatorEmails={existingCreatorEmails}
                    onSuccess={fetchDashboard}
                />
            ) : null}
        </div>
    )
}
