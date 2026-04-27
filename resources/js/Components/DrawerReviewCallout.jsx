/**
 * Shared shell for asset drawer “Review” info callouts: site typography + subtle Jackpot wordmark.
 * Keeps copy readable (slate) instead of heavy amber/rust tints.
 */

const WORDMARK_SRC = '/jp-wordmark-on-white-bg.svg'

/**
 * @param {object} props
 * @param {string} [props.title]
 * @param {import('react').ComponentType<{ className?: string }>|null} [props.titleIcon]
 * @param {string} [props.titleIconClassName] — Tailwind classes for the title icon (e.g. brand accent).
 * @param {'in_progress' | 'neutral'} [props.variant]
 * @param {import('react').ReactNode} props.children
 * @param {string} [props.className]
 */
export default function DrawerReviewCallout({
    title,
    titleIcon: TitleIcon,
    titleIconClassName = 'text-slate-500',
    variant = 'neutral',
    children,
    className = '',
}) {
    const shell =
        variant === 'in_progress'
            ? 'border-sky-200/80 bg-gradient-to-br from-sky-50/60 via-white to-white'
            : 'border-slate-200/80 bg-gradient-to-br from-slate-50/50 via-white to-white'

    return (
        <div
            className={`relative overflow-hidden rounded-lg border p-3.5 shadow-sm shadow-slate-900/[0.03] ${shell} ${className}`.trim()}
        >
            <img
                src={WORDMARK_SRC}
                alt=""
                aria-hidden
                className="pointer-events-none absolute -bottom-0.5 -right-0.5 h-9 w-auto select-none opacity-[0.07] sm:h-10 sm:opacity-[0.08]"
                draggable={false}
            />
            <div className="relative z-[1] pr-10 sm:pr-12">
                {title ? (
                    <div className="mb-2 flex items-center gap-2">
                        {TitleIcon ? (
                            <TitleIcon className={`h-4 w-4 shrink-0 ${titleIconClassName}`.trim()} aria-hidden />
                        ) : null}
                        <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
                    </div>
                ) : null}
                {children}
            </div>
        </div>
    )
}
