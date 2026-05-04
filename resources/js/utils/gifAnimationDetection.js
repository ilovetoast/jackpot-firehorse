/**
 * Client-side GIF animation probe (no extra dependencies).
 * Used to show play/pause for animated GIFs while keeping a static pipeline thumbnail as the "poster".
 */

const animatedGifUrlCache = new Map()

/**
 * @param {ArrayBuffer} arrayBuffer
 * @returns {boolean}
 */
export function isAnimatedGifBuffer(arrayBuffer) {
    const dv = new DataView(arrayBuffer)
    const len = arrayBuffer.byteLength
    if (len < 14) return false
    const b = (i) => (i < len ? dv.getUint8(i) : 0)
    if (b(0) !== 0x47 || b(1) !== 0x49 || b(2) !== 0x46) return false
    if (b(3) !== 0x38 || b(5) !== 0x61) return false
    if (b(4) !== 0x39 && b(4) !== 0x37) return false

    let offset = 13
    const packedFields = dv.getUint8(10)
    if (packedFields & 0x80) {
        offset += 3 * (1 << ((packedFields & 0x07) + 1))
    }

    let frames = 0
    while (offset < len) {
        const block = dv.getUint8(offset)
        if (block === 0x3b) break
        if (block === 0x21) {
            offset++
            if (offset >= len) break
            offset++
            while (offset < len) {
                const sub = dv.getUint8(offset)
                offset++
                if (sub === 0) break
                offset += sub
            }
            continue
        }
        if (block === 0x2c) {
            frames++
            if (frames >= 2) return true
            offset++
            if (offset + 8 >= len) return false
            const imgPacked = dv.getUint8(offset + 8)
            offset += 9
            if (imgPacked & 0x80) {
                offset += 3 * (1 << ((imgPacked & 0x07) + 1))
            }
            if (offset >= len) return false
            offset++
            while (offset < len) {
                const sub = dv.getUint8(offset)
                offset++
                if (sub === 0) break
                offset += sub
            }
            continue
        }
        offset++
    }
    return false
}

/**
 * @param {string} url
 * @param {RequestInit} [init]
 * @returns {Promise<boolean>}
 */
export async function checkUrlIsAnimatedGif(url, init = {}) {
    if (!url || typeof url !== 'string') return false
    const trimmed = url.trim()
    if (!trimmed) return false
    if (animatedGifUrlCache.has(trimmed)) {
        return animatedGifUrlCache.get(trimmed)
    }

    try {
        const isAppRelative =
            typeof window !== 'undefined' &&
            (trimmed.startsWith('/') ||
                trimmed.startsWith(`${window.location.origin}/`) ||
                trimmed.startsWith(`${window.location.protocol}//${window.location.host}/`))

        const res = await fetch(trimmed, {
            ...init,
            credentials: isAppRelative ? 'same-origin' : 'omit',
            mode: 'cors',
            headers: { Accept: 'image/gif,*/*', ...init.headers },
        })
        if (!res.ok) {
            animatedGifUrlCache.set(trimmed, false)
            return false
        }
        const buf = await res.arrayBuffer()
        if (buf.byteLength > 25 * 1024 * 1024) {
            animatedGifUrlCache.set(trimmed, false)
            return false
        }
        const animated = isAnimatedGifBuffer(buf)
        animatedGifUrlCache.set(trimmed, animated)
        return animated
    } catch {
        animatedGifUrlCache.set(trimmed, false)
        return false
    }
}
