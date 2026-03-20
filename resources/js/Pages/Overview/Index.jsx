import { usePage, Link } from '@inertiajs/react'
import { motion } from 'framer-motion'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
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
}) {
    const { auth: authFromPage } = usePage().props
    const activeBrand = brand ?? authFromPage?.activeBrand ?? auth?.activeBrand
    const brandColor = theme.colors?.primary || '#6366f1'

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
        <div className="h-screen overflow-hidden bg-[#0B0B0D] relative">
            <AppHead title="Overview" />

            {/* Nav — absolute so it overlays the cinematic content */}
            <div className="absolute top-0 left-0 right-0 z-50">
                <AppNav brand={authFromPage?.activeBrand || auth?.activeBrand} tenant={tenant} variant="transparent" />
            </div>

            <div className="relative h-full overflow-hidden">
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

                {/* Asset collage — right side, hidden on mobile */}
                <AssetCollage assets={collageAssets} />

                {/* Main content — left column on desktop */}
                <div className="relative z-10 h-full max-w-7xl mx-auto px-6 lg:px-12 flex flex-col justify-center">
                    {/* Hero + Actions — space-y-6 layout */}
                    <motion.div
                        className="lg:max-w-[50%] space-y-6"
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.4 }}
                    >
                        {/* Plan badge */}
                        {plan?.show_badge && plan?.name && (
                            <div className="animate-fadeInUp">
                                <span
                                    className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium mb-6 border border-white/10 text-white/60 bg-white/[0.06] backdrop-blur-sm"
                                >
                                    {plan.name} Plan
                                </span>
                            </div>
                        )}

                        {/* Brand name */}
                        <h1 className="animate-fadeInUp-d2 text-3xl md:text-4xl font-semibold tracking-tight text-white leading-tight">
                            {theme.name || activeBrand?.name || 'Overview'}
                        </h1>

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

                        {/* Active signals — What Needs Attention (permission-filtered, no empty block) */}
                        {brand_signals?.length > 0 && (
                            <ActiveSignals
                                signals={brand_signals}
                                insights={ai_insights}
                                brandColor={brandColor}
                                permissions={permissions}
                            />
                        )}

                        {/* AI Insights — only show orphans (insights that don't match any signal) */}
                        {ai_insights?.length > 0 && brand_signals?.length > 0 && (() => {
                            const signalTypes = new Set(
                                brand_signals
                                    .map((s) => {
                                        const c = s?.context?.category
                                        if (c === 'ai_suggestions') return 'suggestions'
                                        return c
                                    })
                                    .filter(Boolean)
                            )
                            const orphans = ai_insights.filter((ins) => !ins.type || !signalTypes.has(ins.type))
                            return orphans.length > 0 ? <AIInsights insights={orphans} /> : null
                        })()}
                        {ai_insights?.length > 0 && (!brand_signals || brand_signals.length === 0) && (
                            <AIInsights insights={ai_insights} />
                        )}

                        {/* Recent Momentum — aggregated, meaningful */}
                        <RecentMomentum data={momentum_data} />

                        {/* Primary action tiles — Brand Portal, Team, Analytics always */}
                        <PrimaryActions
                            permissions={permissions}
                            brand={activeBrand}
                            brandColor={brandColor}
                        />
                    </motion.div>

                    {/* Powered by footer (default theme only) */}
                    {theme.mode === 'default' && (
                        <div className="absolute bottom-6 left-6 lg:left-12 text-xs text-white/20">
                            Powered by Jackpot
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
