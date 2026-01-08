/**
 * BrandAvatar Component
 * Displays brand logo or initial fallback
 * 
 * @param {string} logoPath - URL to the brand's logo image
 * @param {string} name - Brand name
 * @param {string} primaryColor - Brand primary color (for fallback)
 * @param {string} size - Size variant: 'sm', 'md', 'lg', 'xl' or custom className
 * @param {string} className - Additional CSS classes
 */
import { getContrastTextColor } from '../utils/colorUtils'

export default function BrandAvatar({ 
    logoPath, 
    name, 
    primaryColor = '#4f46e5', // indigo-600 default
    size = 'md',
    className = '' 
}) {
    // Get first letter of brand name
    const firstLetter = name ? name.charAt(0).toUpperCase() : 'B'
    
    // Size classes mapping
    const sizeClasses = {
        sm: 'h-6 w-6 text-xs',
        md: 'h-8 w-8 text-sm',
        lg: 'h-10 w-10 text-base',
        xl: 'h-12 w-12 text-lg',
    }
    
    // If size is a custom className, use it; otherwise use size mapping
    const sizeClass = sizeClasses[size] || size
    
    const baseClasses = 'flex items-center justify-center rounded-full flex-shrink-0'
    
    if (logoPath) {
        return (
            <img
                src={logoPath}
                alt={name || 'Brand'}
                className={`${baseClasses} ${sizeClass} ${className} object-cover`}
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
