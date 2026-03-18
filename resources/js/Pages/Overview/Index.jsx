import { usePage, Link } from '@inertiajs/react'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import PrimaryActions from '../../Components/dashboard/PrimaryActions'
import AssetCollage from '../../Components/dashboard/AssetCollage'
import { ChartBarSquareIcon } from '@heroicons/react/24/outline'

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
}) {
    const { auth: authFromPage } = usePage().props
    const activeBrand = brand ?? authFromPage?.activeBrand ?? auth?.activeBrand

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

                {/* Depth overlays */}
                <div className="absolute inset-0 pointer-events-none">
                    <div className="absolute inset-0 bg-black/30" />
                    <div className="absolute inset-0 bg-gradient-to-b from-black/20 via-transparent to-black/50" />
                </div>

                {/* Asset collage — right side, hidden on mobile */}
                <AssetCollage assets={collageAssets} />

                {/* Main content — left column on desktop */}
                <div className="relative z-10 h-full max-w-7xl mx-auto px-6 lg:px-12 flex flex-col justify-center">
                    {/* Hero + Actions */}
                    <div className="lg:max-w-[50%]">
                        {/* Plan badge */}
                        {plan?.show_badge && plan?.name && (
                            <div className="animate-fadeInUp">
                                <span
                                    className="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium mb-6"
                                    style={{
                                        backgroundColor: `${theme.colors?.primary || '#6366f1'}22`,
                                        color: theme.colors?.primary || '#6366f1',
                                    }}
                                >
                                    {plan.name} Plan
                                </span>
                            </div>
                        )}

                        {/* Brand logo or initial */}
                        <div className="animate-fadeInUp-d1 mb-5">
                            {theme.logo ? (
                                <img
                                    src={theme.logo}
                                    alt={theme.name || 'Brand'}
                                    className="h-16 md:h-20 w-auto max-w-[240px] object-contain"
                                    style={{ filter: 'brightness(0) invert(1)' }}
                                />
                            ) : (
                                <div
                                    className="h-14 w-14 rounded-xl flex items-center justify-center"
                                    style={{
                                        background: `linear-gradient(135deg, ${theme.colors?.primary || '#6366f1'}, ${theme.colors?.secondary || '#8b5cf6'})`,
                                    }}
                                >
                                    <span className="text-xl font-bold text-white">
                                        {(theme.name || 'J').charAt(0).toUpperCase()}
                                    </span>
                                </div>
                            )}
                        </div>

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

                        {/* Inline metrics */}
                        {metrics.length > 0 && (
                            <div className="animate-fadeInUp-d3 flex flex-wrap items-center gap-x-5 gap-y-2 mt-6 text-xs">
                                {metrics.map((m, i) => (
                                    <span key={m.label} className="flex items-center gap-1 text-white/40">
                                        <span className="text-white/80 font-medium">{m.value}</span>
                                        {m.label}
                                        {i < metrics.length - 1 && (
                                            <span className="ml-3 text-white/10 select-none" aria-hidden>|</span>
                                        )}
                                    </span>
                                ))}
                            </div>
                        )}

                        {/* Dashboard link */}
                        {is_manager && (
                            <div className="animate-fadeInUp-d4 mt-5">
                                <Link
                                    href="/app/dashboard"
                                    className="inline-flex items-center gap-1.5 text-xs font-medium text-white/40 hover:text-white/70 transition-colors"
                                >
                                    <ChartBarSquareIcon className="w-3.5 h-3.5" />
                                    View full brand dashboard
                                </Link>
                            </div>
                        )}

                        {/* Primary action tiles */}
                        <div className="mt-6">
                            <PrimaryActions
                                permissions={permissions}
                                brand={activeBrand}
                            />
                        </div>
                    </div>

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
