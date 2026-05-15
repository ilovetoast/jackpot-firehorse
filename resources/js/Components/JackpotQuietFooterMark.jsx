/**
 * Decorative Jackpot wordmark for footers (low contrast). Optional `href` wraps the mark in a link
 * (e.g. marketing home with ?marketing_site=1 for authenticated opt-in).
 *
 * @param {{ className?: string, surface?: 'dark' | 'light', href?: string|null, linkAriaLabel?: string }} props
 * - `dark` — inverted asset on charcoal / cinematic surfaces.
 * - `light` — mark tuned for pale backgrounds (manage, settings).
 */
export default function JackpotQuietFooterMark({
    className = '',
    surface = 'dark',
    href = null,
    linkAriaLabel = 'Jackpot marketing site',
}) {
    const light = surface === 'light'
    const img = (
        <img
            src={light ? '/jp-wordmark-on-white-bg.svg' : '/jp-wordmark-inverted.svg'}
            alt=""
            aria-hidden
            decoding="async"
            className={`pointer-events-none mx-auto block w-auto select-none ${
                light
                    ? 'h-[1.05rem] opacity-[0.22] sm:h-[1.2rem]'
                    : 'h-5 opacity-[0.17] sm:h-[1.35rem]'
            } ${className}`.trim()}
        />
    )
    if (!href) {
        return img
    }
    return (
        <a
            href={href}
            aria-label={linkAriaLabel}
            className="group/mark inline-block rounded-sm outline-none ring-offset-2 transition hover:opacity-100 focus-visible:ring-2 focus-visible:ring-indigo-500/40"
        >
            <span className="block opacity-90 transition group-hover/mark:opacity-100">{img}</span>
        </a>
    )
}
