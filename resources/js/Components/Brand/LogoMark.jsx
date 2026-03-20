import { usePage } from '@inertiajs/react'

export default function LogoMark({ name, logo, size = 'md', className = '' }) {
    const { theme } = usePage().props
    const resolvedName = name || theme?.name || 'Jackpot'
    const resolvedLogo = logo ?? theme?.logo
    const primary = theme?.colors?.primary || '#6366f1'
    const letter = resolvedName.charAt(0).toUpperCase()

    const sizes = {
        sm: { pill: 'h-8 w-8 rounded-lg', icon: 'text-sm', img: 'h-5', text: 'text-sm' },
        md: { pill: 'h-10 w-10 rounded-lg', icon: 'text-base', img: 'h-6', text: 'text-base' },
        lg: { pill: 'h-14 w-14 rounded-xl', icon: 'text-xl', img: 'h-8', text: 'text-lg' },
    }

    const s = sizes[size] || sizes.md

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
