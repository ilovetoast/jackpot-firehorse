import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import AddCreatorModal from '../../Components/prostaff/AddCreatorModal'
import CreatorStatsCards from '../../Components/prostaff/CreatorStatsCards'
import CreatorsTable from '../../Components/prostaff/CreatorsTable'

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
 * }} props
 */
export default function CreatorsDashboard({ brand, canManageCreators = false }) {
    const page = usePage()
    const { auth } = page.props
    const activeBrand = auth?.activeBrand
    const tenant = tenantNavFromAuth(auth)

    const brandColor = brand?.primary_color || activeBrand?.primary_color || '#6366f1'
    const secondaryForBackdrop =
        brand?.secondary_color || activeBrand?.secondary_color || activeBrand?.accent_color || brandColor
    const backdropBackground = overviewDefaultBackdrop(brandColor, secondaryForBackdrop)

    const [creators, setCreators] = useState([])
    const [loading, setLoading] = useState(true)
    const [moduleInactive, setModuleInactive] = useState(false)
    const [loadError, setLoadError] = useState(null)
    const [modalOpen, setModalOpen] = useState(false)

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
                return
            }
            if (!res.ok) {
                setLoadError(data?.error || data?.message || 'Could not load creators.')
                setCreators([])
                return
            }
            setCreators(Array.isArray(data) ? data : [])
        } catch {
            setLoadError('Network error.')
            setCreators([])
        } finally {
            setLoading(false)
        }
    }, [brand?.id])

    useEffect(() => {
        fetchDashboard()
    }, [fetchDashboard])

    const existingCreatorUserIds = useMemo(() => creators.map((r) => r.user_id).filter(Boolean), [creators])

    const openDamForCreator = useCallback((userId) => {
        router.get(
            '/app/assets',
            { submitted_by_prostaff: 1, prostaff_user_id: userId },
            { preserveState: false }
        )
    }, [])

    const hasAgencyQuickLink = Array.isArray(auth?.companies)
        ? auth.companies.some((company) => company?.is_agency === true)
        : false
    const mobileTopPaddingClass = hasAgencyQuickLink
        ? 'pt-[calc(9rem+env(safe-area-inset-top))] lg:pt-[calc(9rem+1.5rem+env(safe-area-inset-top))] xl:pt-[calc(9rem+2rem+env(safe-area-inset-top))]'
        : 'pt-[calc(5.75rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]'

    const addButton = (
        <button
            type="button"
            onClick={() => setModalOpen(true)}
            className="inline-flex items-center justify-center rounded-xl bg-white/90 px-4 py-2.5 text-sm font-semibold text-gray-900 shadow-lg shadow-black/20 transition hover:bg-white"
        >
            Add Creator
        </button>
    )

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
                    <CreatorStatsCards creators={creators} loading={loading && !moduleInactive} />

                    <CreatorsTable
                        creators={creators}
                        loading={loading && !moduleInactive}
                        onRowClick={openDamForCreator}
                        emptyCta={!moduleInactive && canManageCreators ? addButton : null}
                        emptyReadOnlyHint={
                            !canManageCreators
                                ? 'Performance data will appear here once your targets are set for the current period.'
                                : null
                        }
                    />
                </div>
            </main>

            <div className="relative z-10">
                <AppFooter variant="dark" />
            </div>

            {canManageCreators ? (
                <AddCreatorModal
                    open={modalOpen}
                    onClose={() => setModalOpen(false)}
                    brandId={brand.id}
                    existingCreatorUserIds={existingCreatorUserIds}
                    onSuccess={fetchDashboard}
                />
            ) : null}
        </div>
    )
}
