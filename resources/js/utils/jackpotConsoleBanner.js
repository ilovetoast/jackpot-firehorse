/**
 * Jackpot console banner: slot-machine ASCII + release stamp.
 *
 * Stamp uses the server's release metadata when Laravel shares it:
 * `.release-info.json`, `APP_BUILD_*`, the deploy manifest (DEPLOYED_AT —
 * same source as the Admin Command Center), or `git log -1`. When a short
 * commit SHA is also available it is appended with a colon (e.g. `05112026:2114:c77c0c9a`).
 *
 * Falls back to this browser's local clock with a `local ·` prefix when no
 * server-side metadata is available (same `MMDDYYYY:HHMM` stamp, no `v:` / `UTC` labels).
 * We do NOT print a hint message in that case — the badge itself is the signal, and a verbose hint leaked through
 * to staging when the manifest fallback was missing (now fixed server-side).
 *
 * The version line is printed with %c (black background, white monospace text).
 */

function pad2(n) {
    return String(n).padStart(2, '0')
}

/** @deprecated Use {@link formatJackpotConsoleVersionLocal} — kept for any external imports. */
export function formatJackpotConsoleVersion(date = new Date()) {
    return formatJackpotConsoleVersionLocal(date)
}

/** Compact UTC stamp from an ISO-8601 instant (git %cI, release manifest, APP_BUILD_TIME). */
export function formatJackpotConsoleVersionUtc(date) {
    const mm = pad2(date.getUTCMonth() + 1)
    const dd = pad2(date.getUTCDate())
    const yyyy = String(date.getUTCFullYear())
    const hh = pad2(date.getUTCHours())
    const min = pad2(date.getUTCMinutes())
    return `${mm}${dd}${yyyy}:${hh}${min}`
}

/** Local wall-clock compact stamp (dev fallback when no deploy metadata). */
export function formatJackpotConsoleVersionLocal(date = new Date()) {
    const mm = pad2(date.getMonth() + 1)
    const dd = pad2(date.getDate())
    const yyyy = String(date.getFullYear())
    const hh = pad2(date.getHours())
    const min = pad2(date.getMinutes())
    return `${mm}${dd}${yyyy}:${hh}${min}`
}

export function formatVersionLabelFromCommitIso(iso) {
    if (!iso || typeof iso !== 'string') {
        return null
    }
    const d = new Date(iso.trim())
    if (Number.isNaN(d.getTime())) {
        return null
    }
    return formatJackpotConsoleVersionUtc(d)
}

const VERSION_BADGE_STYLE =
    'background:#000;color:#fff;font-weight:700;padding:4px 12px;border-radius:4px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:11px;letter-spacing:0.03em'

/**
 * Four “reels” like the JACKPOT wordmark: top J A C K, bottom P O T, last cell = cherry (@@).
 * Monospace only — tuned for ~62–72 column devtools.
 */
const BANNER_LINES = [
    '',
    '                         *  J A C K  ·  P O T  *',
    '                 77777777777777777777777777777777777777777777777',
    '                 +---------+ +---------+ +---------+ +---------+',
    '                 |    J    | |    A    | |    C    | |    K    |',
    '                 |    P    | |    O    | |    T    | |   @@    |',
    '                 +---------+ +---------+ +---------+ +---------+',
    '                 77777777777777777777777777777777777777777777777',
    '                            @ @   777   @ @',
    '',
]

let cachedCommitIso = null
let cachedCommitSha = null

/**
 * Whitelist a short SHA so we never echo arbitrary server input verbatim into
 * the badge — keeps the console clean and immune to a typo in a deploy
 * manifest sneaking ANSI codes / very long strings into the styled log line.
 *
 * @param {unknown} raw
 * @returns {string|null}
 */
export function sanitizeCommitSha(raw) {
    if (typeof raw !== 'string') return null
    const trimmed = raw.trim()
    if (trimmed.length < 7 || trimmed.length > 40) return null
    if (!/^[0-9a-f]+$/i.test(trimmed)) return null
    return trimmed.slice(0, 8).toLowerCase()
}

/**
 * Decide what the version badge should say.
 *
 * @param {{ commitIso8601?: string|null, commitSha?: string|null }|null} sharedPayload
 * @param {string|null} cachedIso
 * @param {string|null} cachedSha
 * @returns {{ badgeText: string, releaseString: string, sha: string|null }}
 */
export function buildVersionBadge(sharedPayload, cachedIso = null, cachedSha = null) {
    const iso = sharedPayload?.commitIso8601 ?? cachedIso ?? null
    const sha = sanitizeCommitSha(sharedPayload?.commitSha) ?? cachedSha ?? null

    if (iso) {
        const label = formatVersionLabelFromCommitIso(iso)
        if (label) {
            const tail = sha ? `:${sha}` : ''
            return {
                badgeText: `  ${label}${tail}  `,
                releaseString: `${label}${tail}`,
                sha,
            }
        }
    }
    const local = formatJackpotConsoleVersionLocal()
    return {
        badgeText: `  local · ${local}  `,
        releaseString: `local · ${local}`,
        sha: null,
    }
}

function resolveVersionBadge(sharedPayload) {
    const out = buildVersionBadge(sharedPayload, cachedCommitIso, cachedCommitSha)
    if (sharedPayload?.commitIso8601) cachedCommitIso = sharedPayload.commitIso8601
    if (out.sha) cachedCommitSha = out.sha
    return out
}

function getJpReleaseString() {
    return buildVersionBadge(null, cachedCommitIso, cachedCommitSha).releaseString
}

if (typeof window !== 'undefined') {
    try {
        Object.defineProperty(window, '__jp_release', {
            get: () => getJpReleaseString(),
            enumerable: true,
            configurable: true,
        })
    } catch {
        window.__jp_release = getJpReleaseString()
    }
}

export function logJackpotConsoleBanner(sharedPayload = null) {
    if (typeof console === 'undefined' || typeof window === 'undefined') {
        return
    }

    for (const line of BANNER_LINES) {
        console.log(line)
    }

    const { badgeText } = resolveVersionBadge(sharedPayload)
    console.log('%c' + badgeText, VERSION_BADGE_STYLE)
}

/**
 * While logged in: print banner on every Inertia navigation (every “page”).
 */
export function maybeLogJackpotConsoleBanner(pageProps) {
    if (typeof window === 'undefined' || !pageProps || typeof pageProps !== 'object') {
        return
    }
    if (!('auth' in pageProps)) {
        return
    }

    const user = pageProps.auth?.user
    if (!user) {
        return
    }

    logJackpotConsoleBanner(pageProps.jackpotConsole ?? null)
}
