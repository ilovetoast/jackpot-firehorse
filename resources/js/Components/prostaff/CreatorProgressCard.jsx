import { motion } from 'framer-motion'

function periodLabel(periodType) {
    const p = String(periodType || '').toLowerCase()
    if (p === 'month') return 'Month'
    if (p === 'quarter') return 'Quarter'
    if (p === 'year') return 'Year'
    return p ? p.charAt(0).toUpperCase() + p.slice(1) : 'Period'
}

function statusBadge(status) {
    const s = String(status || '').toLowerCase()
    const map = {
        behind: 'border-rose-400/40 bg-rose-500/15 text-rose-100',
        on_track: 'border-amber-400/35 bg-amber-500/12 text-amber-100',
        complete: 'border-emerald-400/40 bg-emerald-500/15 text-emerald-100',
    }
    const cls = map[s] || 'border-white/15 bg-white/10 text-white/70'
    const label = s === 'on_track' ? 'On track' : s ? s.replace(/_/g, ' ') : '—'
    return (
        <span
            className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${cls}`}
        >
            {label}
        </span>
    )
}

/**
 * Prostaff self-service progress on Overview (data from GET /app/api/prostaff/me).
 *
 * @param {{
 *   data?: { actual_uploads?: number, target_uploads?: number|null, completion_percentage?: number, period_type?: string, status?: string } | null,
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
                aria-label="Loading creator progress"
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
                <div>
                    <h2 className="text-sm font-semibold uppercase tracking-wide text-white/45">
                        Your creator progress
                    </h2>
                    <p className="mt-1 text-xs text-white/40">
                        Upload quota · {periodLabel(data.period_type)}
                    </p>
                    <p className="mt-3 text-lg font-medium tabular-nums text-white">
                        {actual}
                        <span className="text-white/40"> of </span>
                        {target != null && Number.isFinite(target) ? target : '—'}
                        <span className="text-sm font-normal text-white/50"> uploads</span>
                    </p>
                </div>
                <div className="shrink-0 sm:pt-0.5">{statusBadge(data.status)}</div>
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
            <p className="mt-2 text-right text-xs tabular-nums text-white/40">{pct.toFixed(1)}% complete</p>
        </motion.div>
    )
}
