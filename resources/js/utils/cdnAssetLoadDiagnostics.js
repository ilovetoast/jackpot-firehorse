/**
 * Best-effort CDN diagnostics for &lt;model-viewer&gt;, &lt;audio&gt;, and similar loads where the
 * element's `error` event does not include HTTP status. Uses fetch + CORS (credentials omitted)
 * so signed URLs work.
 *
 * Never log or return full URLs with signing query params — strip for display only.
 */

/**
 * DevTools-only CDN / media diagnostics (never show this payload in the product UI).
 *
 * @param {string} surface e.g. 'model-viewer' | 'audio'
 * @param {Record<string, unknown>} payload — must not include signed query strings or cookies
 */
export function logCdnMediaDiagnostics(surface, payload) {
    if (typeof console === 'undefined' || typeof console.warn !== 'function') {
        return
    }
    try {
        console.warn(`[Jackpot CDN:${surface}]`, payload)
    } catch {
        // ignore
    }
}

/**
 * @param {string} url
 * @returns {boolean}
 */
export function isProbablyCloudFrontSignedUrl(url) {
    if (typeof url !== 'string' || !url) return false
    return (
        /[?&]Signature=/.test(url) ||
        /[?&]Key-Pair-Id=/.test(url) ||
        /[?&]Policy=/.test(url) ||
        /[?&]Expires=\d+/.test(url)
    )
}

/**
 * Origin + path only (no query) — safe to show in debug UI.
 * @param {string} url
 * @param {string} [base]
 * @returns {string}
 */
export function cdnUrlForDisplayWithoutQuery(url, base) {
    try {
        const u = new URL(url, base || 'https://invalid.invalid')
        return `${u.origin}${u.pathname}`
    } catch {
        return '(invalid url)'
    }
}

/**
 * @param {object} asset
 * @param {string} modelSrc
 * @returns {'preview_3d_glb'|'original'|'unknown'}
 */
export function inferGlbDeliveryVariant(asset, modelSrc) {
    const pv = typeof asset?.preview_3d_viewer_url === 'string' ? asset.preview_3d_viewer_url.trim() : ''
    if (pv && modelSrc) {
        try {
            const a = new URL(pv, 'https://invalid.invalid')
            const b = new URL(modelSrc, 'https://invalid.invalid')
            if (a.origin === b.origin && a.pathname === b.pathname) {
                return 'preview_3d_glb'
            }
        } catch {
            // fall through
        }
    }
    if (typeof asset?.original === 'string' && asset.original && modelSrc) {
        try {
            const a = new URL(asset.original, 'https://invalid.invalid')
            const b = new URL(modelSrc, 'https://invalid.invalid')
            if (a.origin === b.origin && a.pathname === b.pathname) {
                return 'original'
            }
        } catch {
            // fall through
        }
    }
    return 'unknown'
}

/**
 * @param {object} asset
 * @param {string} audioUrl
 * @returns {'audio_web'|'original'|'preview_url'|'unknown'}
 */
export function inferAudioDeliveryVariant(asset, audioUrl) {
    const tryMatch = (candidate) => {
        if (typeof candidate !== 'string' || !candidate.trim() || !audioUrl) return false
        try {
            const a = new URL(candidate.trim(), 'https://invalid.invalid')
            const b = new URL(audioUrl, 'https://invalid.invalid')
            return a.origin === b.origin && a.pathname === b.pathname
        } catch {
            return false
        }
    }
    if (tryMatch(asset?.audio_playback_url)) return 'audio_web'
    if (tryMatch(asset?.original_url)) return 'original'
    if (tryMatch(asset?.preview_url)) return 'preview_url'
    return 'unknown'
}

/**
 * @param {'unauthorized'|'not_found'|'cors_or_unknown'|'viewer_failed_ok_head'|'generic'|'network'|'autoplay'|'media_decode'|'src_not_supported'|'play_rejected'} category
 * @param {number|null|undefined} httpStatus
 * @returns {{ primary: string, secondary?: string|null }}
 */
export function getCdnPreviewFailureCopy(category, httpStatus) {
    switch (category) {
        case 'autoplay':
            return {
                primary: 'The browser blocked starting audio automatically. Tap play again to listen.',
            }
        case 'media_decode':
            return {
                primary: 'This file could not be decoded for playback. You can still download the original.',
            }
        case 'src_not_supported':
            return {
                primary:
                    'The browser could not use this audio source. It may be blocked (authorization/CORS), unsupported, or damaged.',
            }
        case 'play_rejected':
            return {
                primary: 'Playback failed to start. Try again, or download the original if the problem continues.',
            }
        case 'unauthorized': {
            const label =
                httpStatus === 401
                    ? '401 Unauthorized'
                    : httpStatus === 403
                      ? '403 Forbidden'
                      : httpStatus != null
                        ? String(httpStatus)
                        : '403 Forbidden'
            return {
                primary: `The CDN returned ${label}. The preview URL may be missing authorization, expired, or CloudFront cannot read the object.`,
                secondary:
                    'Signed CloudFront URLs or cookies may be absent or invalid. Try Retry or download the original.',
            }
        }
        case 'not_found':
            return {
                primary:
                    'This asset was not found at the expected CDN path (404). The file may be missing, deleted, or stored under a different key.',
            }
        case 'cors_or_unknown':
            return {
                primary:
                    'This asset is served from a different host than the app, and the browser blocked access (often a CORS or network issue).',
                secondary: 'Check CloudFront response headers policy for CORS on this app origin.',
            }
        case 'viewer_failed_ok_head':
            return {
                primary: 'This file could not be previewed in the 3D viewer. You can still download the original.',
            }
        case 'network':
            return {
                primary: 'The preview URL could not be reached. Check your connection and try again.',
            }
        case 'generic':
        default:
            return {
                primary: 'This preview could not be loaded. Try Retry or download the original.',
            }
    }
}

/**
 * @param {{ category: string, httpStatus?: number|null }|null} probe
 * @param {number|null|undefined} mediaErrorCode HTMLMediaElement.error?.code, or null for play() failures
 * @returns {string}
 */
export function classifyAudioPlaybackFailure(probe, mediaErrorCode) {
    if (probe === null) {
        return 'cors_or_unknown'
    }
    if (!probe) {
        return 'cors_or_unknown'
    }
    if (probe.category === 'unauthorized') return 'unauthorized'
    if (probe.category === 'not_found') return 'not_found'
    if (probe.category === 'cors_or_unknown') return 'cors_or_unknown'
    if (probe.category === 'network') return 'network'
    if (probe.category === 'generic') return 'generic'
    if (probe.category === 'ok') {
        if (mediaErrorCode === 3) return 'media_decode'
        if (mediaErrorCode === 4) return 'src_not_supported'
        if (mediaErrorCode === 2) return 'play_rejected'
        return 'play_rejected'
    }
    return 'generic'
}

/**
 * @param {string} url
 * @param {{ signal?: AbortSignal }} [options]
 * @returns {Promise<{
 *   category: 'unauthorized'|'not_found'|'cors_or_unknown'|'ok'|'generic'|'network',
 *   httpStatus: number|null,
 *   modelHost: string,
 *   pageOrigin: string,
 *   urlIsSignedGuess: boolean,
 *   pathnameForLog: string,
 *   fetchFailed?: boolean
 * }>}
 */
export async function probeCdnAssetAvailability(url, options = {}) {
    const { signal } = options
    const pageOrigin = typeof window !== 'undefined' ? window.location.origin : ''
    let modelHost = ''
    let pathnameForLog = ''
    try {
        const u = new URL(url, pageOrigin || 'https://invalid.invalid')
        modelHost = u.host
        pathnameForLog = u.pathname
    } catch {
        return {
            category: 'network',
            httpStatus: null,
            modelHost: '',
            pageOrigin,
            urlIsSignedGuess: false,
            pathnameForLog: '',
            fetchFailed: true,
        }
    }

    const urlIsSignedGuess = isProbablyCloudFrontSignedUrl(url)

    const attempt = async (method) =>
        fetch(url, {
            method,
            mode: 'cors',
            credentials: 'omit',
            cache: 'no-store',
            signal,
            ...(method === 'GET' ? { headers: { Range: 'bytes=0-0' } } : {}),
        })

    try {
        let res = await attempt('HEAD')
        if (res.status === 405 || res.status === 501) {
            res = await attempt('GET')
        }
        const s = res.status
        if (s === 401 || s === 403) {
            return {
                category: 'unauthorized',
                httpStatus: s,
                modelHost,
                pageOrigin,
                urlIsSignedGuess,
                pathnameForLog,
            }
        }
        if (s === 404) {
            return {
                category: 'not_found',
                httpStatus: 404,
                modelHost,
                pageOrigin,
                urlIsSignedGuess,
                pathnameForLog,
            }
        }
        if (s >= 200 && s < 300) {
            return {
                category: 'ok',
                httpStatus: s,
                modelHost,
                pageOrigin,
                urlIsSignedGuess,
                pathnameForLog,
            }
        }
        return {
            category: 'generic',
            httpStatus: s,
            modelHost,
            pageOrigin,
            urlIsSignedGuess,
            pathnameForLog,
        }
    } catch (e) {
        if (signal?.aborted || (e && e.name === 'AbortError')) {
            return null
        }
        return {
            category: 'cors_or_unknown',
            httpStatus: null,
            modelHost,
            pageOrigin,
            urlIsSignedGuess,
            pathnameForLog,
            fetchFailed: true,
        }
    }
}
