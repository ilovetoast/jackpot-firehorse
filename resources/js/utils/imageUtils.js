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

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const img = new Image()
        if (src.startsWith('http://') || src.startsWith('https://')) {
            img.crossOrigin = 'anonymous'
        }
        img.onload = () => resolve(img)
        img.onerror = () => reject(new Error('Failed to load image'))
        img.src = src
    })
}

/**
 * Generate a white (silhouette) variant of a logo image.
 * Converts all opaque pixels to white while preserving the alpha channel.
 * Works best on flat logos without gradients or photographs.
 *
 * @param {string} imageSrc - URL, blob URL, or data URL of the source image
 * @returns {Promise<Blob>} PNG blob of the white variant
 */
export async function generateWhiteVariant(imageSrc) {
    if (isLikelySvg(imageSrc)) {
        throw new Error('SVG logos cannot be converted via Canvas. Please upload a white version manually.')
    }
    const img = await loadImage(imageSrc)
    const canvas = document.createElement('canvas')
    canvas.width = img.naturalWidth
    canvas.height = img.naturalHeight
    const ctx = canvas.getContext('2d')
    ctx.drawImage(img, 0, 0)

    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
    const d = imageData.data
    for (let i = 0; i < d.length; i += 4) {
        if (d[i + 3] > 10) {
            d[i] = 255
            d[i + 1] = 255
            d[i + 2] = 255
        }
    }
    ctx.putImageData(imageData, 0, 0)

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => {
            if (blob) resolve(blob)
            else reject(new Error('Canvas toBlob failed'))
        }, 'image/png')
    })
}

/**
 * Analyse a logo to determine if it is "flat" (few solid colours) or "complex"
 * (gradients, photographs, many colour clusters).
 * Uses k-means-style colour bucketing on opaque pixels sampled at a small size.
 *
 * @param {string} imageSrc - URL, blob URL, or data URL
 * @returns {Promise<{ complexity: 'flat'|'complex', uniqueColors: number }>}
 */
export async function detectLogoComplexity(imageSrc) {
    if (isLikelySvg(imageSrc)) {
        return { complexity: 'flat', uniqueColors: 1 }
    }
    const img = await loadImage(imageSrc)
    const size = 48
    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size
    const ctx = canvas.getContext('2d')
    ctx.drawImage(img, 0, 0, size, size)

    const data = ctx.getImageData(0, 0, size, size).data
    const buckets = new Set()
    for (let i = 0; i < data.length; i += 4) {
        if (data[i + 3] < 64) continue
        const r = Math.round(data[i] / 32) * 32
        const g = Math.round(data[i + 1] / 32) * 32
        const b = Math.round(data[i + 2] / 32) * 32
        buckets.add(`${r},${g},${b}`)
    }

    const uniqueColors = buckets.size
    return {
        complexity: uniqueColors > 12 ? 'complex' : 'flat',
        uniqueColors,
    }
}
