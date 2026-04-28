/**
 * Jackpot icon-mark + "Jackpot" text label for marketing and in-app contexts
 * where no brand is active. Uses the PWA / manifest app icon.
 *
 * The mark is hidden from assistive tech since the text label carries the name.
 * If you need the full wordmark instead, use `/jp-wordmark-inverted.svg` (on
 * dark) or `/jp-wordmark-on-white-bg.svg` (on light).
 */
export default function JackpotLogo({ className = 'h-8 w-auto', textClassName = 'text-xl font-bold text-gray-900', ...imgProps }) {
    return (
        <span className="inline-flex items-center gap-2">
            <img
                src="/android-chrome-192x192.png"
                alt=""
                className={className}
                aria-hidden="true"
                decoding="async"
                {...imgProps}
            />
            <span className={textClassName}>Jackpot</span>
        </span>
    )
}
