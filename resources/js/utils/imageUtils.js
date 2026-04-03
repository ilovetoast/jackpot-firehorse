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

/**
 * Only same-origin http(s) images should use crossOrigin="anonymous".
 * CDN/S3/CloudFront thumbnails load fine in img tags without CORS, but anonymous CORS mode
 * requires Access-Control-Allow-Origin — missing headers spam Chrome Issues ("blocked:cors") while
 * the UI still works. Omitting crossOrigin for cross-origin URLs avoids that; canvas pixel reads
 * then hit a tainted-canvas SecurityError and callers treat as skipped.
 */
function shouldSetCrossOriginAnonymous(imageSrc) {
    if (!imageSrc.startsWith('http://') && !imageSrc.startsWith('https://')) {
        return false
    }
    if (typeof window === 'undefined' || !window.location?.origin) {
        return false
    }
    try {
        const u = new URL(imageSrc, window.location.href)

        return u.origin === window.location.origin
    } catch {
        return false
    }
}

export async function isImageWhite(imageSrc, threshold = 0.9, samplePixels = 1000) {
    // SVG and other vector formats cannot be sampled for pixel data; skip detection
    if (isLikelySvg(imageSrc)) {
        return false
    }

    return new Promise((resolve, reject) => {
        const img = new Image()
        
        if (shouldSetCrossOriginAnonymous(imageSrc)) {
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
        if (shouldSetCrossOriginAnonymous(src)) {
            img.crossOrigin = 'anonymous'
        }
        img.onload = () => resolve(img)
        img.onerror = () => reject(new Error('Failed to load image'))
        img.src = src
    })
}

/** WCAG relative luminance for sRGB 8-bit channels */
function relativeLuminance8bit(r, g, b) {
    const lin = (c) => {
        const x = c / 255
        return x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4
    }
    const R = lin(r)
    const G = lin(g)
    const B = lin(b)
    return 0.2126 * R + 0.7152 * G + 0.0722 * B
}

/** Contrast ratio of white (#fff) over a solid color with luminance L (L is the logo pixel on white bg) */
function contrastRatioOnWhiteBackground(L) {
    return (1 + 0.05) / (L + 0.05)
}

/**
 * Heuristic: primary logo may be unreadable on pure white (e.g. white wordmark, cream, very light grey).
 * Samples raster pixels; SVG returns skipped/ok.
 *
 * @returns {Promise<{ ok: boolean, lightFraction?: number, lowContrastFraction?: number, skipped?: boolean, reason?: string }>}
 */
export async function analyzeLogoLightOnWhiteRisk(imageSrc) {
    if (!imageSrc || typeof imageSrc !== 'string') {
        return { ok: true, skipped: true, reason: 'no-src' }
    }
    if (isLikelySvg(imageSrc)) {
        return { ok: true, skipped: true, reason: 'svg' }
    }
    try {
        const img = await loadImage(imageSrc)
        const maxSide = 72
        const scale = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight, 1))
        const w = Math.max(1, Math.round(img.naturalWidth * scale))
        const h = Math.max(1, Math.round(img.naturalHeight * scale))
        const canvas = document.createElement('canvas')
        canvas.width = w
        canvas.height = h
        const ctx = canvas.getContext('2d')
        if (!ctx) return { ok: true, skipped: true, reason: 'no-ctx' }
        ctx.drawImage(img, 0, 0, w, h)
        const data = ctx.getImageData(0, 0, w, h).data
        let opaque = 0
        let lightProblem = 0
        let lowContrast = 0
        for (let i = 0; i < data.length; i += 4) {
            if (data[i + 3] < 25) continue
            opaque++
            const L = relativeLuminance8bit(data[i], data[i + 1], data[i + 2])
            const cr = contrastRatioOnWhiteBackground(L)
            if (L > 0.86 || cr < 2.05) lightProblem++
            if (cr < 2.5) lowContrast++
        }
        if (opaque === 0) return { ok: true, skipped: true, reason: 'empty' }
        const lightFraction = lightProblem / opaque
        const lowContrastFraction = lowContrast / opaque
        const ok = lightFraction < 0.055 && lowContrastFraction < 0.1
        return {
            ok,
            lightFraction,
            lowContrastFraction,
            skipped: false,
        }
    } catch {
        return { ok: true, skipped: true, reason: 'error' }
    }
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
 * Parse #RGB or #RRGGBB into { r, g, b } or null.
 */
function parseHexRgb(hex) {
    if (!hex || typeof hex !== 'string') return null
    let h = hex.trim()
    if (h.startsWith('#')) h = h.slice(1)
    if (h.length === 3) {
        return {
            r: parseInt(h[0] + h[0], 16),
            g: parseInt(h[1] + h[1], 16),
            b: parseInt(h[2] + h[2], 16),
        }
    }
    if (h.length === 6) {
        return {
            r: parseInt(h.slice(0, 2), 16),
            g: parseInt(h.slice(2, 4), 16),
            b: parseInt(h.slice(4, 6), 16),
        }
    }
    return null
}

/**
 * Tint / "color wash" a logo: all non-transparent pixels become the given solid color (alpha preserved).
 * Useful for a dark-on-light variant when the raw primary mark does not read well on white.
 *
 * @param {string} imageSrc - URL, blob URL, or data URL of the source image
 * @param {string} hexColor - CSS hex e.g. #1e3a5f
 * @returns {Promise<Blob>} PNG blob
 */
export async function generatePrimaryColorWashVariant(imageSrc, hexColor) {
    const rgb = parseHexRgb(hexColor)
    if (!rgb || [rgb.r, rgb.g, rgb.b].some((n) => Number.isNaN(n))) {
        throw new Error('Set a valid primary brand color first.')
    }
    if (isLikelySvg(imageSrc)) {
        throw new Error('SVG logos cannot be converted via Canvas. Please upload a PNG/JPG version or add the variant manually.')
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
            d[i] = rgb.r
            d[i + 1] = rgb.g
            d[i + 2] = rgb.b
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
