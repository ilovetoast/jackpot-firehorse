import { useState } from 'react'
import { resolveBrandIconBackground } from '../utils/colorUtils'

const SIZES = {
    xs: { container: 'h-6 w-6', text: 'text-[10px]', radius: 'rounded-md' },
    sm: { container: 'h-8 w-8', text: 'text-xs', radius: 'rounded-lg' },
    md: { container: 'h-10 w-10', text: 'text-sm', radius: 'rounded-lg' },
    lg: { container: 'h-12 w-12', text: 'text-base', radius: 'rounded-xl' },
    xl: { container: 'h-14 w-14', text: 'text-lg', radius: 'rounded-xl' },
    '2xl': { container: 'h-20 w-20', text: 'text-2xl', radius: 'rounded-2xl' },
}

/**
 * Unified brand tile: logo (inverted on gradient) or first letter — used in brand selector, nav, overview.
 *
 * @param {object} brand - primary_color, secondary_color, icon_style, name, logo_path
 */
export default function BrandIconUnified({ brand, size = 'md', variant = 'gradient', className = '' }) {
    const [imgError, setImgError] = useState(false)

    const primary = brand?.primary_color || '#6366f1'
    const secondary = brand?.secondary_color || '#8b5cf6'
    const iconStyle = brand?.icon_style || 'subtle'
    const name = brand?.name || ''
    const firstLetter = name.charAt(0).toUpperCase() || 'B'
    const logoPath = brand?.logo_path

    const s = SIZES[size] || SIZES.md
    const radius = variant === 'circle' ? 'rounded-full' : s.radius
    const base = `flex items-center justify-center flex-shrink-0 overflow-hidden ${s.container} ${radius} ${className}`
    const bg = resolveBrandIconBackground(iconStyle, primary, secondary)

    if (logoPath && !imgError) {
        return (
            <div className={base} style={{ background: bg }}>
                <img
                    src={logoPath}
                    alt={name}
                    className="h-3/4 w-3/4 object-contain"
                    style={{ filter: 'brightness(0) invert(1)' }}
                    onError={() => setImgError(true)}
                />
            </div>
        )
    }

    return (
        <div className={base} style={{ background: bg }}>
            <span className={`font-bold text-white ${s.text}`}>{firstLetter}</span>
        </div>
    )
}
