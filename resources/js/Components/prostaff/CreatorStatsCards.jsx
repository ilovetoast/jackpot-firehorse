function shimmerCard() {
    return (
        <div className="animate-pulse overflow-hidden rounded-2xl border border-white/10 bg-white/[0.04] p-5 backdrop-blur-xl">
            <div className="h-3 w-24 rounded bg-white/10" />
            <div className="mt-4 h-8 w-16 rounded-lg bg-white/10" />
        </div>
    )
}

/**
 * @param {{
 *   creators: Array<{ completion_percentage?: number, actual_uploads?: number }>,
 *   loading: boolean,
 *   pendingInviteCount?: number,
 * }} props
 */
export default function CreatorStatsCards({ creators, loading, pendingInviteCount = 0 }) {
    if (loading) {
        return (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {shimmerCard()}
                {shimmerCard()}
                {shimmerCard()}
            </div>
        )
    }

    const n = creators.length
    const avgPct =
        n > 0
            ? creators.reduce((s, r) => s + (Number(r.completion_percentage) || 0), 0) / n
            : 0
    const totalUploads = creators.reduce((s, r) => s + (Number(r.actual_uploads) || 0), 0)

    const items = [
        {
            label: 'Total Creators',
            value: String(n),
            hint:
                pendingInviteCount > 0
                    ? `${pendingInviteCount} pending invite${pendingInviteCount === 1 ? '' : 's'}`
                    : null,
        },
        { label: 'Avg completion', value: `${avgPct.toFixed(1)}%`, hint: null },
        { label: 'Total uploads (period)', value: totalUploads.toLocaleString(), hint: null },
    ]

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {items.map((item) => (
                <div
                    key={item.label}
                    className="group relative overflow-hidden rounded-2xl border border-white/10 bg-white/[0.05] p-5 shadow-[0_0_40px_-12px_rgba(255,255,255,0.15)] backdrop-blur-xl transition duration-300 hover:border-white/20 hover:bg-white/[0.08] hover:shadow-[0_0_48px_-8px_rgba(255,255,255,0.2)]"
                >
                    <div className="pointer-events-none absolute -right-6 -top-6 h-24 w-24 rounded-full bg-white/[0.06] blur-2xl transition group-hover:bg-white/[0.1]" />
                    <p className="text-xs font-medium uppercase tracking-wide text-white/45">{item.label}</p>
                    <p className="mt-2 text-2xl font-semibold tabular-nums text-white">{item.value}</p>
                    {item.hint ? <p className="mt-1 text-xs text-violet-200/80">{item.hint}</p> : null}
                </div>
            ))}
        </div>
    )
}
