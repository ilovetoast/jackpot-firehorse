import { useEffect, useMemo, useState } from 'react'
import { usePage } from '@inertiajs/react'
import { motion } from 'framer-motion'
import { SparklesIcon } from '@heroicons/react/24/outline'
import AppHead from '../../Components/AppHead'
import DashboardLinksRow from '../../Components/DashboardLinksRow'
import AppNav from '../../Components/AppNav'
import ManagedCompaniesTeaser from '../../Components/dashboard/ManagedCompaniesTeaser'
import PrimaryActions from '../../Components/dashboard/PrimaryActions'
import AssetCollage from '../../Components/dashboard/AssetCollage'
import ActiveSignals from '../../Components/Brand/ActiveSignals'
import AIInsights from '../../Components/Brand/AIInsights'
import RecentMomentum from '../../Components/Brand/RecentMomentum'
import OverviewCollageSkeleton from '../../Components/Overview/OverviewCollageSkeleton'
import InsightsLoading from '../../Components/Overview/InsightsLoading'
import { SkeletonMetricPills, SkeletonPlanBadge } from '../../Components/Overview/OverviewSkeletons'
import CreatorProgressCard from '../../Components/prostaff/CreatorProgressCard'
import { summarizeMomentum } from '../../utils/summarizeMomentum'
import { resolveOverviewIconColor } from '../../utils/colorUtils'

function formatStorage(mb) {
    if (!mb || mb === 0) return '0 MB'
    if (mb < 1) return `${(mb * 1024).toFixed(0)} KB`
    if (mb < 1024) return `${mb.toFixed(1)} MB`
    return `${(mb / 1024).toFixed(1)} GB`
}

/** Match BrandThemeBuilder::resolveBackground (6-digit hex + alpha stops). */
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

const HERO_URL =
    typeof route === 'function' ? route('overview.api.hero') : '/app/api/overview/hero'
const STATS_URL =
    typeof route === 'function' ? route('overview.api.stats') : '/app/api/overview/stats'
const INSIGHTS_URL =
    typeof route === 'function' ? route('overview.api.insights') : '/app/api/overview/insights'
const PROSTAFF_ME_URL =
    typeof route === 'function' ? route('api.prostaff.me') : '/app/api/prostaff/me'

/** Dedupe concurrent hero/stats fetches (e.g. React Strict Mode double-mount). */
const overviewHeroInflight = new Map()
const overviewStatsInflight = new Map()

const INSIGHTS_SESSION_PREFIX = 'overview_insights_v1:'
const INSIGHTS_CLIENT_TTL_MS = 5 * 60 * 1000

function readInsightsSessionCache(brandId) {
    if (typeof sessionStorage === 'undefined' || brandId == null) return null
    try {
        const raw = sessionStorage.getItem(`${INSIGHTS_SESSION_PREFIX}${brandId}`)
        if (!raw) return null
        const { at, payload } = JSON.parse(raw)
        if (!payload || typeof payload !== 'object') return null
        if (Date.now() - at > INSIGHTS_CLIENT_TTL_MS) return null
        return payload
    } catch {
        return null
    }
}

function writeInsightsSessionCache(brandId, payload) {
    if (typeof sessionStorage === 'undefined' || brandId == null) return
    try {
        sessionStorage.setItem(
            `${INSIGHTS_SESSION_PREFIX}${brandId}`,
            JSON.stringify({ at: Date.now(), payload })
        )
    } catch {
        /* session quota */
    }
}

/**
 * Cinematic brand overview — SSR is an empty shell; metrics, assets, and insights load after paint.
 */
export default function Overview() {
    const page = usePage()
    const { auth: authFromPage } = page.props
    const auth = authFromPage

    const [hero, setHero] = useState(null)
    const [stats, setStats] = useState(null)
    /** null = heavy tier not finished (show placeholder); object = loaded (possibly empty). */
    const [insights, setInsights] = useState(null)
    /** Prostaff self dashboard for active brand; null if not prostaff or error. */
    const [prostaffMe, setProstaffMe] = useState(null)
    const [prostaffMeLoading, setProstaffMeLoading] = useState(false)

    const activeBrand = authFromPage?.activeBrand ?? auth?.activeBrand
    const brandId = activeBrand?.id

    const theme = stats?.theme ?? {}
    const plan = stats?.plan
    const statsBlock = stats?.stats
    const permissions = stats?.permissions ?? {}
    const is_manager = stats?.is_manager ?? false
    const pending_ai_suggestions = stats?.pending_ai_suggestions
    const dashboard_links = stats?.dashboard_links ?? {}

    const brandSignalsState = insights?.brand_signals ?? []
    const momentumDataState = insights?.momentum_data ?? {}
    const aiInsightsState = insights?.ai_insights ?? []
    const insightsUpdatedAt = insights?.generated_at ?? null

    // Theme from API wins when loaded; until then use Inertia auth.activeBrand so first paint matches brand (no indigo flash).
    const brandColor =
        theme.colors?.primary || activeBrand?.primary_color || '#6366f1'
    const secondaryForBackdrop =
        theme.colors?.secondary ||
        activeBrand?.secondary_color ||
        activeBrand?.accent_color ||
        brandColor
    const overviewBackdropBackground =
        theme.background?.value ||
        overviewDefaultBackdrop(brandColor, secondaryForBackdrop)

    const overviewIconColor = useMemo(
        () =>
            resolveOverviewIconColor(brandColor, {
                secondary: theme.colors?.secondary || activeBrand?.secondary_color || null,
                accent: activeBrand?.accent_color || null,
            }),
        [brandColor, theme.colors?.secondary, activeBrand?.secondary_color, activeBrand?.accent_color]
    )

    const managedAgencyClients = authFromPage?.managed_agency_clients ?? []
    const showManagedCompanies =
        Boolean(authFromPage?.activeCompany?.is_agency) && managedAgencyClients.length > 0
    const hasAgencyQuickLink = Array.isArray(authFromPage?.companies)
        ? authFromPage.companies.some((company) => company?.is_agency === true)
        : false
    const mobileTopPaddingClass = hasAgencyQuickLink
        ? 'pt-[calc(9rem+env(safe-area-inset-top))] lg:pt-[calc(9rem+1.5rem+env(safe-area-inset-top))] xl:pt-[calc(9rem+2rem+env(safe-area-inset-top))]'
        : 'pt-[calc(5.75rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]'

    const isAgencyAccount = authFromPage?.activeCompany?.is_agency === true
    const dashLinksRaw = dashboard_links && typeof dashboard_links === 'object' ? dashboard_links : {}
    const dashLinks = {
        company: dashLinksRaw.company,
        company_label: dashLinksRaw.company_label,
    }
    const hasDashboardLinks = Boolean(dashLinks.company)

    const collageAssets = Array.isArray(hero?.collage_assets) ? hero.collage_assets : []

    const totalAssets =
        statsBlock?.total_assets?.value ?? hero?.headline?.total_assets ?? 0
    const storageMB = statsBlock?.storage_mb?.value ?? 0
    const collectionsCount = statsBlock?.collections_count ?? 0
    const downloadsCount = statsBlock?.download_links?.value ?? 0
    const executionsCount = statsBlock?.executions_count ?? hero?.headline?.executions_count ?? 0
    const aiReviews = pending_ai_suggestions?.total ?? 0

    const momentumItems = useMemo(
        () => summarizeMomentum(momentumDataState || {}),
        [momentumDataState]
    )
    const hasDeferredInsightContent =
        (brandSignalsState?.length > 0) ||
        (aiInsightsState?.length > 0) ||
        momentumItems.length > 0

    const metrics = []
    if (totalAssets > 0) metrics.push({ label: 'assets', value: totalAssets.toLocaleString() })
    if (executionsCount > 0) metrics.push({ label: 'executions', value: executionsCount.toLocaleString() })

    if (is_manager) {
        if (storageMB > 0) metrics.push({ label: 'storage', value: formatStorage(storageMB) })
        if (downloadsCount > 0) metrics.push({ label: 'downloads', value: downloadsCount.toLocaleString() })
        if (collectionsCount > 0) metrics.push({ label: 'collections', value: collectionsCount.toLocaleString() })
        if (aiReviews > 0) metrics.push({ label: 'AI reviews', value: aiReviews.toLocaleString() })
    }

    useEffect(() => {
        if (brandId === undefined || brandId === null) {
            setHero(null)
            setStats(null)
            setInsights({
                brand_signals: [],
                momentum_data: {},
                ai_insights: [],
                generated_at: null,
            })
            return undefined
        }

        let cancelled = false

        setHero(null)
        setStats(null)

        const cachedInsights = readInsightsSessionCache(brandId)
        if (cachedInsights) {
            setInsights({
                brand_signals: cachedInsights.brand_signals ?? [],
                momentum_data: cachedInsights.momentum_data ?? {},
                ai_insights: cachedInsights.ai_insights ?? [],
                generated_at: cachedInsights.generated_at ?? null,
            })
        } else {
            setInsights(null)
        }

        const opts = {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }

        let heroInflight = overviewHeroInflight.get(brandId)
        if (!heroInflight) {
            heroInflight = fetch(HERO_URL, opts)
                .then((r) => (r.ok ? r.json() : null))
                .finally(() => overviewHeroInflight.delete(brandId))
            overviewHeroInflight.set(brandId, heroInflight)
        }
        heroInflight.then((h) => {
            if (!cancelled && h) setHero(h)
        })

        let statsInflight = overviewStatsInflight.get(brandId)
        if (!statsInflight) {
            statsInflight = fetch(STATS_URL, opts)
                .then((r) => (r.ok ? r.json() : null))
                .finally(() => overviewStatsInflight.delete(brandId))
            overviewStatsInflight.set(brandId, statsInflight)
        }
        statsInflight.then((s) => {
            if (!cancelled && s) setStats(s)
        })

        let idleId
        const settleTimer = window.setTimeout(() => {
            const runInsights = () => {
                if (cancelled || cachedInsights) return
                fetch(INSIGHTS_URL, opts)
                    .then((r) => (r.ok ? r.json() : null))
                    .then((ins) => {
                        if (cancelled) return
                        if (ins) {
                            writeInsightsSessionCache(brandId, ins)
                            setInsights({
                                brand_signals: ins.brand_signals ?? [],
                                momentum_data: ins.momentum_data ?? {},
                                ai_insights: ins.ai_insights ?? [],
                                generated_at: ins.generated_at ?? null,
                            })
                        } else {
                            setInsights({
                                brand_signals: [],
                                momentum_data: {},
                                ai_insights: [],
                                generated_at: null,
                            })
                        }
                    })
                    .catch(() => {
                        if (!cancelled) {
                            setInsights({
                                brand_signals: [],
                                momentum_data: {},
                                ai_insights: [],
                                generated_at: null,
                            })
                        }
                    })
            }
            if (typeof window.requestIdleCallback === 'function') {
                idleId = window.requestIdleCallback(runInsights, { timeout: 2000 })
            } else {
                idleId = window.setTimeout(runInsights, 1)
            }
        }, 500)

        return () => {
            cancelled = true
            window.clearTimeout(settleTimer)
            if (idleId != null) {
                if (typeof window.cancelIdleCallback === 'function') {
                    window.cancelIdleCallback(idleId)
                } else {
                    window.clearTimeout(idleId)
                }
            }
        }
    }, [brandId])

    useEffect(() => {
        if (brandId == null) {
            setProstaffMe(null)
            setProstaffMeLoading(false)
            return undefined
        }

        let cancelled = false
        setProstaffMe(null)
        setProstaffMeLoading(true)

        const url = `${PROSTAFF_ME_URL}?brand_id=${encodeURIComponent(brandId)}`
        fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((res) => {
                if (cancelled) return null
                if (!res.ok) {
                    setProstaffMe(null)
                    return null
                }
                return res.json()
            })
            .then((json) => {
                if (cancelled || json == null || typeof json !== 'object') return
                if (json.error) {
                    setProstaffMe(null)
                    return
                }
                setProstaffMe(json)
            })
            .catch(() => {
                if (!cancelled) setProstaffMe(null)
            })
            .finally(() => {
                if (!cancelled) setProstaffMeLoading(false)
            })

        return () => {
            cancelled = true
        }
    }, [brandId])

    return (
        <div className="relative h-[100dvh] max-h-[100dvh] overflow-hidden overscroll-none bg-[#0B0B0D]">
            <AppHead title="Overview" />

            <div className="absolute top-0 left-0 right-0 z-50 overflow-visible">
                <AppNav
                    brand={authFromPage?.activeBrand || auth?.activeBrand}
                    tenant={tenantNavFromAuth(authFromPage)}
                    variant="transparent"
                />
            </div>

            <div className="relative flex h-full min-h-0 flex-col overflow-hidden">
                <div
                    className="absolute inset-0 will-change-transform"
                    style={{
                        background: overviewBackdropBackground,
                        transform: 'scale(1)',
                    }}
                />
                <div
                    className="absolute inset-0 pointer-events-none"
                    style={{
                        background: `radial-gradient(circle at 30% 40%, ${brandColor}14, transparent 60%)`,
                    }}
                />

                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-black/30" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />
                </div>

                {hero ? (
                    <AssetCollage assets={collageAssets} fastEntrance eagerImageCount={10} />
                ) : (
                    <OverviewCollageSkeleton />
                )}

                <div className="relative z-10 flex min-h-0 flex-1 flex-col">
                    <div className="relative mx-auto flex h-full min-h-0 w-full max-w-7xl flex-1 flex-col px-4 sm:px-6 lg:px-12">
                        <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden overscroll-y-contain [-webkit-overflow-scrolling:touch] max-lg:[scrollbar-width:none] max-lg:[-ms-overflow-style:none] max-lg:[&::-webkit-scrollbar]:hidden lg:overflow-visible lg:overscroll-auto">
                            <motion.div
                                className={`flex w-full min-w-0 max-w-full flex-col justify-start space-y-4 pb-28 ${mobileTopPaddingClass} sm:space-y-6 sm:pb-24 lg:mx-0 lg:max-w-[50%] lg:min-h-full lg:justify-center lg:space-y-6 lg:pb-16`}
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.4 }}
                            >
                                <div className="animate-fadeInUp space-y-3 sm:space-y-4">
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                                        <div className="min-w-0">
                                            {!stats ? (
                                                <SkeletonPlanBadge />
                                            ) : (
                                                plan?.show_badge &&
                                                plan?.name && (
                                                    <span className="inline-flex max-w-full flex-wrap items-center gap-x-1 rounded-full border border-white/10 bg-white/[0.06] px-3 py-1 text-xs font-medium text-white/60 backdrop-blur-sm">
                                                        <span>
                                                            {plan.name} Plan
                                                            {isAgencyAccount && (
                                                                <span className="text-white/45"> · Agency account</span>
                                                            )}
                                                        </span>
                                                    </span>
                                                )
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

                                    {(prostaffMeLoading || prostaffMe) && (
                                        <div className="mt-1">
                                            <CreatorProgressCard
                                                loading={prostaffMeLoading}
                                                data={prostaffMe}
                                                brandColor={brandColor}
                                            />
                                        </div>
                                    )}

                                    <h1 className="animate-fadeInUp-d2 break-words text-3xl font-semibold leading-tight tracking-tight text-white md:text-4xl">
                                        {theme.name || activeBrand?.name || 'Overview'}
                                    </h1>
                                </div>

                                {theme.tagline && (
                                    <p className="animate-fadeInUp-d2 text-white/50 mt-3 text-sm leading-relaxed">
                                        {theme.tagline}
                                    </p>
                                )}

                                {!stats && !hero ? (
                                    <SkeletonMetricPills />
                                ) : (
                                    metrics.length > 0 && (
                                        <div className="animate-fadeInUp-d3 flex flex-wrap items-center gap-x-5 gap-y-2 mt-6 text-xs">
                                            {metrics.map((m, i) => (
                                                <span key={m.label} className="flex items-center gap-1 text-white/40">
                                                    <span className="text-white/80 font-medium">{m.value}</span>
                                                    {m.label}
                                                    {i < metrics.length - 1 && (
                                                        <span className="ml-3 text-white/10 select-none" aria-hidden>
                                                            •
                                                        </span>
                                                    )}
                                                </span>
                                            ))}
                                        </div>
                                    )
                                )}

                                {insights === null && (
                                    <div aria-busy="true" aria-label="Loading brand insights">
                                        <InsightsLoading />
                                    </div>
                                )}

                                {insights !== null && !hasDeferredInsightContent && (
                                    <div
                                        className="flex gap-3 rounded-2xl border border-white/[0.08] bg-white/[0.035] px-4 py-3.5 backdrop-blur-sm"
                                        style={{ boxShadow: `0 0 24px ${brandColor}12` }}
                                    >
                                        <div
                                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                                            style={{ backgroundColor: `${brandColor}22` }}
                                        >
                                            <SparklesIcon className="h-4 w-4" style={{ color: overviewIconColor }} />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-xs font-semibold uppercase tracking-wide text-white/45">
                                                Brand insights
                                            </p>
                                            <p className="mt-1.5 text-[13px] leading-relaxed text-white/55">
                                                Tips, momentum, and attention items for this brand show up here as your
                                                team uses the library—uploads, metadata, downloads, and AI activity all
                                                feed this view. Nothing to highlight yet.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {insights !== null && brandSignalsState?.length > 0 && (
                                    <ActiveSignals
                                        signals={brandSignalsState}
                                        insights={aiInsightsState}
                                        brandColor={brandColor}
                                        iconAccentColor={overviewIconColor}
                                        permissions={permissions}
                                        insightsUpdatedAt={insightsUpdatedAt}
                                    />
                                )}

                                {insights !== null &&
                                    aiInsightsState?.length > 0 &&
                                    brandSignalsState?.length > 0 &&
                                    (() => {
                                    const signalTypes = new Set(
                                        brandSignalsState
                                            .map((s) => {
                                                const c = s?.context?.category
                                                if (c === 'ai_suggestions') return 'suggestions'
                                                return c
                                            })
                                            .filter(Boolean)
                                    )
                                    const orphans = aiInsightsState.filter(
                                        (ins) => !ins.type || !signalTypes.has(ins.type)
                                    )
                                    return orphans.length > 0 ? (
                                        <AIInsights
                                            insights={orphans}
                                            brandColor={brandColor}
                                            iconAccentColor={overviewIconColor}
                                        />
                                    ) : null
                                })()}
                                {insights !== null &&
                                    aiInsightsState?.length > 0 &&
                                    (!brandSignalsState || brandSignalsState.length === 0) && (
                                        <AIInsights
                                            insights={aiInsightsState}
                                            brandColor={brandColor}
                                            iconAccentColor={overviewIconColor}
                                        />
                                    )}

                                {insights !== null && (
                                    <RecentMomentum
                                        data={momentumDataState}
                                        brandColor={brandColor}
                                        iconAccentColor={overviewIconColor}
                                    />
                                )}

                                {showManagedCompanies && (
                                    <ManagedCompaniesTeaser
                                        count={managedAgencyClients.length}
                                        brandColor={brandColor}
                                    />
                                )}

                                <PrimaryActions
                                    permissions={permissions}
                                    brand={activeBrand}
                                    brandColor={brandColor}
                                    iconAccentColor={overviewIconColor}
                                />
                            </motion.div>

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
