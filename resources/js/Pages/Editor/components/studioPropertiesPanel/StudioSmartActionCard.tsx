import type { ReactNode } from 'react'

/** Primary tools band: `ai` = purple Jackpot Studio accent; `neutral` = quiet panel for non-AI styling. */
export function StudioSmartActionCard({
    title,
    icon,
    children,
    className = '',
    tone = 'ai',
}: {
    title: string
    icon?: ReactNode
    children: ReactNode
    className?: string
    tone?: 'ai' | 'neutral'
}) {
    const shell =
        tone === 'neutral'
            ? 'rounded-xl border border-gray-700/90 border-l-[3px] border-l-slate-600/35 bg-gray-800/35 py-2 pl-2.5 pr-2.5 shadow-sm ring-1 ring-inset ring-black/12'
            : 'rounded-xl border border-violet-500/25 border-l-[3px] border-l-indigo-500/50 bg-gradient-to-b from-violet-950/35 via-gray-900/75 to-gray-900 py-2 pl-2.5 pr-2.5 shadow-md shadow-black/25 ring-1 ring-inset ring-violet-500/12'
    const titleCls =
        tone === 'neutral'
            ? 'text-[11px] font-semibold tracking-wide text-gray-200'
            : 'text-[11px] font-semibold tracking-wide text-violet-50/95'
    const iconWrap =
        tone === 'neutral'
            ? 'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-gray-900/50 text-gray-300 ring-1 ring-inset ring-gray-800/80'
            : 'flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-violet-600/22 text-violet-100 ring-1 ring-inset ring-violet-400/18'
    return (
        <div className={`${shell} ${className}`}>
            <div className="mb-1.5 flex items-center gap-1.5">
                {icon ? <div className={iconWrap}>{icon}</div> : null}
                <p className={titleCls}>{title}</p>
            </div>
            <div className="space-y-2">{children}</div>
        </div>
    )
}
