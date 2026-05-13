/**
 * Decorative Jackpot wordmark for footers (low contrast, non-interactive).
 *
 * @param {{ className?: string, surface?: 'dark' | 'light' }} props
 * - `dark` — inverted asset on charcoal / cinematic surfaces.
 * - `light` — mark tuned for pale backgrounds (manage, settings).
 */
export default function JackpotQuietFooterMark({ className = '', surface = 'dark' }) {
    const light = surface === 'light'
    return (
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
}
