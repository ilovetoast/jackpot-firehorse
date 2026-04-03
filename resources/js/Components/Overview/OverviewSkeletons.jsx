/** Inline metric pills (cinematic overview) */
export function SkeletonMetricPills() {
    return (
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 mt-6" aria-hidden>
            {[1, 2, 3].map((i) => (
                <div
                    key={i}
                    className="h-4 w-24 animate-pulse rounded bg-white/[0.08]"
                />
            ))}
        </div>
    )
}

/** Plan badge row */
export function SkeletonPlanBadge() {
    return (
        <div className="h-7 w-40 max-w-full animate-pulse rounded-full bg-white/[0.08]" aria-hidden />
    )
}

/** Compact brand-tinted placeholder while overview insights load */
export function SkeletonBrandInsights({ brandColor = '#6366f1' }) {
    return (
        <div
            className="rounded-2xl border border-white/[0.08] bg-white/[0.035] px-4 py-3.5 backdrop-blur-sm"
            style={{ boxShadow: `0 0 24px ${brandColor}14` }}
            aria-hidden
        >
            <div className="flex gap-3">
                <div
                    className="h-9 w-9 shrink-0 animate-pulse rounded-xl"
                    style={{ backgroundColor: `${brandColor}24` }}
                />
                <div className="min-w-0 flex-1 space-y-2 pt-1">
                    <div className="h-2.5 w-28 max-w-[45%] animate-pulse rounded-md bg-white/[0.1]" />
                    <div className="h-2.5 w-full animate-pulse rounded-md bg-white/[0.06]" />
                    <div className="h-2.5 w-[85%] animate-pulse rounded-md bg-white/[0.05]" />
                </div>
            </div>
        </div>
    )
}
