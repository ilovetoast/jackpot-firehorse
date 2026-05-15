import { usePage } from '@inertiajs/react'

/** Inverted wordmark for dark UIs when no tenant/brand (gateway default). */
export const JACKPOT_WORDMARK_INVERTED_SRC = '/jp-wordmark-inverted.svg'

export default function LogoMark({
    name,
    logo,
    size = 'md',
    className = '',
    /** When set, the mark is wrapped in an Inertia link (e.g. gateway → `/`). */
    href = null,
    linkAriaLabel = 'Jackpot home',
    /** Force the Jackpot wordmark (e.g. multi-brand picker uses a neutral canvas). */
    forceJackpotWordmark = false,
}) {
    const { theme } = usePage().props
    const resolvedName = name || theme?.name || 'Jackpot'
    // LogoMark paints inside a dark-tinted gradient pill (primary@CC → primary@55) with
    // white text — it's a dark surface. Prefer the dark variant from the theme, then the
    // primary as fallback. Callers may override via the explicit `logo` prop.
    const resolvedLogo = logo ?? theme?.logo_dark ?? theme?.logo
    const primary = theme?.colors?.primary || '#7c3aed'
    const letter = resolvedName.charAt(0).toUpperCase()
    const hideAdjacentName = Boolean(theme?.single_brand_tenant && resolvedLogo)

    const sizes = {
        sm: { pill: 'h-8 w-8 rounded-lg', icon: 'text-sm', img: 'h-5', text: 'text-sm', wordmark: 'h-7 sm:h-8' },
        md: { pill: 'h-10 w-10 rounded-lg', icon: 'text-base', img: 'h-6', text: 'text-base', wordmark: 'h-8 sm:h-9' },
        lg: { pill: 'h-14 w-14 rounded-xl', icon: 'text-xl', img: 'h-8', text: 'text-lg', wordmark: 'h-10 sm:h-11' },
    }

    const s = sizes[size] || sizes.md

    const defaultMark = (
        <div className="flex items-center">
            <img
                src={JACKPOT_WORDMARK_INVERTED_SRC}
                alt={href ? '' : 'Jackpot'}
                className={`${s.wordmark} w-auto max-w-[min(100%,12rem)]`}
                decoding="async"
            />
        </div>
    )

    const brandedRow = (extraClass = '') => (
        <div className={`flex items-center gap-3 ${extraClass}`.trim()}>
            <div
                className={`${s.pill} flex items-center justify-center`}
                style={{
                    background: `linear-gradient(135deg, ${primary}CC, ${primary}55)`,
                }}
            >
                {resolvedLogo ? (
                    <img
                        src={resolvedLogo}
                        alt={resolvedName}
                        className={`${s.img} object-contain`}
                    />
                ) : (
                    <span className={`${s.icon} font-bold text-white`}>
                        {letter}
                    </span>
                )}
            </div>
            {!hideAdjacentName && (
                <span className={`${s.text} font-semibold tracking-tight text-white`}>{resolvedName}</span>
            )}
        </div>
    )

    const useJackpotMark = forceJackpotWordmark || theme?.mode === 'default'
    const inner = useJackpotMark ? defaultMark : brandedRow('')

    if (href) {
        // Use a native <a> so the ?marketing_site=1 param reaches the PHP middleware
        // and sets the session bypass flag before the redirect strips the param.
        return (
            <a
                href={href}
                className={`inline-flex items-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-white/35 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0B0B0D] ${className}`.trim()}
                aria-label={linkAriaLabel}
            >
                {inner}
            </a>
        )
    }

    if (useJackpotMark) {
        return (
            <div className={`flex items-center ${className}`}>
                <img
                    src={JACKPOT_WORDMARK_INVERTED_SRC}
                    alt="Jackpot"
                    className={`${s.wordmark} w-auto max-w-[min(100%,12rem)]`}
                    decoding="async"
                />
            </div>
        )
    }

    return brandedRow(className)
}
