import { usePage } from '@inertiajs/react'

/** Inverted wordmark for dark UIs when no tenant/brand (gateway default). */
export const JACKPOT_WORDMARK_INVERTED_SRC = '/jp-wordmark-inverted.svg'

export default function LogoMark({ name, logo, size = 'md', className = '' }) {
    const { theme } = usePage().props
    const resolvedName = name || theme?.name || 'Jackpot'
    // LogoMark paints inside a dark-tinted gradient pill (primary@CC → primary@55) with
    // white text — it's a dark surface. Prefer the dark variant from the theme, then the
    // primary as fallback. Callers may override via the explicit `logo` prop.
    const resolvedLogo = logo ?? theme?.logo_dark ?? theme?.logo
    const primary = theme?.colors?.primary || '#6366f1'
    const letter = resolvedName.charAt(0).toUpperCase()

    const sizes = {
        sm: { pill: 'h-8 w-8 rounded-lg', icon: 'text-sm', img: 'h-5', text: 'text-sm', wordmark: 'h-7 sm:h-8' },
        md: { pill: 'h-10 w-10 rounded-lg', icon: 'text-base', img: 'h-6', text: 'text-base', wordmark: 'h-8 sm:h-9' },
        lg: { pill: 'h-14 w-14 rounded-xl', icon: 'text-xl', img: 'h-8', text: 'text-lg', wordmark: 'h-10 sm:h-11' },
    }

    const s = sizes[size] || sizes.md

    if (theme?.mode === 'default') {
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

    return (
        <div className={`flex items-center gap-3 ${className}`}>
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
            <span className={`${s.text} font-semibold tracking-tight text-white`}>
                {resolvedName}
            </span>
        </div>
    )
}
