import { useMemo } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { motion } from 'framer-motion'
import {
    ArrowTopRightOnSquareIcon,
    ChartBarIcon,
    ClockIcon,
    NoSymbolIcon,
    RectangleStackIcon,
    SparklesIcon,
} from '@heroicons/react/24/outline'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'

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

function periodLabel(periodType) {
    const p = String(periodType || '').toLowerCase()
    if (p === 'month') return 'month'
    if (p === 'quarter') return 'quarter'
    if (p === 'year') return 'year'
    return p || 'period'
}

function statusBadge(status, brandColor) {
    const s = String(status || '').toLowerCase()
    const map = {
        behind: 'border-rose-400/40 bg-rose-500/15 text-rose-100',
        on_track: 'border-amber-400/35 bg-amber-500/12 text-amber-100',
        complete: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    }
    const cls = map[s] || 'border-white/15 bg-white/10 text-white/70'
    const labels = {
        behind: 'Behind pace',
        on_track: 'On track',
        complete: 'Target complete',
    }
    return (
        <span
            className={`inline-flex rounded-full border px-3 py-1 text-xs font-medium ${cls}`}
            style={{ boxShadow: `0 0 20px ${brandColor}18` }}
        >
            {labels[s] || '—'}
        </span>
    )
}

/**
 * Cinematic creator self-progress (from overview). Detailed uploads grid lives on Creator profile.
 */
export default function CreatorProgress({
    brand,
    creator,
    performance,
    rejections = [],
    awaiting_brand_review_count = 0,
    pipeline = {},
    peer_comparison = {},
    canManageCreators = false,
}) {
    const page = usePage()
    const { auth } = page.props
    const activeBrand = auth?.activeBrand
    const tenant = tenantNavFromAuth(auth)

    const brandColor = brand?.primary_color || activeBrand?.primary_color || '#6366f1'
    const secondaryForBackdrop =
        brand?.secondary_color || activeBrand?.secondary_color || activeBrand?.accent_color || brandColor
    const backdropBackground = overviewDefaultBackdrop(brandColor, secondaryForBackdrop)

    const pct = Number(performance?.completion_percentage)
    const pctClamped = Number.isFinite(pct) ? Math.min(100, Math.max(0, pct)) : 0

    const hasAgencyQuickLink = Array.isArray(auth?.companies)
        ? auth.companies.some((company) => company?.is_agency === true)
        : false
    const mobileTopPaddingClass = hasAgencyQuickLink
        ? 'pt-[calc(9rem+env(safe-area-inset-top))] lg:pt-[calc(9rem+1.5rem+env(safe-area-inset-top))] xl:pt-[calc(9rem+2rem+env(safe-area-inset-top))]'
        : 'pt-[calc(5.75rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]'

    const damAssetsHref = `/app/assets?prostaff_user_id=${encodeURIComponent(creator.id)}`

    const profileHref = useMemo(() => {
        if (typeof route !== 'function') {
            return `/app/brands/${brand.id}/creators/${creator.id}`
        }
        return route('brands.creators.show', { brand: brand.id, user: creator.id })
    }, [brand.id, creator.id])

    const peerCopy = useMemo(() => {
        const p = peer_comparison || {}
        if (p.solo || (p.cohort_size ?? 0) <= 1) {
            return "You're the only active creator on this brand this period. When more creators contribute, you'll see an anonymized comparison of upload volume—no names, just benchmarks."
        }
        const top = p.top_percent
        const n = p.cohort_size
        const pl = periodLabel(p.period_type || performance?.period_type)
        if (top != null && n != null) {
            return `Based on anonymized upload volume this ${pl}, you're in the top ${top}% of ${n} active creators on this brand.`
        }
        return 'Upload volume is compared across active creators on this brand—anonymized and aggregated for this period.'
    }, [peer_comparison, performance?.period_type])

    const awaiting = Number(pipeline?.awaiting_brand_review ?? awaiting_brand_review_count ?? 0)
    const rejectedN = Number(pipeline?.rejected ?? rejections?.length ?? 0)
    const publishedN = Number(pipeline?.approved_published ?? 0)

    return (
        <div className="relative min-h-[100dvh] overflow-x-hidden bg-[#0B0B0D] pb-28 sm:pb-16">
            <AppHead title={`Creator progress — ${brand?.name || 'Brand'}`} />

            <div className="absolute left-0 right-0 top-0 z-50 overflow-visible">
                <AppNav brand={activeBrand} tenant={tenant} variant="transparent" />
            </div>

            <div className="pointer-events-none fixed inset-0" style={{ background: backdropBackground }} />
            <div
                className="pointer-events-none fixed inset-0"
                style={{
                    background: `radial-gradient(circle at 30% 40%, ${brandColor}14, transparent 60%)`,
                }}
            />
            <div className="pointer-events-none fixed inset-0 bg-black/35" />
            <div className="pointer-events-none fixed inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/55" />

            <main
                className={`relative z-10 mx-auto w-full max-w-5xl px-4 sm:px-6 lg:px-10 ${mobileTopPaddingClass} pb-12`}
            >
                <nav className="mb-6 text-sm">
                    <Link href={typeof route === 'function' ? route('overview') : '/app/overview'} className="text-white/45 transition hover:text-white/80">
                        ← Overview
                    </Link>
                    <span className="mx-2 text-white/25">/</span>
                    <span className="text-white/70">Creator progress</span>
                </nav>

                <motion.header
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45 }}
                    className="rounded-2xl border border-white/10 bg-white/[0.05] p-6 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl sm:p-8"
                    style={{ boxShadow: `0 0 40px ${brandColor}12` }}
                >
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-semibold uppercase tracking-wider text-white/40">Your dashboard</p>
                            <h1 className="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                                Creator progress
                            </h1>
                            <p className="mt-2 text-sm text-white/50">{brand?.name}</p>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                {statusBadge(performance?.status, brandColor)}
                                {performance?.rank != null ? (
                                    <span className="text-sm text-white/45">
                                        Rank <span className="font-semibold text-white/80">#{performance.rank}</span> on
                                        this brand
                                    </span>
                                ) : null}
                            </div>
                        </div>
                        <div className="flex shrink-0 flex-col gap-2 sm:flex-row">
                            <Link
                                href={damAssetsHref}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/20 bg-white/[0.08] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:border-white/30 hover:bg-white/[0.12]"
                            >
                                <RectangleStackIcon className="h-4 w-4 text-white/70" aria-hidden />
                                View my assets
                            </Link>
                            <Link
                                href={profileHref}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-white/15 bg-white/[0.04] px-4 py-2.5 text-sm font-semibold text-white/90 transition hover:border-white/25 hover:bg-white/[0.08]"
                            >
                                Full profile & uploads
                                <ArrowTopRightOnSquareIcon className="h-4 w-4 text-white/50" aria-hidden />
                            </Link>
                        </div>
                    </div>

                    <div className="mt-8 rounded-xl border border-white/[0.08] bg-black/25 p-5">
                        <div className="flex items-start gap-3">
                            <div
                                className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                                style={{ backgroundColor: `${brandColor}28` }}
                            >
                                <SparklesIcon className="h-5 w-5 text-white/90" aria-hidden />
                            </div>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-wide text-white/45">
                                    How you compare
                                </p>
                                <p className="mt-2 text-sm leading-relaxed text-white/70">{peerCopy}</p>
                            </div>
                        </div>
                    </div>
                </motion.header>

                <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {[
                        {
                            key: 'awaiting',
                            label: 'Awaiting brand review',
                            value: awaiting,
                            icon: ClockIcon,
                            hint: 'With approvers now',
                        },
                        {
                            key: 'rejected',
                            label: 'Rejected',
                            value: rejectedN,
                            icon: NoSymbolIcon,
                            hint: 'Reupload to resubmit',
                        },
                        {
                            key: 'live',
                            label: 'Published',
                            value: publishedN,
                            icon: ChartBarIcon,
                            hint: 'Live in the library',
                        },
                    ].map((tile, i) => (
                        <motion.div
                            key={tile.key}
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.4, delay: 0.08 * i }}
                            className="rounded-2xl border border-white/10 bg-white/[0.04] p-5 backdrop-blur-xl"
                        >
                            <div className="flex items-center gap-3">
                                <div
                                    className="flex h-9 w-9 items-center justify-center rounded-lg"
                                    style={{ backgroundColor: `${brandColor}22` }}
                                >
                                    <tile.icon className="h-[18px] w-[18px] text-white/85" aria-hidden />
                                </div>
                                <div>
                                    <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">
                                        {tile.label}
                                    </p>
                                    <p className="text-2xl font-semibold tabular-nums text-white">{tile.value}</p>
                                </div>
                            </div>
                            <p className="mt-3 text-xs text-white/40">{tile.hint}</p>
                        </motion.div>
                    ))}
                </div>

                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.12 }}
                    className="mt-8 rounded-2xl border border-white/10 bg-white/[0.04] p-6 backdrop-blur-xl"
                >
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-white/45">Period quota</h2>
                    <p className="mt-1 text-xs text-white/40">
                        {periodLabel(performance?.period_type)} · {performance?.period_start} → {performance?.period_end}
                    </p>
                    <p className="mt-4 text-lg font-medium tabular-nums text-white">
                        {performance?.actual_uploads ?? 0}
                        <span className="text-white/40"> of </span>
                        {performance?.target_uploads != null ? performance.target_uploads : '—'}
                        <span className="text-sm font-normal text-white/50"> uploads</span>
                    </p>
                    <div className="mt-4 h-3 w-full overflow-hidden rounded-full bg-black/50 ring-1 ring-inset ring-white/10">
                        <motion.div
                            className="h-full rounded-full"
                            style={{
                                background: `linear-gradient(90deg, ${brandColor}aa, ${brandColor})`,
                                boxShadow: `0 0 16px ${brandColor}55`,
                            }}
                            initial={{ width: 0 }}
                            animate={{ width: `${pctClamped}%` }}
                            transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
                        />
                    </div>
                    <p className="mt-2 text-right text-xs tabular-nums text-white/45">
                        {Number.isFinite(pct) ? `${pct.toFixed(1)}%` : '—'} complete
                    </p>
                </motion.section>

                {rejections.length > 0 ? (
                    <motion.section
                        initial={{ opacity: 0, y: 10 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.45, delay: 0.15 }}
                        className="mt-8 rounded-2xl border border-rose-400/20 bg-rose-500/[0.07] p-6 backdrop-blur-xl"
                    >
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-rose-100/80">
                            Needs reupload ({rejections.length})
                        </h2>
                        <ul className="mt-4 space-y-3">
                            {rejections.slice(0, 5).map((r) => (
                                <li key={r.id} className="rounded-lg border border-white/10 bg-black/20 px-3 py-2 text-sm">
                                    <p className="font-medium text-white/90">{r.title || 'Asset'}</p>
                                    {r.rejection_reason ? (
                                        <p className="mt-1 text-xs text-white/50">{r.rejection_reason}</p>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                        {rejections.length > 5 ? (
                            <Link href={profileHref} className="mt-4 inline-block text-sm font-semibold text-rose-100/90 underline">
                                View all on profile →
                            </Link>
                        ) : null}
                    </motion.section>
                ) : null}

                {canManageCreators ? (
                    <p className="mt-10 text-center text-xs text-white/30">
                        Managing creators?{' '}
                        <Link
                            href={typeof route === 'function' ? route('brands.creators', { brand: brand.id }) : `/app/brands/${brand.id}/creators`}
                            className="text-white/50 underline hover:text-white/70"
                        >
                            Open team dashboard
                        </Link>
                    </p>
                ) : null}
            </main>
        </div>
    )
}
