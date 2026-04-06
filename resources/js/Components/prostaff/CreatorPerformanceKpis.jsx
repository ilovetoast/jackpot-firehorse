function glassCard(brandColor) {
    return {
        boxShadow: `0 0 28px ${brandColor}14`,
    }
}

/**
 * Manager dashboard: on-track %, period uploads, top performer (moved from cinematic overview).
 *
 * @param {{
 *   rows: Array<{ name?: string, completion_percentage?: number, actual_uploads?: number, is_on_track?: boolean }>,
 *   loading?: boolean,
 *   brandColor?: string,
 * }} props
 */
export default function CreatorPerformanceKpis({ rows = [], loading = false, brandColor = '#6366f1' }) {
    if (loading) {
        return (
            <div
                className="rounded-2xl border border-white/[0.08] bg-white/[0.03] px-4 py-4 backdrop-blur-md sm:px-5"
                style={glassCard(brandColor)}
                aria-busy="true"
            >
                <div className="h-3 w-40 animate-pulse rounded bg-white/10" />
                <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    {[1, 2, 3].map((i) => (
                        <div key={i} className="h-20 animate-pulse rounded-xl border border-white/10 bg-black/20" />
                    ))}
                </div>
            </div>
        )
    }

    if (!rows.length) {
        return null
    }

    const n = rows.length
    const onTrack = rows.filter((r) => r.is_on_track === true).length
    const onTrackPct = Math.round((onTrack / n) * 100)
    const totalActual = rows.reduce((s, r) => s + Number(r.actual_uploads || 0), 0)
    const top = [...rows].sort(
        (a, b) => Number(b.completion_percentage || 0) - Number(a.completion_percentage || 0)
    )[0]

    return (
        <div
            className="rounded-2xl border border-white/[0.08] bg-white/[0.03] px-4 py-4 backdrop-blur-md sm:px-5"
            style={glassCard(brandColor)}
        >
            <p className="text-xs font-semibold uppercase tracking-wide text-white/45">Creator performance</p>
            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="rounded-xl border border-white/10 bg-black/20 px-3 py-3">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-white/40">On track</p>
                    <p className="mt-1 text-xl font-semibold text-white">{onTrackPct}%</p>
                </div>
                <div className="rounded-xl border border-white/10 bg-black/20 px-3 py-3">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-white/40">
                        Total uploads (period)
                    </p>
                    <p className="mt-1 text-xl font-semibold text-white">{totalActual}</p>
                </div>
                <div className="rounded-xl border border-white/10 bg-black/20 px-3 py-3">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-white/40">Top performer</p>
                    <p className="mt-1 text-sm font-semibold text-white/90 line-clamp-2">{top?.name || '—'}</p>
                </div>
            </div>
        </div>
    )
}
