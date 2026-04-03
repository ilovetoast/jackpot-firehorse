/**
 * 90s BBS / ANSI-art style console welcome — fires once per login session (see maybeLogJackpotConsoleBanner).
 */

const SESSION_KEY = 'jackpot_console_logged_uid'

/** Fallback when sessionStorage is blocked (private mode). */
let memoryLoggedUid = null

const BANNER_LINES = [
    '░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░',
    '░░                                                                ░░',
    '░░  ███████╗ █████╗  ██████╗██╗  ██╗██████╗  ██████╗ ████████╗  ░░',
    '░░  ██╔════╝██╔══██╗██╔════╝██║ ██╔╝██╔══██╗██╔═══██╗╚══██╔══╝  ░░',
    '░░  ███████╗███████║██║     █████╔╝ ██████╔╝██║   ██║   ██║     ░░',
    '░░  ╚════██║██╔══██║██║     ██╔═██╗ ██╔═══╝ ██║   ██║   ██║     ░░',
    '░░  ███████║██║  ██║╚██████╗██║  ██╗██║     ╚██████╔╝   ██║     ░░',
    '░░  ╚══════╝╚═╝  ╚═╝ ╚═════╝╚═╝  ╚═╝╚═╝      ╚═════╝    ╚═╝     ░░',
    '░░                                                                ░░',
    '░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░',
]

const TAGLINE = '  · · ·  WELCOME TO JACKPOT  · · ·  '

const STYLES = {
    frame:
        'color: #9333ea; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; line-height: 1.12; font-weight: bold;',
    logo: 'color: #22d3ee; font-weight: bold; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; line-height: 1.12; text-shadow: 0 0 8px #06b6d4, 0 0 2px #f0abfc;',
    logoHot:
        'color: #f472b6; font-weight: bold; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 11px; line-height: 1.12; text-shadow: 0 0 6px #db2777;',
    tagline:
        'color: #fde047; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; font-weight: bold; letter-spacing: 0.12em; text-shadow: 1px 1px 0 #ca8a04;',
    sub:
        'color: #4ade80; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 10px; font-style: italic;',
}

export function logJackpotConsoleBanner() {
    if (typeof console === 'undefined' || typeof window === 'undefined') {
        return
    }

    let glyphRow = 0
    for (const line of BANNER_LINES) {
        if (!line.includes('█')) {
            console.log(`%c${line}`, STYLES.frame)
            continue
        }
        console.log(`%c${line}`, glyphRow % 2 === 0 ? STYLES.logo : STYLES.logoHot)
        glyphRow += 1
    }
    console.log(`%c${TAGLINE}`, STYLES.tagline)
    console.log('%c  // *** SESSION OK ***  zmodem not required  ·  56k certified', STYLES.sub)
}

/**
 * Show banner when user is authenticated; clear session marker when logged out.
 * Same browser tab: once per login (cleared on logout so re-login shows again).
 */
export function maybeLogJackpotConsoleBanner(pageProps) {
    if (typeof window === 'undefined' || !pageProps || typeof pageProps !== 'object') {
        return
    }
    // Avoid clearing session when finish fires before props are available (no shared `auth` yet).
    if (!('auth' in pageProps)) {
        return
    }

    const user = pageProps.auth?.user
    if (!user) {
        memoryLoggedUid = null
        try {
            sessionStorage.removeItem(SESSION_KEY)
        } catch {
            /* private mode / blocked storage */
        }
        return
    }

    const uid = String(user.id)
    let stored = null
    try {
        stored = sessionStorage.getItem(SESSION_KEY)
    } catch {
        /* ignore */
    }
    if (stored === uid || memoryLoggedUid === uid) {
        return
    }
    try {
        sessionStorage.setItem(SESSION_KEY, uid)
    } catch {
        /* ignore */
    }
    memoryLoggedUid = uid

    logJackpotConsoleBanner()
}
