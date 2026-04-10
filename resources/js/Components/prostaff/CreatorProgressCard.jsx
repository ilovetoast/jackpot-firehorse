import { Link } from '@inertiajs/react'
import { motion } from 'framer-motion'

function periodLabel(periodType) {
    const p = String(periodType || '').toLowerCase()
    if (p === 'month') return 'Month'
    if (p === 'quarter') return 'Quarter'
    if (p === 'year') return 'Year'
    return p ? p.charAt(0).toUpperCase() + p.slice(1) : 'Period'
}

function initialsFromName(name) {
    const n = String(name || '').trim()
    if (!n) return '?'
    const parts = n.split(/\s+/).filter(Boolean)
    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
    }
    return n.slice(0, 2).toUpperCase()
}

/** Encouraging copy — no “behind pace” framing. */
function statusBadge(status, completionPct) {
    const s = String(status || '').toLowerCase()
    const pct = Number(completionPct)
    const pctRounded = Number.isFinite(pct) ? Math.min(100, Math.max(0, Math.round(pct))) : null
    const map = {
        behind: 'border-indigo-400/35 bg-indigo-500/12 text-indigo-100',
        on_track: 'border-sky-400/35 bg-sky-500/12 text-sky-100',
        complete: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    }
    const cls = map[s] || 'border-white/15 bg-white/10 text-white/70'
    const labels = {
        behind: pctRounded != null ? `${pctRounded}% toward goal` : 'Working toward goal',
        on_track: 'Strong progress',
        complete: 'Goal reached',
    }
    const label = labels[s] || '—'
    return (
        <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium ${cls}`}>{label}</span>
    )
}

/**
 * Prostaff self-service progress on Overview (data from GET /app/api/prostaff/me).
 *
 * @param {{
 *   data?: { actual_uploads?: number, target_uploads?: number|null, uploads_remaining?: number|null, completion_percentage?: number, period_type?: string, status?: string, avatar_url?: string|null, last_login_at?: string|null, total_assets_uploaded?: number, display_name?: string } | null,
 *   loading?: boolean,
 *   brandColor?: string,
 * }} props
 */
export default function CreatorProgressCard({ data, loading = false, brandColor = '#6366f1' }) {
    if (loading) {
        return (
            <div
                className="animate-fadeInUp-d2 relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.05] p-5 shadow-[0_0_40px_-12px_rgba(255,255,255,0.12)] backdrop-blur-xl"
                aria-busy="true"
                aria-label="Loading Creator Home"
            >
                <div className="absolute inset-0 animate-pulse bg-gradient-to-r from-transparent via-white/[0.04] to-transparent" />
                <div className="relative space-y-4">
                    <div className="h-4 w-48 rounded-md bg-white/10" />
                    <div className="h-2.5 w-full rounded-full bg-white/10" />
                    <div className="h-3 w-36 rounded bg-white/10" />
                </div>
            </div>
        )
    }

    if (!data || typeof data !== 'object') {
        return null
    }

    const actual = Number(data.actual_uploads) || 0
    const target = data.target_uploads != null ? Number(data.target_uploads) : null
    const pctRaw = Number(data.completion_percentage)
    const pct = Number.isFinite(pctRaw) ? Math.min(100, Math.max(0, pctRaw)) : 0
    const uploadsRemaining =
        data.uploads_remaining != null && Number.isFinite(Number(data.uploads_remaining))
            ? Number(data.uploads_remaining)
            : null
    const targetNum = target != null && Number.isFinite(target) ? Number(target) : null
    const progressHint =
        targetNum != null && targetNum > 0
            ? uploadsRemaining != null && uploadsRemaining > 0
                ? `${uploadsRemaining} upload${uploadsRemaining === 1 ? '' : 's'} to reach your target`
                : uploadsRemaining === 0
                  ? "You've reached your target for this period"
                  : null
            : null

    const displayName = data.display_name || ''
    const avatarUrl = data.avatar_url ?? null
    const totalAll =
        data.total_assets_uploaded != null && Number.isFinite(Number(data.total_assets_uploaded))
            ? Number(data.total_assets_uploaded)
            : null

    return (
        <motion.div
            className="animate-fadeInUp-d2 relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.05] p-5 shadow-[0_0_40px_-12px_rgba(255,255,255,0.15)] backdrop-blur-xl transition-[box-shadow,border-color] duration-300 hover:border-white/15 hover:shadow-[0_0_48px_-8px_rgba(255,255,255,0.18)]"
            style={{ boxShadow: `0 0 32px ${brandColor}14` }}
            initial={{ opacity: 0, y: 8 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
        >
            <div
                className="pointer-events-none absolute -right-8 -top-8 h-28 w-28 rounded-full opacity-40 blur-2xl"
                style={{ backgroundColor: `${brandColor}33` }}
            />
            <div className="relative flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 flex-1 gap-4">
                    {avatarUrl ? (
                        <img
                            src={avatarUrl}
                            alt=""
                            className="h-14 w-14 shrink-0 rounded-xl object-cover ring-1 ring-white/15"
                        />
                    ) : (
                        <div
                            className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl text-sm font-semibold text-white/90 ring-1 ring-white/15"
                            style={{
                                background: `linear-gradient(145deg, ${brandColor}66, ${brandColor}33)`,
                            }}
                            aria-hidden
                        >
                            {initialsFromName(displayName)}
                        </div>
                    )}
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-white/45">Creator Home</h2>
                        {displayName ? (
                            <p className="mt-0.5 truncate text-base font-medium text-white/90">{displayName}</p>
                        ) : null}
                        <p className="mt-1 text-xs text-white/40">
                            Upload quota · {periodLabel(data.period_type)}
                            {totalAll != null ? (
                                <>
                                    {' '}
                                    · <span className="tabular-nums text-white/55">{totalAll}</span> assets on brand
                                </>
                            ) : null}
                        </p>
                        <p className="mt-3 text-lg font-medium tabular-nums text-white">
                            {actual}
                            <span className="text-white/40"> of </span>
                            {target != null && Number.isFinite(target) ? target : '—'}
                            <span className="text-sm font-normal text-white/50"> this period</span>
                        </p>
                        {uploadsRemaining != null && targetNum != null && targetNum > 0 ? (
                            <p className="mt-2 text-xs text-white/45">
                                <span className="font-semibold tabular-nums text-white/70">{uploadsRemaining}</span> upload
                                {uploadsRemaining === 1 ? '' : 's'} remaining
                            </p>
                        ) : null}
                        {progressHint ? <p className="mt-1 text-xs text-white/40">{progressHint}</p> : null}
                    </div>
                </div>
                <div className="shrink-0 sm:pt-0.5">{statusBadge(data.status, data.completion_percentage)}</div>
            </div>
            <div className="relative mt-5 h-2.5 w-full overflow-hidden rounded-full bg-black/40 ring-1 ring-inset ring-white/10">
                <motion.div
                    className="h-full rounded-full"
                    style={{
                        background: `linear-gradient(90deg, ${brandColor}cc, ${brandColor})`,
                        boxShadow: `0 0 12px ${brandColor}66`,
                    }}
                    initial={{ width: 0 }}
                    animate={{ width: `${pct}%` }}
                    transition={{ duration: 0.85, ease: [0.22, 1, 0.36, 1] }}
                />
            </div>
            <p className="mt-2 text-right text-xs tabular-nums text-white/40">{pct.toFixed(1)}% toward your goal</p>
            <div className="relative mt-4 border-t border-white/10 pt-4">
                <Link
                    href={
                        typeof route === 'function'
                            ? route('overview.creator-progress')
                            : '/app/overview/creator-progress'
                    }
                    className="inline-flex items-center gap-1 text-sm font-semibold text-white/80 transition hover:text-white"
                >
                    Open Creator Home
                    <span aria-hidden className="text-white/45">
                        →
                    </span>
                </Link>
                <p className="mt-1 text-xs text-white/35">Review status, approved work, and benchmarks</p>
            </div>
        </motion.div>
    )
}
