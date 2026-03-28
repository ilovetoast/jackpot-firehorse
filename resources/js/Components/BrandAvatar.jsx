/**
 * Brand avatar: logo image, or initial letter on brand color (optional icon_bg_color override).
 */
import { useState } from 'react'
import { getContrastTextColor } from '../utils/colorUtils'

export default function BrandAvatar({
    logoPath,
    name,
    primaryColor = '#4f46e5',
    iconBgColor = null,
    size = 'md',
    className = '',
}) {
    const [imgError, setImgError] = useState(false)

    const firstLetter = name ? name.charAt(0).toUpperCase() : 'B'

    const sizeClasses = {
        sm: 'h-8 w-8 text-xs',
        md: 'h-10 w-10 text-sm',
        lg: 'h-12 w-12 text-base',
        xl: 'h-16 w-16 text-lg',
    }

    const sizeClass = sizeClasses[size] || size
    const baseClasses = 'flex items-center justify-center rounded-full flex-shrink-0'

    if (logoPath && !imgError) {
        return (
            <img
                src={logoPath}
                alt={name || 'Brand'}
                className={`${baseClasses} ${sizeClass} ${className} object-contain`}
                onError={() => setImgError(true)}
            />
        )
    }

    const bg = iconBgColor && iconBgColor.trim() !== '' ? iconBgColor : primaryColor
    const textColor = getContrastTextColor(bg)

    return (
        <div className={`${baseClasses} ${sizeClass} ${className}`} style={{ backgroundColor: bg }}>
            <span className="font-medium" style={{ color: textColor }}>
                {firstLetter}
            </span>
        </div>
    )
}
