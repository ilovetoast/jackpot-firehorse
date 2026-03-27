import { useEffect, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { motion } from 'framer-motion'
import AppHead from '../../Components/AppHead'
import DashboardLinksRow from '../../Components/DashboardLinksRow'
import AppNav from '../../Components/AppNav'
import ManagedCompaniesTeaser from '../../Components/dashboard/ManagedCompaniesTeaser'
import PrimaryActions from '../../Components/dashboard/PrimaryActions'
import AssetCollage from '../../Components/dashboard/AssetCollage'
import ActiveSignals from '../../Components/Brand/ActiveSignals'
import AIInsights from '../../Components/Brand/AIInsights'
import RecentMomentum from '../../Components/Brand/RecentMomentum'

function formatStorage(mb) {
    if (!mb || mb === 0) return '0 MB'
    if (mb < 1) return `${(mb * 1024).toFixed(0)} KB`
    if (mb < 1024) return `${mb.toFixed(1)} MB`
    return `${(mb / 1024).toFixed(1)} GB`
}

export default function Overview({
    auth,
    tenant,
    brand,
    plan,
    permissions = {},
    theme = {},
    stats = null,
    is_manager = false,
    collage_assets = [],
    most_viewed_assets = [],
    most_trending_assets = [],
    ai_usage = null,
    pending_ai_suggestions = null,
    pending_metadata_approvals_count = 0,
    brand_signals = [],
    momentum_data = {},
    ai_insights = [],
    insights_deferred = false,
    dashboard_links = {},
}) {
    const page = usePage()
    const { auth: authFromPage } = page.props
    const activeBrand = brand ?? authFromPage?.activeBrand ?? auth?.activeBrand
    const brandColor = theme.colors?.primary || '#6366f1'
    const managedAgencyClients = authFromPage?.managed_agency_clients ?? []
    const showManagedCompanies =
        Boolean(authFromPage?.activeCompany?.is_agency) && managedAgencyClients.length > 0

    const isAgencyAccount = authFromPage?.activeCompany?.is_agency === true
    const dashLinksRaw = dashboard_links && typeof dashboard_links === 'object' ? dashboard_links : {}
    // Cinematic brand overview: company settings link only (you’re already on brand overview).
    const dashLinks = {
        company: dashLinksRaw.company,
        company_label: dashLinksRaw.company_label,
    }
    const hasDashboardLinks = Boolean(dashLinks.company)

    // Prefer dedicated collage assets (sorted by quality), fall back to most viewed
    const collageAssets = collage_assets?.length
        ? collage_assets
        : most_viewed_assets?.length
            ? most_viewed_assets
            : most_trending_assets || []

    const totalAssets = stats?.total_assets?.value ?? 0
    const storageMB = stats?.storage_mb?.value ?? 0
    const collectionsCount = stats?.collections_count ?? 0
    const downloadsCount = stats?.download_links?.value ?? 0
    const executionsCount = stats?.executions_count ?? 0
    const aiReviews = pending_ai_suggestions?.total ?? 0

    const [brandSignalsState, setBrandSignalsState] = useState(brand_signals)
    const [momentumDataState, setMomentumDataState] = useState(momentum_data)
    const [aiInsightsState, setAiInsightsState] = useState(ai_insights)
    const [insightsLoading, setInsightsLoading] = useState(Boolean(insights_deferred))

    useEffect(() => {
        setBrandSignalsState(brand_signals)
        setMomentumDataState(momentum_data)
        setAiInsightsState(ai_insights)
    }, [brand?.id, brand_signals, momentum_data, ai_insights])

    useEffect(() => {
        if (!insights_deferred) {
            setInsightsLoading(false)
            return
        }
        const ac = new AbortController()
        let cancelled = false
        setInsightsLoading(true)
        fetch('/app/overview/insights', {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: ac.signal,
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                if (cancelled || !data) return
                setBrandSignalsState(data.brand_signals ?? [])
                setMomentumDataState(data.momentum_data ?? {})
                setAiInsightsState(data.ai_insights ?? [])
            })
            .catch(() => {
                /* keep empty; overview still usable */
            })
            .finally(() => {
                if (!cancelled) setInsightsLoading(false)
            })
        return () => {
            cancelled = true
            ac.abort()
        }
    }, [insights_deferred, brand?.id])

    // Everyone sees assets + executions
    // Managers/admins see the full set
    const metrics = []
    if (totalAssets > 0) metrics.push({ label: 'assets', value: totalAssets.toLocaleString() })
    if (executionsCount > 0) metrics.push({ label: 'executions', value: executionsCount.toLocaleString() })

    if (is_manager) {
        if (storageMB > 0) metrics.push({ label: 'storage', value: formatStorage(storageMB) })
        if (downloadsCount > 0) metrics.push({ label: 'downloads', value: downloadsCount.toLocaleString() })
        if (collectionsCount > 0) metrics.push({ label: 'collections', value: collectionsCount.toLocaleString() })
        if (aiReviews > 0) metrics.push({ label: 'AI reviews', value: aiReviews.toLocaleString() })
    }

    return (
        <div className="relative h-[100dvh] max-h-[100dvh] overflow-hidden overscroll-none bg-[#0B0B0D]">
            <AppHead title="Overview" />

            {/* Nav — absolute so it overlays the cinematic content */}
            <div className="absolute top-0 left-0 right-0 z-50">
                <AppNav brand={authFromPage?.activeBrand || auth?.activeBrand} tenant={tenant} variant="transparent" />
            </div>

            <div className="relative flex h-full min-h-0 flex-col overflow-hidden">
                {/* Cinematic background */}
                <div
                    className="absolute inset-0 will-change-transform"
                    style={{
                        background: theme.background?.value || 'radial-gradient(circle at 20% 20%, #6366f133, transparent), radial-gradient(circle at 80% 80%, #8b5cf633, transparent), #0B0B0D',
                        transform: 'scale(1)',
                    }}
                />
                {/* Left column radial accent (brand-driven) */}
                <div
                    className="absolute inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(circle at 30% 40%, ${brandColor}14, transparent 60%)`,
                    }}
                />

                {/* Depth overlays */}
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-black/30" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />
                </div>

                {/* Asset collage — pairs with left column below; absolute right, ~38% width (see AssetCollage.jsx). */}
                <AssetCollage assets={collageAssets} />

                {/* Main content — scroll on small viewports (PWA: single pane + overscroll containment) */}
                <div className="relative z-10 flex min-h-0 flex-1 flex-col">
                    <div className="relative mx-auto flex h-full min-h-0 w-full max-w-7xl flex-1 flex-col px-4 sm:px-6 lg:px-12">
                        <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-y-contain [-webkit-overflow-scrolling:touch] lg:overflow-visible lg:overscroll-auto">
                            {/*
                             * ---------------------------------------------------------------------------
                             * OVERVIEW COLUMN LAYOUT — DO NOT CHANGE without explicit design/product sign-off.
                             * ---------------------------------------------------------------------------
                             * Intended behavior (lg+): a fixed LEFT content column (max 50% of this max-w-7xl
                             * container), left-aligned — NOT viewport-centered — so the cinematic asset
                             * collage (absolute; right: 0; ~38vw) reads as a distinct right pane. Using
                             * mx-auto on this column breaks that split and stacks content in the middle.
                             * Mobile: full-width column; collage hidden.
                             * ---------------------------------------------------------------------------
                             */}
                            <motion.div
                                className="flex w-full min-w-0 max-w-full flex-col justify-start space-y-4 pb-28 pt-[5.5rem] sm:space-y-6 sm:pb-24 lg:mx-0 lg:max-w-[50%] lg:min-h-full lg:justify-center lg:space-y-6 lg:pb-16 lg:pt-12 xl:pt-16"
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.4 }}
                            >
                                {/* Plan badge + optional Dashboards links — grouped so the badge never overlaps the heading on narrow screens */}
                                <div className="animate-fadeInUp space-y-3 sm:space-y-4">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                        <div className="min-w-0">
                                            {plan?.show_badge && plan?.name && (
                                                <span className="inline-flex max-w-full flex-wrap items-center gap-x-1 rounded-full border border-white/10 bg-white/[0.06] px-3 py-1 text-xs font-medium text-white/60 backdrop-blur-sm">
                                                    <span>
                                                        {plan.name} Plan
                                                        {isAgencyAccount && (
                                                            <span className="text-white/45"> · Agency account</span>
                                                        )}
                                                    </span>
                                                </span>
                                            )}
                                        </div>
                                        {hasDashboardLinks && (
                                            <DashboardLinksRow
                                                links={dashLinks}
                                                variant="dark"
                                                className="shrink-0 sm:pt-0.5 sm:text-right"
                                            />
                                        )}
                                    </div>

                                    <h1 className="animate-fadeInUp-d2 break-words text-3xl font-semibold leading-tight tracking-tight text-white md:text-4xl">
                                        {theme.name || activeBrand?.name || 'Overview'}
                                    </h1>
                                </div>

                        {/* Tagline */}
                        {theme.tagline && (
                            <p className="animate-fadeInUp-d2 text-white/50 mt-3 text-sm leading-relaxed">
                                {theme.tagline}
                            </p>
                        )}

                        {/* Inline metrics — lightweight stat line */}
                        {metrics.length > 0 && (
                            <div className="animate-fadeInUp-d3 flex flex-wrap items-center gap-x-5 gap-y-2 mt-6 text-xs">
                                {metrics.map((m, i) => (
                                    <span key={m.label} className="flex items-center gap-1 text-white/40">
                                        <span className="text-white/80 font-medium">{m.value}</span>
                                        {m.label}
                                        {i < metrics.length - 1 && (
                                            <span className="ml-3 text-white/10 select-none" aria-hidden>•</span>
                                        )}
                                    </span>
                                ))}
                            </div>
                        )}

                        {/* Deferred insights: lightweight placeholder while signals + LLM load after first paint */}
                        {insights_deferred && insightsLoading && (
                            <div
                                className="animate-pulse space-y-3"
                                aria-busy="true"
                                aria-label="Loading brand insights"
                            >
                                <div className="h-24 rounded-xl bg-white/[0.06] ring-1 ring-white/[0.06]" />
                                <div className="h-20 rounded-xl bg-white/[0.04] ring-1 ring-white/[0.05]" />
                            </div>
                        )}

                        {/* Active signals — What Needs Attention (permission-filtered, no empty block) */}
                        {brandSignalsState?.length > 0 && (
                            <ActiveSignals
                                signals={brandSignalsState}
                                insights={aiInsightsState}
                                brandColor={brandColor}
                                permissions={permissions}
                            />
                        )}

                        {/* AI Insights — only show orphans (insights that don't match any signal) */}
                        {aiInsightsState?.length > 0 && brandSignalsState?.length > 0 && (() => {
                            const signalTypes = new Set(
                                brandSignalsState
                                    .map((s) => {
                                        const c = s?.context?.category
                                        if (c === 'ai_suggestions') return 'suggestions'
                                        return c
                                    })
                                    .filter(Boolean)
                            )
                            const orphans = aiInsightsState.filter((ins) => !ins.type || !signalTypes.has(ins.type))
                            return orphans.length > 0 ? <AIInsights insights={orphans} /> : null
                        })()}
                        {aiInsightsState?.length > 0 && (!brandSignalsState || brandSignalsState.length === 0) && (
                            <AIInsights insights={aiInsightsState} />
                        )}

                        {/* Recent Momentum — aggregated, meaningful */}
                        <RecentMomentum data={momentumDataState} />

                        {/* Agency: client companies linked via tenant_agencies */}
                        {showManagedCompanies && (
                            <ManagedCompaniesTeaser
                                count={managedAgencyClients.length}
                                brandColor={brandColor}
                            />
                        )}

                        {/* Primary action tiles — Brand Settings / Insights when user has permissions */}
                                <PrimaryActions
                                    permissions={permissions}
                                    brand={activeBrand}
                                    brandColor={brandColor}
                                />
                            </motion.div>

                            {/* Powered by footer (default theme only) */}
                            {theme.mode === 'default' && (
                                <div className="pointer-events-none shrink-0 px-1 pb-4 pt-2 text-xs text-white/20 lg:absolute lg:bottom-6 lg:left-6 lg:px-0 lg:pb-0 lg:pt-0">
                                    Powered by Jackpot
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    )
}
