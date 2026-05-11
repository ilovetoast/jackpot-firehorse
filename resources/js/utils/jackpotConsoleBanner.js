/**
 * Jackpot console banner: slot-machine ASCII + deploy stamp.
 *
 * Stamp uses the latest git commit time (ISO-8601) when Laravel shares it:
 * `.release-info.json`, `APP_BUILD_TIME` (see config/jackpot_console.php), or `git log -1`
 * on local/staging when `.git` exists. Otherwise falls back to this browser’s local clock
 * and prefixes `local ·` in the badge.
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
    return `v:${mm}${dd}${yyyy}:${hh}${min}`
}

/** Local wall-clock compact stamp (dev fallback when no deploy metadata). */
export function formatJackpotConsoleVersionLocal(date = new Date()) {
    const mm = pad2(date.getMonth() + 1)
    const dd = pad2(date.getDate())
    const yyyy = String(date.getFullYear())
    const hh = pad2(date.getHours())
    const min = pad2(date.getMinutes())
    return `v:${mm}${dd}${yyyy}:${hh}${min}`
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

const LOCAL_HINT_STYLE = 'color:#6b7280;font-size:10px;margin-top:2px'

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

function resolveVersionBadge(sharedPayload) {
    const iso = sharedPayload?.commitIso8601 ?? cachedCommitIso
    if (iso) {
        cachedCommitIso = iso
        const label = formatVersionLabelFromCommitIso(iso)
        if (label) {
            return { badgeText: `  ${label} UTC  `, hint: null }
        }
    }
    const local = formatJackpotConsoleVersionLocal()
    return {
        badgeText: `  local · ${local}  `,
        hint: 'No commit time from the server — using your computer’s clock. Set APP_BUILD_TIME or .release-info.json on deploy.',
    }
}

function getJpReleaseString() {
    if (cachedCommitIso) {
        const label = formatVersionLabelFromCommitIso(cachedCommitIso)
        if (label) {
            return `${label} UTC`
        }
    }
    return `local · ${formatJackpotConsoleVersionLocal()}`
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

    const { badgeText, hint } = resolveVersionBadge(sharedPayload)
    console.log('%c' + badgeText, VERSION_BADGE_STYLE)
    if (hint) {
        console.log('%c' + hint, LOCAL_HINT_STYLE)
    }
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
