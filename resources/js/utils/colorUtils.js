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
