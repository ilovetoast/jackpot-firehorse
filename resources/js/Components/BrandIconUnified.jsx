import { useState } from 'react'
import {
    blendHex,
    normalizeHexColor,
    getSolidFillButtonForegroundHex,
    resolveBrandIconBackground,
} from '../utils/colorUtils'
import { getBrandLogoForSurface, hasDedicatedVariantForSurface } from '../utils/brandLogo'

const SIZES = {
    xs: { container: 'h-6 w-6', text: 'text-[10px]', radius: 'rounded-md' },
    sm: { container: 'h-8 w-8', text: 'text-xs', radius: 'rounded-lg' },
    md: { container: 'h-10 w-10', text: 'text-sm', radius: 'rounded-lg' },
    lg: { container: 'h-12 w-12', text: 'text-base', radius: 'rounded-xl' },
    xl: { container: 'h-14 w-14', text: 'text-lg', radius: 'rounded-xl' },
    /** Gateway workspace tiles — prominent mark without tenant purple chrome. */
    tile: { container: 'h-16 w-16 sm:h-[4.5rem] sm:w-[4.5rem]', text: 'text-lg sm:text-xl', radius: 'rounded-2xl' },
    '2xl': { container: 'h-20 w-20', text: 'text-2xl', radius: 'rounded-2xl' },
    /** Gateway workspace picker cards — large mark; see BrandSelector WorkspaceCard. */
    'gateway-ws': { container: 'h-[6.5rem] w-[6.5rem]', text: 'text-2xl', radius: 'rounded-2xl' },
    'gateway-ws-compact': { container: 'h-[5.5rem] w-[5.5rem]', text: 'text-xl', radius: 'rounded-2xl' },
}

const GATEWAY_TILE_BG =
    'linear-gradient(155deg, rgba(52,48,44,0.98) 0%, rgba(28,26,23,0.98) 48%, rgba(18,16,14,1) 100%)'

/**
 * Unified brand tile: logo (inverted on gradient) or first letter — used in brand selector, nav, overview.
 *
 * @param {object} brand - primary_color, secondary_color, icon_style, name, logo_path, settings.nav_display_mode
 * @param {'brand' | 'gateway'} [palette='brand'] — `gateway`: neutral vault surface (no purple tenant gradient).
 * @param {'auto'|'logo'|'monogram'} [markMode='auto'] — `auto`: use brand `settings.nav_display_mode` (logo vs text/monogram); `logo` / `monogram` override.
 */
export default function BrandIconUnified({ brand, size = 'md', variant = 'gradient', className = '', palette = 'brand', markMode = 'auto' }) {
    const [imgError, setImgError] = useState(false)

    const isGateway = palette === 'gateway'

    const primary = brand?.primary_color || '#6366f1'
    const secondary = brand?.secondary_color || '#8b5cf6'
    const iconStyle = brand?.icon_style || 'subtle'
    const iconBgColor = typeof brand?.icon_bg_color === 'string' ? brand.icon_bg_color.trim() : ''
    const pHex = normalizeHexColor(primary)
    const sHex = normalizeHexColor(secondary)
    const monogramSurface = iconBgColor
        ? normalizeHexColor(iconBgColor)
        : iconStyle === 'solid'
          ? pHex
          : blendHex(pHex, sHex, 0.5)
    const monogramTextColor = isGateway
        ? '#f2ead8'
        : getSolidFillButtonForegroundHex(monogramSurface)
    const name = brand?.name || ''
    const firstLetter = name.charAt(0).toUpperCase() || 'B'
    const navDisplayMode = brand?.settings?.nav_display_mode
    const useMonogramTile =
        markMode !== 'logo'
        && (markMode === 'monogram' || (markMode === 'auto' && navDisplayMode === 'text'))
    const logoPath = useMonogramTile ? null : getBrandLogoForSurface(brand, 'dark')
    const dedicatedDarkLogo = Boolean(logoPath) && hasDedicatedVariantForSurface(brand, 'dark')

    const s = SIZES[size] || SIZES.md
    const radius = variant === 'circle' ? 'rounded-full' : s.radius
    const base = `flex items-center justify-center flex-shrink-0 overflow-hidden ${s.container} ${radius} ${className}`
    const bg = isGateway
        ? GATEWAY_TILE_BG
        : iconBgColor
          ? iconBgColor
          : resolveBrandIconBackground(iconStyle, primary, secondary)
    const tileShadow = isGateway ? { boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.07), 0 8px 24px rgba(0,0,0,0.35)' } : {}
    /** Dark-surface logos are already light — inverting them hides the mark on tinted tiles. */
    const logoFilterStyle = isGateway || dedicatedDarkLogo ? undefined : { filter: 'brightness(0) invert(1)' }

    if (logoPath && !imgError) {
        return (
            <div className={base} style={{ background: bg, ...tileShadow }}>
                <img
                    src={logoPath}
                    alt={name}
                    className={isGateway ? 'h-[76%] w-[76%] object-contain drop-shadow-[0_2px_10px_rgba(0,0,0,0.5)]' : 'h-3/4 w-3/4 object-contain'}
                    style={logoFilterStyle}
                    onError={() => setImgError(true)}
                />
            </div>
        )
    }

    return (
        <div className={base} style={{ background: bg, ...tileShadow }}>
            <span className={`font-bold ${s.text} tracking-tight`} style={{ color: monogramTextColor }}>
                {firstLetter}
            </span>
        </div>
    )
}
