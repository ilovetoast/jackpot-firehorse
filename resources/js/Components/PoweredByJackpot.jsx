/**
 * "Powered by Jackpot" SaaS branding badge.
 *
 * Used in the Brand Guidelines builder and Research pages so tenants
 * see that AI-generated brand assets come from the Jackpot platform.
 *
 * Variants:
 *   'badge'   — pill with logo + text (default)
 *   'inline'  — horizontal wordmark + subtext, no background
 *   'stacked' — centered column with larger wordmark
 */

const WORDMARK_SRC = '/jp-wordmark-inverted.svg'

export default function PoweredByJackpot({ variant = 'badge', className = '' }) {
    if (variant === 'stacked') {
        return (
            <div className={`flex flex-col items-center gap-2 ${className}`}>
                <img
                    src={WORDMARK_SRC}
                    alt="Jackpot"
                    className="h-6 w-auto brightness-0 invert opacity-90"
                    draggable={false}
                />
                <span className="text-[10px] font-medium uppercase tracking-[0.15em] text-white/30">
                    AI Brand Intelligence
                </span>
            </div>
        )
    }

    if (variant === 'inline') {
        return (
            <div className={`flex items-center gap-2.5 ${className}`}>
                <img
                    src={WORDMARK_SRC}
                    alt="Jackpot"
                    className="h-4 w-auto brightness-0 invert opacity-70"
                    draggable={false}
                />
                <span className="text-[10px] font-medium uppercase tracking-[0.12em] text-white/25">
                    Brand Intelligence
                </span>
            </div>
        )
    }

    return (
        <div
            className={`inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/[0.04] border border-white/[0.06] ${className}`}
        >
            <img
                src={WORDMARK_SRC}
                alt="Jackpot"
                className="h-3.5 w-auto brightness-0 invert opacity-60"
                draggable={false}
            />
            <span className="text-[10px] font-medium uppercase tracking-[0.12em] text-white/30">
                Powered by
            </span>
        </div>
    )
}
