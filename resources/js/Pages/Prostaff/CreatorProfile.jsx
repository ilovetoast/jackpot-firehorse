import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import { motion, AnimatePresence } from 'framer-motion'
import {
    ChevronDownIcon,
    ChevronUpIcon,
    ClockIcon,
    NoSymbolIcon,
    RectangleStackIcon,
} from '@heroicons/react/24/outline'
import AppHead from '../../Components/AppHead'
import AppNav from '../../Components/AppNav'
import AppFooter from '../../Components/AppFooter'
import ConfirmDialog from '../../Components/ConfirmDialog'

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

function statusBadge(status) {
    const s = String(status || '').toLowerCase()
    const map = {
        behind: 'border-rose-400/40 bg-rose-500/15 text-rose-100',
        on_track: 'border-amber-400/35 bg-amber-500/12 text-amber-100',
        complete: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    }
    const cls = map[s] || 'border-white/15 bg-white/10 text-white/70'
    const labels = {
        behind: '🔴 Behind Pace',
        on_track: '🟡 On Track',
        complete: '🟢 Complete',
    }
    return (
        <span className={`inline-flex rounded-full border px-3 py-1 text-xs font-medium ${cls}`}>
            {labels[s] || '—'}
        </span>
    )
}

function periodLabel(periodType) {
    const p = String(periodType || '').toLowerCase()
    if (p === 'month') return 'Month'
    if (p === 'quarter') return 'Quarter'
    if (p === 'year') return 'Year'
    return p ? p.charAt(0).toUpperCase() + p.slice(1) : 'Period'
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

function formatDate(iso) {
    if (!iso) return '—'
    try {
        return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    } catch {
        return '—'
    }
}

/** @param {string|null|undefined} iso */
function formatRelativePast(iso) {
    if (!iso) return null
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return null
        const now = Date.now()
        let diffMs = now - d.getTime()
        if (diffMs < 0) diffMs = 0
        const minutes = Math.floor(diffMs / 60000)
        if (minutes < 1) return 'just now'
        if (minutes < 60) return `${minutes} min ago`
        const hours = Math.floor(minutes / 60)
        if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`
        const days = Math.floor(hours / 24)
        if (days < 14) return `${days} day${days === 1 ? '' : 's'} ago`
        const weeks = Math.floor(days / 7)
        if (weeks < 8) return `${weeks} week${weeks === 1 ? '' : 's'} ago`
        return d.toLocaleDateString(undefined, { dateStyle: 'medium' })
    } catch {
        return null
    }
}

function uploadsPeriodPhrase(periodType) {
    const p = String(periodType || '').toLowerCase()
    if (p === 'month') return 'this month'
    if (p === 'quarter') return 'this quarter'
    if (p === 'year') return 'this year'
    return 'this period'
}

/**
 * @param {{
 *   brand: { id: number, name: string, slug?: string, primary_color?: string|null, secondary_color?: string|null, accent_color?: string|null },
 *   creator: { id: number, name: string, email: string },
 *   performance: Record<string, unknown>,
 *   rejections: Array<{ id: string, title: string, rejection_reason: string|null, rejected_at: string|null }>,
 *   awaiting_brand_review_count?: number,
 *   canManageCreators?: boolean,
 *   membership: { id: number, target_uploads: number|null, period_type: string },
 * }} props
 */
export default function CreatorProfile({
    brand,
    creator,
    performance,
    rejections = [],
    awaiting_brand_review_count = 0,
    canManageCreators = false,
    membership,
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

    const [assets, setAssets] = useState([])
    const [assetsLoading, setAssetsLoading] = useState(true)
    const [nextPageUrl, setNextPageUrl] = useState(null)
    const [assetsError, setAssetsError] = useState(null)

    const [targetModalOpen, setTargetModalOpen] = useState(false)
    const [targetInput, setTargetInput] = useState(String(membership?.target_uploads ?? ''))
    const [periodInput, setPeriodInput] = useState(String(membership?.period_type || 'month'))
    const [savingTarget, setSavingTarget] = useState(false)
    const [targetError, setTargetError] = useState(null)

    const [removeOpen, setRemoveOpen] = useState(false)
    const [removing, setRemoving] = useState(false)
    const [removeError, setRemoveError] = useState(null)

    const [rejectionsOpen, setRejectionsOpen] = useState(true)

    const quickInsight = useMemo(() => {
        const pctStr = Number.isFinite(pct) ? `${pct.toFixed(1)}%` : '—'
        const actual = performance?.actual_uploads
        const n = Number(actual)
        const uploadsPart = Number.isFinite(n)
            ? `${n} upload${n === 1 ? '' : 's'} ${uploadsPeriodPhrase(performance?.period_type)}`
            : `— uploads ${uploadsPeriodPhrase(performance?.period_type)}`
        const rel = formatRelativePast(performance?.last_upload_at)
        const lastPart = rel ? `Last upload ${rel}` : 'No uploads yet'
        return `${pctStr} complete · ${uploadsPart} · ${lastPart}`
    }, [pct, performance])

    const behindPace = Number.isFinite(pct) && pct < 50

    const damAssetsHref = `/app/assets?prostaff_user_id=${encodeURIComponent(creator.id)}`

    const approvalsEnabled = auth?.approval_features?.approvals_enabled === true

    const awaitingReviewHref = useMemo(() => {
        if (!brand?.id) {
            return '/app/assets?lifecycle=pending_publication'
        }
        if (canManageCreators) {
            const q = new URLSearchParams({
                lifecycle: 'pending_publication',
                submitted_by_prostaff: '1',
                prostaff_user_id: String(creator.id),
            })
            return `/app/assets?${q.toString()}`
        }
        return '/app/assets?lifecycle=pending_publication'
    }, [brand?.id, canManageCreators, creator.id])

    const fetchAssetsPage = useCallback(
        async (url) => {
            setAssetsError(null)
            try {
                const res = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                })
                const data = await res.json().catch(() => ({}))
                if (!res.ok) {
                    setAssetsError(data?.message || 'Could not load uploads.')
                    return { assets: [], next: null }
                }
                return {
                    assets: Array.isArray(data.assets) ? data.assets : [],
                    next: data.next_page_url || null,
                }
            } catch {
                setAssetsError('Network error loading uploads.')
                return { assets: [], next: null }
            }
        },
        []
    )

    useEffect(() => {
        let cancelled = false
        const run = async () => {
            setAssetsLoading(true)
            const base = `/app/assets?format=json&prostaff_user_id=${encodeURIComponent(creator.id)}&page=1`
            const { assets: first, next } = await fetchAssetsPage(base)
            if (!cancelled) {
                setAssets(first)
                setNextPageUrl(next)
                setAssetsLoading(false)
            }
        }
        run()
        return () => {
            cancelled = true
        }
    }, [creator.id, fetchAssetsPage])

    const loadMore = async () => {
        if (!nextPageUrl) return
        setAssetsLoading(true)
        const { assets: more, next } = await fetchAssetsPage(nextPageUrl)
        setAssets((prev) => [...prev, ...more])
        setNextPageUrl(next)
        setAssetsLoading(false)
    }

    const saveTarget = async () => {
        setTargetError(null)
        setSavingTarget(true)
        try {
            const res = await fetch(
                route('api.brands.prostaff.members.update', { brand: brand.id, user: creator.id }),
                {
                    method: 'PUT',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf(),
                    },
                    body: JSON.stringify({
                        target_uploads: parseInt(targetInput, 10),
                        period_type: periodInput,
                    }),
                }
            )
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                setTargetError(data?.error || data?.message || 'Could not save.')
                return
            }
            setTargetModalOpen(false)
            router.reload({ preserveScroll: true })
        } catch {
            setTargetError('Network error.')
        } finally {
            setSavingTarget(false)
        }
    }

    const confirmRemove = async () => {
        setRemoveError(null)
        setRemoving(true)
        try {
            const res = await fetch(
                route('api.brands.prostaff.members.destroy', { brand: brand.id, user: creator.id }),
                {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf(),
                    },
                }
            )
            const data = await res.json().catch(() => ({}))
            if (!res.ok) {
                setRemoveError(data?.error || data?.message || 'Could not remove creator.')
                return
            }
            router.visit(
                canManageCreators
                    ? route('brands.creators', { brand: brand.id })
                    : route('overview')
            )
        } catch {
            setRemoveError('Network error.')
        } finally {
            setRemoving(false)
        }
    }

    const thumbFor = (a) => a?.final_thumbnail_url || a?.thumbnail_medium || a?.thumbnail_url || null

    const creatorsListUrl = useMemo(() => {
        if (typeof route !== 'function') {
            return canManageCreators ? `/app/brands/${brand.id}/creators` : '/app/overview'
        }
        return canManageCreators ? route('brands.creators', { brand: brand.id }) : route('overview')
    }, [brand.id, canManageCreators])

    const hasAgencyQuickLink = Array.isArray(auth?.companies)
        ? auth.companies.some((company) => company?.is_agency === true)
        : false
    const mobileTopPaddingClass = hasAgencyQuickLink
        ? 'pt-[calc(9rem+env(safe-area-inset-top))] lg:pt-[calc(9rem+1.5rem+env(safe-area-inset-top))] xl:pt-[calc(9rem+2rem+env(safe-area-inset-top))]'
        : 'pt-[calc(5.75rem+env(safe-area-inset-top))] lg:pt-[calc(6rem+env(safe-area-inset-top))]'

    return (
        <div className="relative min-h-[100dvh] overflow-x-hidden bg-[#0B0B0D] pb-28 sm:pb-16">
            <AppHead title={`${creator.name} — Creators`} />

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
                className={`relative z-10 mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-10 ${mobileTopPaddingClass} pb-12`}
            >
                <nav className="mb-6 text-sm">
                    <Link href={creatorsListUrl} className="text-white/45 transition hover:text-white/80">
                        {canManageCreators ? '← Creators' : '← Overview'}
                    </Link>
                    <span className="mx-2 text-white/25">/</span>
                    <span className="text-white/70">{creator.name}</span>
                </nav>

                {/* STEP 1 — Header */}
                <header className="rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl transition hover:border-white/[0.12] sm:p-8">
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">{creator.name}</h1>
                                <Link
                                    href={damAssetsHref}
                                    className="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-xl border border-white/20 bg-white/[0.08] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:border-white/30 hover:bg-white/[0.12] sm:self-auto"
                                >
                                    <RectangleStackIcon className="h-4 w-4 text-white/70" aria-hidden />
                                    View Assets
                                </Link>
                            </div>
                            <p className="mt-1 text-sm text-white/50">{creator.email}</p>
                            <p className="mt-3 text-sm leading-relaxed text-white/55">{quickInsight}</p>
                            {behindPace ? (
                                <p
                                    className="mt-3 rounded-xl border border-amber-400/25 bg-amber-500/10 px-3 py-2 text-sm font-medium text-amber-100/95"
                                    role="status"
                                >
                                    ⚠️ This creator is behind pace
                                </p>
                            ) : null}
                            <div className="mt-4 flex flex-wrap items-center gap-3">{statusBadge(performance?.status)}</div>
                        </div>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:text-right">
                            <div className="rounded-xl border border-white/[0.08] bg-black/20 px-4 py-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">Completion</p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums text-white">
                                    {Number.isFinite(pct) ? `${pct.toFixed(1)}%` : '—'}
                                </p>
                            </div>
                            <div className="rounded-xl border border-white/[0.08] bg-black/20 px-4 py-3">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">Actual</p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums text-white">{performance?.actual_uploads ?? '—'}</p>
                            </div>
                            <div className="col-span-2 rounded-xl border border-white/[0.08] bg-black/20 px-4 py-3 sm:col-span-1">
                                <p className="text-[11px] font-semibold uppercase tracking-wide text-white/40">Target</p>
                                <p className="mt-1 text-2xl font-semibold tabular-nums text-white">
                                    {performance?.target_uploads != null ? performance.target_uploads : '—'}
                                </p>
                            </div>
                        </div>
                    </div>
                </header>

                <div className="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-12">
                    <div className="space-y-8 lg:col-span-7">
                        {/* STEP 2 — Performance */}
                        <motion.section
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.4 }}
                            className="rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl"
                        >
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-white/45">Performance</h2>
                            <p className="mt-1 text-xs text-white/40">
                                {periodLabel(performance?.period_type)} · {performance?.period_start} → {performance?.period_end}
                            </p>
                            <div className="mt-5 flex items-end justify-between gap-4">
                                <p className="text-3xl font-semibold tabular-nums text-white">#{performance?.rank ?? '—'}</p>
                                <span className="text-sm text-white/50">Rank in brand</span>
                            </div>
                            <div className="mt-6 h-3 w-full overflow-hidden rounded-full bg-black/50 ring-1 ring-inset ring-white/10">
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
                        </motion.section>

                        {/* STEP 3 — Uploads */}
                        <motion.section
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.45, delay: 0.05 }}
                            className="rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)] backdrop-blur-xl"
                        >
                            <h2 className="text-sm font-semibold uppercase tracking-wide text-white/45">Uploads</h2>
                            {assetsError ? (
                                <p className="mt-4 text-sm text-rose-300/90">{assetsError}</p>
                            ) : null}
                            <div className="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                                {assetsLoading && assets.length === 0
                                    ? Array.from({ length: 8 }).map((_, i) => (
                                          <div
                                              key={i}
                                              className="aspect-square animate-pulse rounded-xl bg-white/[0.06] ring-1 ring-white/10"
                                          />
                                      ))
                                    : !assetsLoading && assets.length === 0 && !assetsError ? (
                                          <div className="col-span-full flex flex-col items-center justify-center rounded-2xl border border-dashed border-white/15 bg-black/20 px-6 py-14 text-center">
                                              <RectangleStackIcon className="h-10 w-10 text-white/25" aria-hidden />
                                              <p className="mt-4 text-sm font-medium text-white/55">
                                                  No uploads yet — submissions will appear here
                                              </p>
                                          </div>
                                      )
                                    : assets.map((a) => (
                                          <motion.a
                                              key={a.id}
                                              href={
                                                  typeof route === 'function'
                                                      ? route('assets.view', { asset: a.id })
                                                      : `/app/assets/${a.id}/view`
                                              }
                                              initial={{ opacity: 0, scale: 0.97 }}
                                              animate={{ opacity: 1, scale: 1 }}
                                              transition={{ duration: 0.25 }}
                                              className="group relative aspect-square overflow-hidden rounded-xl border border-white/[0.08] bg-black/30 ring-1 ring-white/[0.06] transition hover:border-white/20 hover:ring-white/15"
                                          >
                                              {thumbFor(a) ? (
                                                  <img
                                                      src={thumbFor(a)}
                                                      alt=""
                                                      className="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]"
                                                  />
                                              ) : (
                                                  <div className="flex h-full w-full items-center justify-center text-xs text-white/35">
                                                      No preview
                                                  </div>
                                              )}
                                              <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-2 pt-8">
                                                  <p className="truncate text-[11px] font-medium text-white/90">{a.title || 'Untitled'}</p>
                                              </div>
                                          </motion.a>
                                      ))}
                            </div>
                            {nextPageUrl ? (
                                <button
                                    type="button"
                                    onClick={loadMore}
                                    disabled={assetsLoading}
                                    className="mt-6 w-full rounded-xl border border-white/15 bg-white/[0.06] py-2.5 text-sm font-semibold text-white/85 transition hover:bg-white/10 disabled:opacity-50"
                                >
                                    {assetsLoading ? 'Loading…' : 'Load more'}
                                </button>
                            ) : null}
                        </motion.section>
                    </div>

                    <div className="space-y-8 lg:col-span-5">
                        {approvalsEnabled && awaiting_brand_review_count > 0 ? (
                            <motion.section
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.45, delay: 0.06 }}
                                className="rounded-2xl border border-amber-400/35 bg-amber-950/25 p-5 shadow-[0_0_28px_rgba(245,158,11,0.1),inset_0_1px_0_0_rgba(255,255,255,0.05)] backdrop-blur-xl"
                            >
                                <div className="flex items-start gap-3">
                                    <div
                                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-amber-400/25 bg-amber-500/15"
                                        style={{ boxShadow: `0 0 16px ${brandColor}22` }}
                                    >
                                        <ClockIcon className="h-5 w-5 text-amber-200/90" aria-hidden />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <h2 className="text-sm font-semibold uppercase tracking-wide text-amber-100/90">
                                            Awaiting brand review
                                        </h2>
                                        <p className="mt-1 text-sm leading-relaxed text-amber-50/80">
                                            {awaiting_brand_review_count === 1
                                                ? '1 creator upload is pending approval before it can go live.'
                                                : `${awaiting_brand_review_count} creator uploads are pending approval before they can go live.`}
                                        </p>
                                        <p className="mt-2 text-xs text-amber-100/50">
                                            {canManageCreators
                                                ? 'Review in the library or full queue—approve and publish from the asset drawer, same as team uploads.'
                                                : 'You can track status here; approvers publish from the queue or asset drawer.'}
                                        </p>
                                        <Link
                                            href={awaitingReviewHref}
                                            className="mt-4 inline-flex items-center rounded-xl border border-amber-300/35 bg-amber-500/15 px-4 py-2.5 text-sm font-semibold text-amber-50 transition hover:bg-amber-500/25"
                                        >
                                            {canManageCreators ? 'Review pending uploads' : 'View in library'}
                                        </Link>
                                    </div>
                                </div>
                            </motion.section>
                        ) : null}

                        {/* STEP 4 — Rejections */}
                        <motion.section
                            initial={{ opacity: 0, y: 10 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: 0.45, delay: 0.08 }}
                            className="overflow-hidden rounded-2xl border-2 border-rose-500/35 bg-rose-950/20 shadow-[0_0_32px_rgba(244,63,94,0.08),inset_0_1px_0_0_rgba(255,255,255,0.04)] backdrop-blur-xl"
                        >
                            <button
                                type="button"
                                onClick={() => setRejectionsOpen((o) => !o)}
                                className="flex w-full items-start gap-3 border-b border-rose-500/20 bg-rose-500/[0.07] px-4 py-4 text-left transition hover:bg-rose-500/10 sm:px-5 sm:py-4"
                                aria-expanded={rejectionsOpen}
                            >
                                <NoSymbolIcon className="mt-0.5 h-6 w-6 shrink-0 text-rose-400" aria-hidden />
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-sm font-semibold uppercase tracking-wide text-rose-100/90">
                                            Rejections & feedback
                                        </h2>
                                        <span className="rounded-full border border-rose-400/30 bg-rose-500/15 px-2 py-0.5 text-[11px] font-semibold tabular-nums text-rose-100/90">
                                            {rejections.length}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-xs text-rose-100/55">Creator-tagged assets rejected in approval.</p>
                                </div>
                                <span className="shrink-0 rounded-lg border border-rose-400/25 bg-black/20 p-1.5 text-rose-200">
                                    {rejectionsOpen ? (
                                        <ChevronUpIcon className="h-5 w-5" aria-hidden />
                                    ) : (
                                        <ChevronDownIcon className="h-5 w-5" aria-hidden />
                                    )}
                                </span>
                            </button>
                            <AnimatePresence initial={false}>
                                {rejectionsOpen ? (
                                    <motion.div
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        transition={{ duration: 0.28, ease: [0.22, 1, 0.36, 1] }}
                                        className="overflow-hidden"
                                    >
                                        <ul className="max-h-[28rem] space-y-3 overflow-y-auto p-4 sm:p-5">
                                            {rejections.length === 0 ? (
                                                <li className="rounded-xl border border-white/[0.08] bg-black/25 px-4 py-4 text-sm text-white/45">
                                                    No rejections for this period of history.
                                                </li>
                                            ) : (
                                                rejections.map((r) => (
                                                    <li
                                                        key={r.id}
                                                        className="rounded-xl border border-rose-400/25 border-l-4 border-l-rose-500/80 bg-black/35 pl-4 pr-4 py-3 shadow-sm transition hover:border-rose-400/40 hover:bg-black/45"
                                                    >
                                                        <div className="flex gap-2">
                                                            <NoSymbolIcon
                                                                className="mt-0.5 h-4 w-4 shrink-0 text-rose-400/90"
                                                                aria-hidden
                                                            />
                                                            <div className="min-w-0 flex-1">
                                                                <Link
                                                                    href={
                                                                        typeof route === 'function'
                                                                            ? route('assets.view', { asset: r.id })
                                                                            : `/app/assets/${r.id}/view`
                                                                    }
                                                                    className="text-sm font-medium text-white hover:text-rose-100"
                                                                >
                                                                    {r.title || 'Untitled asset'}
                                                                </Link>
                                                                {r.rejection_reason ? (
                                                                    <p className="mt-2 text-xs leading-relaxed text-rose-100/80">
                                                                        {r.rejection_reason}
                                                                    </p>
                                                                ) : null}
                                                                <p className="mt-2 text-[11px] text-white/40">
                                                                    {formatDate(r.rejected_at)}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    </li>
                                                ))
                                            )}
                                        </ul>
                                    </motion.div>
                                ) : null}
                            </AnimatePresence>
                        </motion.section>

                        {/* STEP 5 — Actions */}
                        {canManageCreators ? (
                            <motion.section
                                initial={{ opacity: 0, y: 10 }}
                                animate={{ opacity: 1, y: 0 }}
                                transition={{ duration: 0.45, delay: 0.1 }}
                                className="rounded-2xl border border-white/10 bg-white/[0.04] p-6 backdrop-blur-xl"
                            >
                                <h2 className="text-sm font-semibold uppercase tracking-wide text-white/45">Actions</h2>
                                <div className="mt-4 flex flex-col gap-3">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setTargetInput(String(membership?.target_uploads ?? performance?.target_uploads ?? ''))
                                            setPeriodInput(String(membership?.period_type || performance?.period_type || 'month'))
                                            setTargetError(null)
                                            setTargetModalOpen(true)
                                        }}
                                        className="rounded-xl border border-white/15 bg-white/[0.08] px-4 py-3 text-left text-sm font-semibold text-white transition hover:bg-white/12"
                                    >
                                        Adjust target
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setRemoveError(null)
                                            setRemoveOpen(true)
                                        }}
                                        className="rounded-xl border border-rose-400/30 bg-rose-500/10 px-4 py-3 text-left text-sm font-semibold text-rose-100 transition hover:bg-rose-500/20"
                                    >
                                        Remove creator
                                    </button>
                                </div>
                            </motion.section>
                        ) : null}
                    </div>
                </div>
            </main>

            <div className="relative z-10">
                <AppFooter variant="dark" />
            </div>

            {/* Adjust target modal */}
            {targetModalOpen ? (
                <div className="fixed inset-0 z-[210] flex items-end justify-center p-4 sm:items-center">
                    <button
                        type="button"
                        className="absolute inset-0 bg-black/70 backdrop-blur-sm"
                        aria-label="Close"
                        onClick={() => !savingTarget && setTargetModalOpen(false)}
                    />
                    <div className="relative w-full max-w-md rounded-2xl border border-white/10 bg-[#12141a]/95 p-6 shadow-2xl backdrop-blur-2xl">
                        <h3 className="text-lg font-semibold text-white">Adjust target</h3>
                        {targetError ? <p className="mt-2 text-sm text-rose-300">{targetError}</p> : null}
                        <label className="mt-4 block text-xs font-medium text-white/50">Target uploads</label>
                        <input
                            type="number"
                            min={0}
                            value={targetInput}
                            onChange={(e) => setTargetInput(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-white/15 bg-black/40 px-3 py-2 text-sm text-white"
                        />
                        <label className="mt-4 block text-xs font-medium text-white/50">Period</label>
                        <select
                            value={periodInput}
                            onChange={(e) => setPeriodInput(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-white/15 bg-black/40 px-3 py-2 text-sm text-white"
                        >
                            <option value="month">Month</option>
                            <option value="quarter">Quarter</option>
                            <option value="year">Year</option>
                        </select>
                        <div className="mt-6 flex justify-end gap-2">
                            <button
                                type="button"
                                disabled={savingTarget}
                                onClick={() => setTargetModalOpen(false)}
                                className="rounded-xl border border-white/15 px-4 py-2 text-sm text-white/80"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={savingTarget}
                                onClick={saveTarget}
                                className="rounded-xl bg-white/90 px-4 py-2 text-sm font-semibold text-gray-900"
                            >
                                {savingTarget ? 'Saving…' : 'Save'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}

            <ConfirmDialog
                open={removeOpen}
                onClose={() => !removing && setRemoveOpen(false)}
                onConfirm={confirmRemove}
                title="Remove creator"
                message={`Remove ${creator.name} as a creator for ${brand.name}? Their prostaff membership will end; brand access as a contributor is unchanged.`}
                confirmText="Remove creator"
                variant="warning"
                loading={removing}
                error={removeError}
            />
        </div>
    )
}
