/**
 * Single-asset "tracked" download (POST → JSON with delivery URL).
 * Prefer streaming through the app (?stream=1) with Content-Disposition: attachment — never navigate
 * to CDN URLs (they inline images) or to public_url (landing page).
 */

/** @param {string} url */
export function ensureStreamQueryOnDeliverFileUrl(url) {
    if (!url || typeof url !== 'string') return url
    try {
        const u = new URL(url, window.location.origin)
        const path = (u.pathname || '').replace(/\/+$/, '')
        if (!/\/d\/[^/]+\/file$/i.test(path)) {
            return url
        }
        u.searchParams.set('stream', '1')
        return u.toString()
    } catch {
        return url
    }
}

/**
 * Resolve the GET URL that streams the file with attachment (same-origin).
 * @param {{ file_url?: string, public_url?: string, download_url?: string, download_id?: string|number }} data
 */
export function resolveTrackedSingleAssetFileUrl(data) {
    let fileUrl = typeof data?.file_url === 'string' && data.file_url.length > 0 ? data.file_url : null
    if (fileUrl) {
        return ensureStreamQueryOnDeliverFileUrl(fileUrl)
    }
    let id = data?.download_id
    if (id == null || String(id) === '') {
        const pub = typeof data?.public_url === 'string' ? data.public_url : ''
        const m = pub.match(/\/d\/([0-9a-f-]{36})\/?$/i)
        if (m) {
            id = m[1]
        }
    }
    if (id != null && String(id).length > 0) {
        if (typeof route !== 'undefined') {
            try {
                return ensureStreamQueryOnDeliverFileUrl(
                    route('downloads.public.file', { download: String(id), stream: 1 }),
                )
            } catch {
                /* fall through */
            }
        }
        const origin = typeof window !== 'undefined' ? window.location.origin : ''
        return `${origin}/d/${encodeURIComponent(String(id))}/file?stream=1`
    }
    const fallback = typeof data?.download_url === 'string' && data.download_url ? data.download_url : null
    return fallback ? ensureStreamQueryOnDeliverFileUrl(fallback) : null
}

/**
 * Parse filename from Content-Disposition (RFC 5987 filename* or quoted filename).
 * @param {string|null} header
 */
function filenameFromContentDisposition(header, fallback) {
    if (!header || typeof header !== 'string') return fallback
    const star = /filename\*\s*=\s*UTF-8''([^;\s]+)/i.exec(header)
    if (star) {
        try {
            const v = decodeURIComponent(star[1].trim())
            if (v) return v.replace(/[/\\]/g, '_')
        } catch {
            /* ignore */
        }
    }
    const quoted = /filename\s*=\s*"((?:\\.|[^"\\])*)"/i.exec(header)
    if (quoted) {
        const v = quoted[1].replace(/\\(.)/g, '$1').trim()
        if (v) return v.replace(/[/\\]/g, '_')
    }
    const plain = /filename\s*=\s*([^;\s]+)/i.exec(header)
    if (plain) {
        const v = plain[1].replace(/^["']|["']$/g, '').trim()
        if (v) return v.replace(/[/\\]/g, '_')
    }
    return fallback
}

/**
 * GET same-origin delivery URL and save as a file (no full-page navigation; works when CDN would inline).
 * @param {string} fileUrl
 * @param {string} [fallbackFilename]
 */
export async function saveUrlAsDownload(fileUrl, fallbackFilename = 'download') {
    const res = await fetch(fileUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: '*/*' },
    })
    if (!res.ok) {
        const err = new Error(`Download failed (${res.status})`)
        err.status = res.status
        throw err
    }
    const headerName = res.headers.get('Content-Disposition')
    const filename = filenameFromContentDisposition(headerName, fallbackFilename)
    const blob = await res.blob()
    const objectUrl = URL.createObjectURL(blob)
    try {
        const a = document.createElement('a')
        a.href = objectUrl
        a.download = filename
        a.rel = 'noopener'
        a.style.display = 'none'
        document.body.appendChild(a)
        a.click()
        a.remove()
    } finally {
        URL.revokeObjectURL(objectUrl)
    }
}
