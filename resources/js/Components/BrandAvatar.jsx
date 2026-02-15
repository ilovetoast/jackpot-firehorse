/**
 * BrandAvatar Component
 * Displays brand logo, icon, or initial fallback
 * 
 * @param {string} logoPath - URL to the brand's logo image
 * @param {string} iconPath - URL to the brand's uploaded icon image (alternative to logo)
 * @param {string} name - Brand name
 * @param {string} primaryColor - Brand primary color (for fallback)
 * @param {string} icon - Icon ID (for selectable icon)
 * @param {string} iconBgColor - Background color for icon (only used when showing icon, not logo)
 * @param {boolean} showIcon - Whether to show icon instead of logo/initial
 * @param {string} size - Size variant: 'sm', 'md', 'lg', 'xl' or custom className
 * @param {string} className - Additional CSS classes
 */
import { getContrastTextColor, getLuminance } from '../utils/colorUtils'
import { CategoryIcon } from '../Helpers/categoryIcons'

export default function BrandAvatar({ 
    logoPath, 
    iconPath = null,
    name, 
    primaryColor = '#4f46e5', // indigo-600 default
    icon = null,
    iconBgColor = '#6366f1',
    showIcon = false,
    size = 'md',
    className = '' 
}) {
    // Get first letter of brand name
    const firstLetter = name ? name.charAt(0).toUpperCase() : 'B'
    
    // Size classes mapping (matching Avatar component sizes)
    const sizeClasses = {
        sm: 'h-8 w-8 text-xs',
        md: 'h-10 w-10 text-sm',
        lg: 'h-12 w-12 text-base',
        xl: 'h-16 w-16 text-lg',
    }
    
    // Icon size mapping (smaller than container)
    const iconSizeClasses = {
        sm: 'h-4 w-4',
        md: 'h-5 w-5',
        lg: 'h-6 w-6',
        xl: 'h-8 w-8',
    }
    
    // If size is a custom className, use it; otherwise use size mapping
    const sizeClass = sizeClasses[size] || size
    const iconSizeClass = iconSizeClasses[size] || 'h-5 w-5'
    
    const baseClasses = 'flex items-center justify-center rounded-full flex-shrink-0'
    
    // If showIcon is true, prioritize showing icon
    if (showIcon) {
        // First check if there's an uploaded icon image (iconPath)
        if (iconPath) {
            // Show uploaded icon with background color if provided
            if (iconBgColor && iconBgColor.trim() !== '') {
                // Apply invert filter for icon image when background is light (luminance >= 0.5)
                const luminance = getLuminance(iconBgColor)
                const isLightBg = luminance >= 0.5
                return (
                    <div 
                        className={`${baseClasses} ${sizeClass} ${className}`}
                        style={{ backgroundColor: iconBgColor }}
                    >
                        <img
                            src={iconPath}
                            alt={name || 'Brand'}
                            className={`${iconSizeClass} object-contain`}
                            style={isLightBg ? { filter: 'brightness(0)' } : undefined}
                        />
                    </div>
                )
            }
            // Show uploaded icon without background (contain so full icon is visible)
            return (
                <img
                    src={iconPath}
                    alt={name || 'Brand'}
                    className={`${baseClasses} ${sizeClass} ${className} object-contain`}
                />
            )
        }
        
        // If no iconPath, check if there's a selectable icon (icon ID)
        if (icon) {
            // Only show background color if iconBgColor is explicitly set
            if (iconBgColor && iconBgColor.trim() !== '') {
                const iconColor = getContrastTextColor(iconBgColor)
                return (
                    <div 
                        className={`${baseClasses} ${sizeClass} ${className}`}
                        style={{ backgroundColor: iconBgColor }}
                    >
                        <CategoryIcon 
                            iconId={icon} 
                            className={iconSizeClass} 
                            style={{ color: iconColor }}
                        />
                    </div>
                )
            }
            // If no iconBgColor, show icon without background
            return (
                <div className={`${baseClasses} ${sizeClass} ${className}`}>
                    <CategoryIcon 
                        iconId={icon} 
                        className={iconSizeClass} 
                        color="text-gray-600"
                    />
                </div>
            )
        }

        // No icon uploaded yet — show initial with iconBgColor so Background Style preview updates live
        const bgColor = iconBgColor && iconBgColor.trim() !== '' ? iconBgColor : primaryColor
        const textColor = getContrastTextColor(bgColor)
        return (
            <div 
                className={`${baseClasses} ${sizeClass} ${className}`}
                style={{ backgroundColor: bgColor }}
            >
                <span className="font-medium" style={{ color: textColor }}>{firstLetter}</span>
            </div>
        )
    }
    
    // Show logo if available (when NOT using icon mode). Use contain so full logo is visible (same as graphics).
    // IMPORTANT: Do NOT add icon background color to logos
    if (logoPath) {
        return (
            <img
                src={logoPath}
                alt={name || 'Brand'}
                className={`${baseClasses} ${sizeClass} ${className} object-contain`}
            />
        )
    }
    
    // Fallback to initial with brand color
    const textColor = getContrastTextColor(primaryColor)
    
    return (
        <div 
            className={`${baseClasses} ${sizeClass} ${className}`}
            style={{ backgroundColor: primaryColor }}
        >
            <span className="font-medium" style={{ color: textColor }}>{firstLetter}</span>
        </div>
    )
}
