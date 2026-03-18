import { useState, useEffect } from 'react'

const cache = new Map()

/**
 * Detects whether a logo image is predominantly dark (needs inversion on dark backgrounds).
 * Samples the image at a small size via canvas, computing average luminance of opaque pixels.
 * Returns: true = logo is dark and needs inversion, false = logo is light enough as-is.
 * Caches results by URL (minus query params/signatures) so detection runs once per logo.
 */
export default function useLogoBrightness(src) {
    const [needsInvert, setNeedsInvert] = useState(() => {
        if (!src) return false
        const key = cacheKey(src)
        return cache.has(key) ? cache.get(key) : false
    })

    useEffect(() => {
        if (!src) { setNeedsInvert(false); return }

        const key = cacheKey(src)
        if (cache.has(key)) {
            setNeedsInvert(cache.get(key))
            return
        }

        let cancelled = false
        const img = new Image()
        img.crossOrigin = 'anonymous'

        img.onload = () => {
            if (cancelled) return
            try {
                const canvas = document.createElement('canvas')
                const size = 24
                canvas.width = size
                canvas.height = size
                const ctx = canvas.getContext('2d')
                ctx.drawImage(img, 0, 0, size, size)

                const data = ctx.getImageData(0, 0, size, size).data
                let totalLum = 0
                let count = 0

                for (let i = 0; i < data.length; i += 4) {
                    if (data[i + 3] < 128) continue
                    totalLum += (0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]) / 255
                    count++
                }

                const avgLum = count > 0 ? totalLum / count : 0.5
                const dark = avgLum < 0.45
                cache.set(key, dark)
                if (!cancelled) setNeedsInvert(dark)
            } catch {
                cache.set(key, false)
                if (!cancelled) setNeedsInvert(false)
            }
        }

        img.onerror = () => {
            cache.set(key, false)
            if (!cancelled) setNeedsInvert(false)
        }

        img.src = src

        return () => { cancelled = true }
    }, [src])

    return needsInvert
}

function cacheKey(url) {
    if (!url) return ''
    try {
        const u = new URL(url, window.location.origin)
        return u.origin + u.pathname
    } catch {
        return url
    }
}
