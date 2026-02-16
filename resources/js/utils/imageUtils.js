/**
 * Detect if an image is predominantly white
 * This function loads an image, samples its pixels, and determines if it's mostly white
 * Useful for determining if a logo/icon needs a grey background for visibility
 * 
 * @param {string} imageSrc - The image source (URL, blob URL, or data URL)
 * @param {number} threshold - Luminance threshold (0-1, default 0.9). Higher = more strict white detection
 * @param {number} samplePixels - Number of pixels to sample (default 1000). Higher = more accurate but slower
 * @returns {Promise<boolean>} - True if image is predominantly white
 */
/**
 * Check if the image source is likely SVG (cannot be sampled for pixel data).
 */
function isLikelySvg(imageSrc) {
    if (!imageSrc || typeof imageSrc !== 'string') return false
    const s = imageSrc.toLowerCase()
    return s.startsWith('data:image/svg+xml') || s.includes('.svg') || s.includes('image/svg')
}

export async function isImageWhite(imageSrc, threshold = 0.9, samplePixels = 1000) {
    // SVG and other vector formats cannot be sampled for pixel data; skip detection
    if (isLikelySvg(imageSrc)) {
        return false
    }

    return new Promise((resolve, reject) => {
        const img = new Image()
        
        // Only set crossOrigin for http(s) URLs - blob/data URLs work without it
        if (imageSrc.startsWith('http://') || imageSrc.startsWith('https://')) {
            img.crossOrigin = 'anonymous'
        }
        
        img.onload = () => {
            try {
                // Create canvas and draw image
                const canvas = document.createElement('canvas')
                const ctx = canvas.getContext('2d')
                
                if (!ctx) {
                    reject(new Error('Could not get canvas context'))
                    return
                }
                
                canvas.width = img.width
                canvas.height = img.height
                ctx.drawImage(img, 0, 0)
                
                // Sample pixels from the image
                const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
                const data = imageData.data
                
                // Sample pixels (strided sampling for performance)
                const pixelCount = data.length / 4 // Each pixel is 4 values (RGBA)
                const stride = Math.max(1, Math.floor(pixelCount / samplePixels))
                let whitePixels = 0
                let sampledCount = 0
                
                // Sample pixels
                for (let i = 0; i < data.length; i += 4 * stride) {
                    const r = data[i]
                    const g = data[i + 1]
                    const b = data[i + 2]
                    const a = data[i + 3]
                    
                    // Skip transparent pixels
                    if (a < 128) continue
                    
                    // Calculate luminance (using same formula as colorUtils)
                    const rLinear = r / 255 <= 0.03928 
                        ? (r / 255) / 12.92 
                        : Math.pow(((r / 255) + 0.055) / 1.055, 2.4)
                    const gLinear = g / 255 <= 0.03928 
                        ? (g / 255) / 12.92 
                        : Math.pow(((g / 255) + 0.055) / 1.055, 2.4)
                    const bLinear = b / 255 <= 0.03928 
                        ? (b / 255) / 12.92 
                        : Math.pow(((b / 255) + 0.055) / 1.055, 2.4)
                    
                    const luminance = 0.2126 * rLinear + 0.7152 * gLinear + 0.0722 * bLinear
                    
                    sampledCount++
                    
                    // Consider pixel white if luminance exceeds threshold
                    if (luminance >= threshold) {
                        whitePixels++
                    }
                }
                
                // Image is white if more than 70% of sampled pixels are white
                const whiteRatio = sampledCount > 0 ? whitePixels / sampledCount : 0
                resolve(whiteRatio >= 0.7)
            } catch (error) {
                reject(error)
            }
        }
        
        img.onerror = () => {
            reject(new Error('Failed to load image'))
        }
        
        img.src = imageSrc
    })
}

/**
 * Get the appropriate background style for an image based on whether it's white
 * Returns a grey background if the image is white, otherwise no background
 * 
 * @param {string} imageSrc - The image source (URL, blob URL, or data URL)
 * @param {string} backgroundColor - Background color to use if image is white (default: '#e5e7eb' - grey-200)
 * @returns {Promise<{background: string, isWhite: boolean}>} - Object with background style and white detection result
 */
export async function getImageBackgroundStyle(imageSrc, backgroundColor = '#e5e7eb') {
    if (!imageSrc) {
        return { background: 'transparent', isWhite: false }
    }
    // Skip detection for SVG - cannot sample pixels
    if (isLikelySvg(imageSrc)) {
        return { background: 'transparent', isWhite: false }
    }
    try {
        const isWhite = await isImageWhite(imageSrc)
        return {
            background: isWhite ? backgroundColor : 'transparent',
            isWhite,
        }
    } catch (error) {
        // Expected for CORS-blocked, SVG, or unloadable images - fail silently
        return {
            background: 'transparent',
            isWhite: false,
        }
    }
}

/**
 * Hook/component helper to get background style for an image with state management
 * This can be used in React components to automatically detect and set background
 * 
 * @param {string} imageSrc - The image source
 * @param {string} backgroundColor - Background color if white (default: '#e5e7eb')
 * @returns {Promise<{background: string, isLoading: boolean, isWhite: boolean}>}
 */
export async function getImageStyleWithDetection(imageSrc, backgroundColor = '#e5e7eb') {
    if (!imageSrc) {
        return {
            background: 'transparent',
            isLoading: false,
            isWhite: false,
        }
    }
    
    try {
        const result = await getImageBackgroundStyle(imageSrc, backgroundColor)
        return {
            ...result,
            isLoading: false,
        }
    } catch (error) {
        return {
            background: 'transparent',
            isLoading: false,
            isWhite: false,
        }
    }
}
