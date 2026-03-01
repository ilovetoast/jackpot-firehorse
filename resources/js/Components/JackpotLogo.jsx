/**
 * Jackpot SVG logo + text for marketing and backend when no brand is active.
 * Uses the official jp-logo.svg (purple/blue gradient).
 */
export default function JackpotLogo({ className = 'h-8 w-auto', textClassName = 'text-xl font-bold text-gray-900', ...imgProps }) {
    return (
        <span className="inline-flex items-center gap-2">
            <img
                src="/jp-logo.svg"
                alt=""
                className={className}
                aria-hidden="true"
                {...imgProps}
            />
            <span className={textClassName}>Jackpot</span>
        </span>
    )
}
