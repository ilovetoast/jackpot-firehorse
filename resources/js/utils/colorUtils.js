/**
 * Calculate the relative luminance of a color using WCAG formula
 * @param {string} hexColor - Hex color string (e.g., "#FF0000" or "#f00")
 * @returns {number} Luminance value between 0 and 1
 */
export function getLuminance(hexColor) {
    if (!hexColor) return 0.5 // Default to medium if no color provided
    
    // Remove # if present
    let hex = hexColor.replace('#', '')
    
    // Convert 3-digit hex to 6-digit
    if (hex.length === 3) {
        hex = hex.split('').map(char => char + char).join('')
    }
    
    // Convert to RGB
    const r = parseInt(hex.substring(0, 2), 16) / 255
    const g = parseInt(hex.substring(2, 4), 16) / 255
    const b = parseInt(hex.substring(4, 6), 16) / 255
    
    // Apply gamma correction
    const [rLinear, gLinear, bLinear] = [r, g, b].map(val => {
        return val <= 0.03928 ? val / 12.92 : Math.pow((val + 0.055) / 1.055, 2.4)
    })
    
    // Calculate relative luminance (WCAG formula)
    return 0.2126 * rLinear + 0.7152 * gLinear + 0.0722 * bLinear
}

/**
 * Get the workspace button/accent color based on workspace_button_style setting.
 * Used for Add Asset button and primary actions in DAM (Assets, Deliverables, Collections).
 * @param {Object} brand - Brand object with workspace_button_style, primary_color, secondary_color, accent_color
 * @returns {string} Hex color for the workspace primary action
 */
export function getWorkspaceButtonColor(brand) {
    if (!brand) return '#6366f1'
    const style = brand.workspace_button_style ?? 'primary'
    if (style === 'primary') return brand.primary_color || '#6366f1'
    if (style === 'secondary') return brand.secondary_color || '#64748b'
    return brand.accent_color || '#6366f1' // accent
}

/**
 * Convert hex color to rgba string with given opacity.
 * @param {string} hexColor - Hex color (e.g. "#6366f1" or "6366f1")
 * @param {number} alpha - Opacity 0-1 (e.g. 0.25 for 25%)
 * @returns {string} rgba(r, g, b, alpha)
 */
export function hexToRgba(hexColor, alpha = 1) {
    if (!hexColor) return `rgba(99, 102, 241, ${alpha})` // indigo fallback
    let hex = String(hexColor).replace('#', '')
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('')
    const r = parseInt(hex.substring(0, 2), 16)
    const g = parseInt(hex.substring(2, 4), 16)
    const b = parseInt(hex.substring(4, 6), 16)
    return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

/**
 * Darken a hex color by subtracting from each RGB channel.
 * Matches the Add Execution / Add Asset button hover behavior.
 * @param {string} hexColor - Hex color (e.g. "#6366f1")
 * @param {number} amount - Amount to subtract from each channel (default 20)
 * @returns {string} Darkened hex color
 */
export function darkenColor(hexColor, amount = 20) {
    if (!hexColor) return '#4f46e5'
    let hex = String(hexColor).replace('#', '')
    if (hex.length === 3) hex = hex.split('').map(c => c + c).join('')
    let r = Math.max(0, parseInt(hex.substring(0, 2), 16) - amount)
    let g = Math.max(0, parseInt(hex.substring(2, 4), 16) - amount)
    let b = Math.max(0, parseInt(hex.substring(4, 6), 16) - amount)
    return `#${r.toString(16).padStart(2, '0')}${g.toString(16).padStart(2, '0')}${b.toString(16).padStart(2, '0')}`
}

/**
 * Get appropriate text color (white or black) based on background color
 * Uses WCAG contrast ratio guidelines - returns white for dark backgrounds, black for light
 * @param {string} backgroundColor - Hex color string
 * @returns {string} '#ffffff' for dark backgrounds, '#000000' for light backgrounds
 */
export function getContrastTextColor(backgroundColor) {
    if (!backgroundColor) return '#ffffff' // Default to white if no color
    
    const luminance = getLuminance(backgroundColor)
    // If luminance is less than 0.5, it's a dark color, use white text
    // If luminance is 0.5 or greater, it's a light color, use black text
    return luminance < 0.5 ? '#ffffff' : '#000000'
}
