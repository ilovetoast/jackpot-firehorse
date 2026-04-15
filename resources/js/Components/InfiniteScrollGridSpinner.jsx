/**
 * Infinite-scroll “load more” spinner on white grid chrome — uses tenant brand when readable on white.
 */
import { useMemo } from 'react'
import { resolveSpinnerColorOnWhite } from '../utils/colorUtils'

/**
 * @param {{ primary_color?: string|null, secondary_color?: string|null, accent_color?: string|null }|null|undefined} props.brand - `auth.activeBrand` slice
 * @param {string} [props.className] - Extra classes on the outer wrapper
 */
export default function InfiniteScrollGridSpinner({ brand = null, className = '' }) {
    const color = useMemo(
        () =>
            resolveSpinnerColorOnWhite(brand?.primary_color, {
                secondary: brand?.secondary_color,
                accent: brand?.accent_color,
            }),
        [brand?.primary_color, brand?.secondary_color, brand?.accent_color],
    )

    return (
        <div
            className={`flex justify-center py-6 ${className}`.trim()}
            role="status"
            aria-live="polite"
            aria-label="Loading more"
        >
            <svg
                className="h-8 w-8 animate-spin"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                aria-hidden="true"
                style={{ color }}
            >
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path
                    className="opacity-75"
                    fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                />
            </svg>
        </div>
    )
}
