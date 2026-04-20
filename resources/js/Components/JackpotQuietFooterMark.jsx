/**
 * Decorative Jackpot wordmark for dark cinematic footers (low contrast,
 * non-interactive). Uses the inverted wordmark since it sits on dark
 * surfaces at very low opacity.
 */
export default function JackpotQuietFooterMark({ className = '' }) {
    return (
        <img
            src="/jp-wordmark-inverted.svg"
            alt=""
            aria-hidden
            decoding="async"
            className={`pointer-events-none mx-auto block h-5 w-auto opacity-[0.17] select-none sm:h-[1.35rem] ${className}`.trim()}
        />
    )
}
