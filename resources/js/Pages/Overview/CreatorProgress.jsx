import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, usePage } from '@inertiajs/react'
import { motion } from 'framer-motion'
import {
    ArrowTopRightOnSquareIcon,
    ChartBarIcon,
    CheckCircleIcon,
    ClockIcon,
    NoSymbolIcon,
    PhotoIcon,
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
    if (p === 'month') return 'Month'
    if (p === 'quarter') return 'Quarter'
    if (p === 'year') return 'Year'
    return p ? p.charAt(0).toUpperCase() + p.slice(1) : 'Period'
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

function formatShortDate(iso) {
    if (!iso) return '—'
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return '—'
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
    } catch {
        return '—'
    }
}

function truncate(str, max) {
    if (!str) return ''
    if (str.length <= max) return str
    return `${str.slice(0, Math.max(0, max - 1))}…`
}

function dismissedRejectionsKey(brandId, userId) {
    return `creator_home_dismissed_rejections:${brandId}:${userId}`
}

function SectionCard({ children, className = '', style }) {
    return (
        <div
            className={`rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl sm:p-7 ${className}`}
            style={style}
        >
            {children}
        </div>
    )
}

function SectionTitle({ icon: Icon, children, brandColor, className = 'mb-4' }) {
    return (
        <div className={`flex items-center gap-3 ${className}`}>
            {Icon ? (
                <div
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                    style={{ backgroundColor: `${brandColor}28` }}
                >
                    <Icon className="h-[18px] w-[18px] text-white/85" aria-hidden />
                </div>
            ) : null}
            <h2 className="text-sm font-semibold uppercase tracking-wide text-white/50">{children}</h2>
        </div>
    )
}

/**
 * Creator Home — full prostaff self-service dashboard (data from creator_home / GET /api/prostaff/me).
 */
export default function CreatorProgress({ brand, creator, creator_home = {}, canManageCreators = false }) {
    const page = usePage()
    const { auth } = page.props
    const activeBrand = auth?.activeBrand
    const tenant = tenantNavFromAuth(auth)

    const brandColor = brand?.primary_color || activeBrand?.primary_color || '#6366f1'
    const secondaryForBackdrop =
        brand?.secondary_color || activeBrand?.secondary_color || activeBrand?.accent_color || brandColor
    const backdropBackground = overviewDefaultBackdrop(brandColor, secondaryForBackdrop)

    const h = creator_home || {}
    const pct = Number(h.completion_percentage)
    const pctClamped = Number.isFinite(pct) ? Math.min(100, Math.max(0, pct)) : 0

    const hasAgencyQuickLink = Array.isArray(auth?.companies)
        ? auth.companies.some((company) => company?.is_agency === true)
        : false
    const mobileTopPaddingClass = hasAgencyQuickLink
        ? 'pt-[calc(9rem+env(safe-area-inset-top))] lg:pt-[calc(9rem+1.5rem+env(safe-area-inset-top))] xl:pt-[calc(9rem+2rem+env(safe-area-inset-top))]'
        : 'pt-[calc(5.75rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]'

    const assetsFilteredHref = `/app/assets?prostaff_user_id=${encodeURIComponent(creator.id)}`

    const [dismissedRejectionIds, setDismissedRejectionIds] = useState([])

    useEffect(() => {
        if (typeof window === 'undefined' || !brand?.id || !creator?.id) return
        try {
            const raw = localStorage.getItem(dismissedRejectionsKey(brand.id, creator.id))
            const parsed = raw ? JSON.parse(raw) : []
            setDismissedRejectionIds(Array.isArray(parsed) ? parsed.map(String) : [])
        } catch {
            setDismissedRejectionIds([])
        }
    }, [brand?.id, creator?.id])

    const persistDismissed = useCallback(
        (ids) => {
            if (typeof window === 'undefined' || !brand?.id || !creator?.id) return
            try {
                localStorage.setItem(dismissedRejectionsKey(brand.id, creator.id), JSON.stringify(ids))
            } catch {
                /* quota */
            }
        },
        [brand?.id, creator?.id]
    )

    const handleDismissRejection = useCallback(
        (assetId) => {
            const id = String(assetId)
            setDismissedRejectionIds((prev) => {
                if (prev.includes(id)) return prev
                const next = [...prev, id]
                persistDismissed(next)
                return next
            })
        },
        [persistDismissed]
    )

    const visibleRejected = useMemo(() => {
        const list = Array.isArray(h.rejected_assets) ? h.rejected_assets : []
        const dismissed = new Set(dismissedRejectionIds)
        return list.filter((r) => r?.id && !dismissed.has(String(r.id)))
    }, [h.rejected_assets, dismissedRejectionIds])

    const pendingAssets = Array.isArray(h.pending_assets) ? h.pending_assets : []
    const approvedAssets = Array.isArray(h.approved_assets) ? h.approved_assets : []

    const gamificationCopy = useMemo(() => {
        const total = Number(h.total_creators) || 0
        const pctile = h.creator_rank_percentile
        const pos = h.creator_rank_position
        if (total <= 1) {
            return "You're the only active creator on this brand this period. When more creators contribute, you'll see anonymized benchmarks here—no names, just rankings."
        }
        if (pctile != null && Number.isFinite(Number(pctile))) {
            return `You're in the top ${Number(pctile)}% of creators on this brand (anonymized upload volume, this ${String(h.period_type || 'period').toLowerCase()}).`
        }
        if (pos != null && total > 0) {
            return `Rank #${Number(pos)} of ${total} active creators (anonymized).`
        }
        return 'Upload volume is compared across active creators on this brand—aggregated and anonymized for this period.'
    }, [h.total_creators, h.creator_rank_percentile, h.creator_rank_position, h.period_type])

    const uploadsRemaining = h.uploads_remaining
    const targetUploads = h.target_uploads

    const progressMessage = useMemo(() => {
        if (targetUploads == null || !Number.isFinite(Number(targetUploads)) || Number(targetUploads) <= 0) {
            return null
        }
        const rem = uploadsRemaining != null ? Number(uploadsRemaining) : null
        if (rem != null && rem > 0) {
            return `${rem} upload${rem === 1 ? '' : 's'} to reach your target`
        }
        if (rem === 0) {
            return "You've reached your target for this period"
        }
        return null
    }, [targetUploads, uploadsRemaining])

    return (
        <div className="relative min-h-[100dvh] overflow-x-hidden bg-[#0B0B0D] pb-28 sm:pb-16">
            <AppHead title={`Creator Home — ${brand?.name || 'Brand'}`} />

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
                    <Link
                        href={typeof route === 'function' ? route('overview') : '/app/overview'}
                        className="text-white/45 transition hover:text-white/80"
                    >
                        ← Overview
                    </Link>
                    <span className="mx-2 text-white/25">/</span>
                    <span className="text-white/70">Creator Home</span>
                </nav>

                <motion.header
                    initial={{ opacity: 0, y: 12 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45 }}
                    className="rounded-2xl border border-white/10 bg-white/[0.05] p-6 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl sm:p-8"
                    style={{ boxShadow: `0 0 40px ${brandColor}12` }}
                >
                    <p className="text-xs font-semibold uppercase tracking-wider text-white/40">{brand?.name}</p>
                    <h1 className="mt-2 text-3xl font-semibold tracking-tight text-white sm:text-4xl">Creator Home</h1>
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        {statusBadge(h.status, brandColor)}
                    </div>
                </motion.header>

                <div className="my-8 h-px w-full bg-gradient-to-r from-transparent via-white/12 to-transparent" aria-hidden />

                {/* Progress */}
                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.05 }}
                >
                    <SectionCard style={{ boxShadow: `0 0 32px ${brandColor}10` }}>
                        <SectionTitle icon={SparklesIcon} brandColor={brandColor}>
                            Progress
                        </SectionTitle>
                        <p className="text-xs text-white/40">
                            Upload quota · {periodLabel(h.period_type)} · {h.period_start} → {h.period_end}
                        </p>
                        <p className="mt-4 text-lg font-medium tabular-nums text-white">
                            {h.actual_uploads ?? 0}
                            <span className="text-white/40"> of </span>
                            {h.target_uploads != null ? h.target_uploads : '—'}
                            <span className="text-sm font-normal text-white/50"> uploads</span>
                        </p>
                        {uploadsRemaining != null && Number(targetUploads) > 0 ? (
                            <p className="mt-2 text-sm text-white/55">
                                <span className="font-semibold tabular-nums text-white/80">{uploadsRemaining}</span>{' '}
                                upload{Number(uploadsRemaining) === 1 ? '' : 's'} remaining
                            </p>
                        ) : null}
                        {progressMessage ? (
                            <p className="mt-1 text-sm text-white/50">{progressMessage}</p>
                        ) : null}
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
                    </SectionCard>
                </motion.section>

                <div className="my-8 h-px w-full bg-gradient-to-r from-transparent via-white/12 to-transparent" aria-hidden />

                {/* Pending review */}
                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.08 }}
                >
                    <SectionCard>
                        <SectionTitle icon={ClockIcon} brandColor={brandColor}>
                            Pending review
                        </SectionTitle>
                        {pendingAssets.length === 0 ? (
                            <p className="text-sm text-white/45">No assets waiting for review</p>
                        ) : (
                            <ul className="space-y-3">
                                {pendingAssets.map((a) => (
                                    <li
                                        key={a.id}
                                        className="flex items-center gap-3 rounded-xl border border-white/[0.07] bg-black/20 px-3 py-2.5"
                                    >
                                        <div className="relative h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-white/5 ring-1 ring-white/10">
                                            {a.thumbnail_url ? (
                                                <img
                                                    src={a.thumbnail_url}
                                                    alt=""
                                                    className="h-full w-full object-cover"
                                                />
                                            ) : (
                                                <div className="flex h-full w-full items-center justify-center text-white/25">
                                                    <PhotoIcon className="h-6 w-6" />
                                                </div>
                                            )}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium text-white/90">{a.title}</p>
                                            <p className="text-xs text-white/40">Uploaded {formatShortDate(a.uploaded_at)}</p>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </motion.section>

                <div className="my-6 h-px w-full bg-gradient-to-r from-transparent via-white/10 to-transparent" aria-hidden />

                {/* Needs revision */}
                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.1 }}
                >
                    <SectionCard className="border-rose-400/15 bg-rose-500/[0.04]">
                        <SectionTitle icon={NoSymbolIcon} brandColor={brandColor}>
                            Needs revision
                        </SectionTitle>
                        {visibleRejected.length === 0 ? (
                            <p className="text-sm text-white/45">No revisions needed</p>
                        ) : (
                            <ul className="space-y-4">
                                {visibleRejected.map((r) => (
                                    <li
                                        key={r.id}
                                        className="rounded-xl border border-white/10 bg-black/25 px-4 py-3"
                                    >
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                                            <div className="flex min-w-0 flex-1 items-center gap-3">
                                                <div className="relative h-12 w-12 shrink-0 overflow-hidden rounded-lg bg-white/5 ring-1 ring-white/10">
                                                    {r.thumbnail_url ? (
                                                        <img
                                                            src={r.thumbnail_url}
                                                            alt=""
                                                            className="h-full w-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="flex h-full w-full items-center justify-center text-white/25">
                                                            <PhotoIcon className="h-6 w-6" />
                                                        </div>
                                                    )}
                                                </div>
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-white/90">{r.title}</p>
                                                    {r.rejection_reason ? (
                                                        <p className="mt-1 text-xs leading-relaxed text-white/45">
                                                            {truncate(r.rejection_reason, 140)}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            </div>
                                            <div className="flex shrink-0 flex-wrap gap-2 sm:flex-col sm:items-stretch">
                                                <Link
                                                    href={`/app/assets?prostaff_user_id=${encodeURIComponent(creator.id)}&asset=${encodeURIComponent(r.id)}`}
                                                    className="inline-flex items-center justify-center rounded-lg border border-white/20 bg-white/[0.08] px-3 py-2 text-center text-xs font-semibold text-white transition hover:border-white/30 hover:bg-white/[0.12]"
                                                >
                                                    Fix & reupload
                                                </Link>
                                                <button
                                                    type="button"
                                                    onClick={() => handleDismissRejection(r.id)}
                                                    className="inline-flex items-center justify-center rounded-lg border border-white/10 bg-transparent px-3 py-2 text-center text-xs font-medium text-white/55 transition hover:border-white/20 hover:text-white/75"
                                                >
                                                    Dismiss
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>
                </motion.section>

                <div className="my-6 h-px w-full bg-gradient-to-r from-transparent via-white/10 to-transparent" aria-hidden />

                {/* Approved */}
                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.12 }}
                >
                    <SectionCard>
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                            <SectionTitle icon={CheckCircleIcon} brandColor={brandColor} className="mb-0">
                                Approved
                            </SectionTitle>
                            <Link
                                href={assetsFilteredHref}
                                className="inline-flex items-center gap-1 text-xs font-semibold text-white/55 transition hover:text-white/85"
                            >
                                View all in library
                                <ArrowTopRightOnSquareIcon className="h-3.5 w-3.5" aria-hidden />
                            </Link>
                        </div>
                        {approvedAssets.length === 0 ? (
                            <p className="text-sm text-white/45">No approved assets yet</p>
                        ) : (
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                {approvedAssets.map((a) => (
                                    <Link
                                        key={a.id}
                                        href={`${assetsFilteredHref}&asset=${encodeURIComponent(a.id)}`}
                                        className="group block overflow-hidden rounded-xl border border-white/10 bg-black/20 ring-white/20 transition hover:border-white/20 hover:ring-2"
                                    >
                                        <div className="aspect-square w-full bg-white/5">
                                            {a.thumbnail_url ? (
                                                <img
                                                    src={a.thumbnail_url}
                                                    alt=""
                                                    className="h-full w-full object-cover transition group-hover:opacity-90"
                                                />
                                            ) : (
                                                <div className="flex h-full w-full items-center justify-center text-white/25">
                                                    <PhotoIcon className="h-8 w-8" />
                                                </div>
                                            )}
                                        </div>
                                        <p className="truncate px-2 py-2 text-[11px] font-medium text-white/70">{a.title}</p>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </SectionCard>
                </motion.section>

                <div className="my-6 h-px w-full bg-gradient-to-r from-transparent via-white/10 to-transparent" aria-hidden />

                {/* Gamification */}
                <motion.section
                    initial={{ opacity: 0, y: 10 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.45, delay: 0.14 }}
                >
                    <SectionCard>
                        <SectionTitle icon={ChartBarIcon} brandColor={brandColor}>
                            How you compare
                        </SectionTitle>
                        <p className="text-sm leading-relaxed text-white/65">{gamificationCopy}</p>
                    </SectionCard>
                </motion.section>

                {canManageCreators ? (
                    <p className="mt-10 text-center text-xs text-white/30">
                        Managing creators?{' '}
                        <Link
                            href={
                                typeof route === 'function'
                                    ? route('brands.creators', { brand: brand.id })
                                    : `/app/brands/${brand.id}/creators`
                            }
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
