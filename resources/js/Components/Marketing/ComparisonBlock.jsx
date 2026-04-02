/**
 * Two-column contrast: muted left vs highlighted right (Jackpot).
 */
export default function ComparisonBlock({ leftTitle, rightTitle, leftItems, rightItems, footnote }) {
    return (
        <div className="mx-auto max-w-5xl">
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-8 lg:items-stretch">
                <div className="rounded-2xl bg-white/[0.02] p-8 sm:p-10 ring-1 ring-white/[0.06]">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-white/40">{leftTitle}</h3>
                    <ul className="mt-8 space-y-5">
                        {leftItems.map((line, i) => (
                            <li key={i} className="flex gap-3 text-base leading-relaxed text-white/45">
                                <span className="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/[0.06] text-white/35 text-xs">
                                    ×
                                </span>
                                <span>{line}</span>
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="rounded-2xl bg-gradient-to-br from-indigo-500/[0.08] to-violet-600/[0.06] p-8 sm:p-10 ring-1 ring-indigo-400/25 shadow-lg shadow-indigo-950/40 lg:scale-[1.02]">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-indigo-300">{rightTitle}</h3>
                    <ul className="mt-8 space-y-5">
                        {rightItems.map((line, i) => (
                            <li key={i} className="flex gap-3 text-base leading-relaxed text-white/70">
                                <span className="mt-1 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-500/25 text-indigo-300 text-xs font-bold">
                                    ✓
                                </span>
                                <span>{line}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
            {footnote && <p className="mt-10 text-center text-sm text-white/35 max-w-2xl mx-auto">{footnote}</p>}
        </div>
    )
}
