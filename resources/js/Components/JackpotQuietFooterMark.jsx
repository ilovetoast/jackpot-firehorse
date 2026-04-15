/**
 * Decorative Jackpot logo for dark cinematic footers (low contrast, non-interactive).
 */
export default function JackpotQuietFooterMark({ className = '' }) {
    return (
        <img
            src="/jp-logo.svg"
            alt=""
            aria-hidden
            decoding="async"
            className={`pointer-events-none mx-auto block h-5 w-auto opacity-[0.17] select-none sm:h-[1.35rem] ${className}`.trim()}
        />
    )
}
