/**
 * OneSignal Web SDK (v16) — init, optional pre-dialog, then permission + server sync.
 * Requires {@see app.blade.php} to load OneSignalSDK.page.js and `window.OneSignalDeferred`.
 *
 * Future: email + push unified preferences; per-category device routing; multi-device subscription UI.
 */

const NS = '[JackpotPush]'

function log(...args) {
    if (typeof console !== 'undefined' && console.log) {
        console.log(NS, ...args)
    }
}

function logError(context, err) {
    const msg =
        err instanceof Error
            ? err.message
            : typeof err === 'string'
              ? err
              : (err && (err.message || err.reason)) || null
    log(context, msg || String(err), err instanceof Error ? err.stack : err)
}

let initPushInFlight = null

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

/**
 * Prefer Blade `onesignal-app-id` (from ONESIGNAL_APP_ID on the server) over Vite env.
 * `VITE_ONESIGNAL_APP_ID` is baked in at `npm run build`; if it pointed at an older OneSignal app
 * (e.g. Site URL still .co), the SDK would keep enforcing that origin even after dashboard + .env were fixed.
 */
function appId() {
    const fromMeta = document.querySelector('meta[name="onesignal-app-id"]')?.getAttribute('content')?.trim()
    if (fromMeta) {
        return fromMeta
    }
    const fromVite = import.meta.env.VITE_ONESIGNAL_APP_ID
    if (fromVite) {
        log('appId: using VITE_ONESIGNAL_APP_ID (no onesignal-app-id meta on this page)')
    }
    return fromVite || ''
}

function isPrivateLanIpv4(hostname) {
    return (
        /^192\.168\.\d{1,3}\.\d{1,3}$/.test(hostname) ||
        /^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(hostname) ||
        /^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/.test(hostname)
    )
}

/**
 * OneSignal v16 blocks non-HTTPS unless allowLocalhostAsSecureOrigin is true (SDK still validates origin).
 * Must cover: production build on http://localhost, LAN IPs, and explicit .env opt-in via Blade meta / Vite.
 */
function allowLocalhostAsSecureOriginOption() {
    if (typeof window === 'undefined') {
        return false
    }
    if (window.location.protocol === 'https:') {
        return false
    }
    // Explicit Vite override when APP_ENV isn’t "local" but you’re on HTTP (e.g. docker hostname)
    if (import.meta.env.VITE_ONESIGNAL_ALLOW_HTTP === 'true') {
        log('OneSignal allowLocalhostAsSecureOrigin: true (VITE_ONESIGNAL_ALLOW_HTTP)')
        return true
    }
    const meta = document.querySelector('meta[name="onesignal-allow-local-http"]')
    const metaRaw = meta?.getAttribute('content') ?? ''
    if (metaRaw === 'true' || metaRaw === '1') {
        log('OneSignal allowLocalhostAsSecureOrigin: true (Blade meta onesignal-allow-local-http)')
        return true
    }
    const h = window.location.hostname
    if (h === 'localhost' || h === '127.0.0.1' || h === '[::1]') {
        log('OneSignal allowLocalhostAsSecureOrigin: true (loopback hostname)', { hostname: h })
        return true
    }
    if (h.endsWith('.local') || h.endsWith('.test') || h.endsWith('.localhost')) {
        log('OneSignal allowLocalhostAsSecureOrigin: true (dev TLD)', { hostname: h })
        return true
    }
    if (isPrivateLanIpv4(h)) {
        log('OneSignal allowLocalhostAsSecureOrigin: true (private LAN IPv4)', { hostname: h })
        return true
    }
    if (import.meta.env.DEV) {
        log('OneSignal allowLocalhostAsSecureOrigin: true (Vite import.meta.env.DEV)')
        return true
    }
    log('OneSignal allowLocalhostAsSecureOrigin: false — set ONESIGNAL_ALLOW_HTTP_LOCAL=true, or VITE_ONESIGNAL_ALLOW_HTTP=true, or use HTTPS', {
        hostname: h,
        protocol: window.location.protocol,
        metaOnesignalAllowLocalHttp: metaRaw || '(missing)',
    })
    return false
}

async function postPushStatus(enabled) {
    log('postPushStatus', { enabled })
    const res = await fetch('/app/api/user/push-status', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ enabled }),
    })
    if (!res.ok) {
        const t = await res.text()
        throw new Error(t || `push-status ${res.status}`)
    }
    return res.json()
}

/**
 * Blade injects `window.OneSignalDeferred = []` only when push is enabled. If the meta tag is missing,
 * the SDK was never included — do not spin for 15s waiting for something that will never exist.
 */
async function waitForOneSignalDeferred(maxMs = 15000) {
    if (typeof window === 'undefined') {
        return false
    }
    const appIdMeta = document.querySelector('meta[name="onesignal-app-id"]')?.getAttribute('content')?.trim()
    if (!appIdMeta) {
        log('waitForOneSignalDeferred: skip — no onesignal-app-id meta (PUSH_NOTIFICATIONS_ENABLED / ONESIGNAL_APP_ID off on server)')
        return false
    }
    const start = Date.now()
    while (!window.OneSignalDeferred) {
        if (Date.now() - start > maxMs) {
            log('waitForOneSignalDeferred:timeout', { maxMs, hint: 'Script blocked or failed to load' })
            return false
        }
        await new Promise((r) => setTimeout(r, 50))
    }
    log('waitForOneSignalDeferred:ready')
    return true
}

function runOnOneSignal(callback) {
    if (typeof window === 'undefined' || !window.OneSignalDeferred) {
        log('runOnOneSignal:skipped', { reason: !window?.OneSignalDeferred ? 'no OneSignalDeferred' : 'no window' })
        return Promise.resolve()
    }
    log('runOnOneSignal:queued')
    return new Promise((resolve, reject) => {
        window.OneSignalDeferred.push(async function (OneSignal) {
            try {
                await callback(OneSignal)
                log('runOnOneSignal:done')
                resolve()
            } catch (e) {
                logError('runOnOneSignal:error', e)
                reject(e)
            }
        })
    })
}

/**
 * OneSignal.init must only succeed once per page. {@link initPush} runs on load; {@link requestPushPermission}
 * and {@link togglePush} run later — calling init again throws "SDK already initialized".
 */
async function safeOneSignalInit(OneSignal) {
    const id = appId()
    if (!id) {
        log('safeOneSignalInit:skip — no app id')
        return
    }
    const allowHttp = allowLocalhostAsSecureOriginOption()
    log('OneSignal.init calling', {
        appId: id,
        allowLocalhostAsSecureOrigin: allowHttp,
        href: typeof window !== 'undefined' ? window.location.href : '',
    })
    try {
        await OneSignal.init({
            appId: id,
            allowLocalhostAsSecureOrigin: allowHttp,
            autoPrompt: false,
            // Pin v16 worker at site root — stale SW from older SDK causes "Unrecognized operation: login-user".
            serviceWorkerPath: '/OneSignalSDKWorker.js',
            serviceWorkerParam: { scope: '/' },
        })
    } catch (e) {
        const msg = e?.message || String(e)
        if (/already initialized|SDK already initialized/i.test(msg)) {
            log('safeOneSignalInit: OK — already initialized (second init skipped)')
            return
        }
        throw e
    }
}

/**
 * Maps OneSignal / browser errors to short copy for modals and settings.
 */
export function formatPushUserError(err) {
    const msg = err?.message || String(err || '')
    if (/Can only be used on:/i.test(msg)) {
        return (
            'The browser URL doesn’t match the Web origin OneSignal has for this App ID (SDK reads it from their servers — changing the REST API key does not change that origin). ' +
            'In OneSignal, open Keys & IDs and confirm ONESIGNAL_APP_ID matches that app; set Site URL to this exact origin (https + host, e.g. .com not .co), save, wait a minute, hard-refresh. ' +
            'On the server run: php artisan onesignal:verify-app (set ONESIGNAL_ORGANIZATION_API_KEY if the command says 403).'
        )
    }
    if (/already initialized|SDK already initialized/i.test(msg)) {
        return null
    }
    return msg
}

/**
 * Associate Jackpot user with OneSignal (external_id = user_{id} for REST targeting).
 * Prefer {@link OneSignal.login} after init; fall back to User.addAlias if login op fails (SW/version edge cases).
 */
async function linkOneSignalExternalUser(OneSignal, userId) {
    const externalId = `user_${userId}`
    try {
        if (typeof OneSignal.login === 'function') {
            await OneSignal.login(externalId)
            log('linkOneSignalExternalUser: login OK', { externalId })
            return
        }
    } catch (e) {
        logError('linkOneSignalExternalUser: login failed', e)
    }
    try {
        if (OneSignal.User?.addAlias) {
            OneSignal.User.addAlias('external_id', externalId)
            log('linkOneSignalExternalUser: addAlias OK', { externalId })
            return
        }
    } catch (e2) {
        logError('linkOneSignalExternalUser: addAlias failed', e2)
        throw e2
    }
    throw new Error('OneSignal: login/addAlias unavailable')
}

function browserNotificationGranted() {
    return typeof Notification !== 'undefined' && Notification.permission === 'granted'
}

/**
 * Why the in-app consent modal may be hidden (browser prompt comes only after you tap Allow there).
 *
 * @returns {{ eligible: boolean, blockers: string[] }}
 */
export function getPushConsentModalEligibility(user) {
    const blockers = []
    if (!user?.id) {
        blockers.push('not signed in')
        return { eligible: false, blockers }
    }
    if (user.push_prompted_at != null && user.push_prompted_at !== '') {
        blockers.push(
            `users.push_prompted_at is set (${user.push_prompted_at}) — first-run dialog already completed in DB`
        )
    }
    if (typeof Notification === 'undefined') {
        blockers.push('Notification API unavailable')
    } else if (Notification.permission !== 'default') {
        blockers.push(
            `browser permission is "${Notification.permission}" (need "default" to show first-run flow; reset in site settings if testing)`
        )
    }
    return { eligible: blockers.length === 0, blockers }
}

/**
 * True when `users.push_prompted_at` is null (no consent recorded in DB) and the browser has not
 * chosen allow/deny yet. Drives the first-load site dialog — not the master toggle on settings.
 */
export function shouldShowPushPermissionDialog(user) {
    return getPushConsentModalEligibility(user).eligible
}

/**
 * OneSignal init + login for existing subscribers. Does not call requestPermission (dialog handles that).
 *
 * @returns {Promise<{ ready: boolean }>}
 */
export async function initPush(user) {
    if (!user?.id || typeof window === 'undefined') {
        log('initPush:skip', { reason: 'no user or window' })
        return { ready: false }
    }
    if (!(await waitForOneSignalDeferred())) {
        log('initPush:skip', { reason: 'OneSignal SDK not loaded (PUSH_NOTIFICATIONS_ENABLED / app id?)' })
        return { ready: false }
    }
    const id = appId()
    if (!id) {
        log('initPush:skip', { reason: 'no app id — set VITE_ONESIGNAL_APP_ID or Blade meta onesignal-app-id' })
        return { ready: false }
    }

    if (initPushInFlight) {
        log('initPush:dedupe', { waiting: true })
        return initPushInFlight
    }

    log('initPush:start', { userId: user.id, push_enabled: user.push_enabled, push_prompted_at: user.push_prompted_at })
    const eligibility = getPushConsentModalEligibility(user)
    log('initPush: consent modal eligibility (in-app “Stay in the loop”)', {
        willShowModalIfClientEnabled: eligibility.eligible,
        blockers: eligibility.blockers.length ? eligibility.blockers : '(none — modal can show)',
    })
    log(
        'initPush: note — OneSignal init does not open the browser permission dialog; that happens after you tap “Allow notifications” in our modal or on Settings.'
    )

    initPushInFlight = (async () => {
        await runOnOneSignal(async (OneSignal) => {
            await safeOneSignalInit(OneSignal)
            log('initPush:OneSignal init path complete')

            // Login before permission/subscription often yields POST /users 400 and "login-user" op errors in SW.
            if (user.push_enabled && browserNotificationGranted()) {
                try {
                    await linkOneSignalExternalUser(OneSignal, user.id)
                } catch (e) {
                    logError('initPush: link external user failed (non-fatal)', e)
                }
            } else {
                log('initPush:skip login', {
                    push_enabled: user.push_enabled,
                    browser_granted: browserNotificationGranted(),
                })
            }
        })

        return { ready: true }
    })()

    try {
        return await initPushInFlight
    } finally {
        initPushInFlight = null
    }
}

/**
 * After the user accepts the in-app explanation — native permission + server + OneSignal.login when granted.
 */
export async function requestPushPermission(user) {
    log('requestPushPermission:start', { userId: user?.id })
    if (!user?.id || typeof window === 'undefined') {
        return { granted: false }
    }
    if (!(await waitForOneSignalDeferred())) {
        log('requestPushPermission:abort', { reason: 'no SDK (timeout waiting for script)' })
        return { granted: false }
    }

    const id = appId()
    if (!id) {
        return { granted: false }
    }

    let granted = false

    await runOnOneSignal(async (OneSignal) => {
        await safeOneSignalInit(OneSignal)

        const before = typeof Notification !== 'undefined' ? Notification.permission : 'denied'
        log('requestPushPermission:before requestPermission', { permission: before })

        const req = await OneSignal.Notifications.requestPermission()
        granted = req === true || (typeof Notification !== 'undefined' && Notification.permission === 'granted')
        log('requestPushPermission:after', { requestResult: req, notificationPermission: Notification?.permission, granted })

        await postPushStatus(granted)

        if (granted) {
            try {
                await linkOneSignalExternalUser(OneSignal, user.id)
            } catch (e) {
                logError('requestPushPermission: link user failed (prefs saved)', e)
            }
        }
    })

    return { granted }
}

/**
 * User chose "Not now" — record consent outcome so we do not show the dialog every load.
 */
export async function dismissPushPermissionPrompt() {
    log('dismissPushPermissionPrompt')
    await postPushStatus(false)
}

/**
 * Settings: enable or disable push (opt-out on disable when SDK is available).
 */
export async function togglePush(user, enabled) {
    log('togglePush', { enabled, userId: user?.id })
    if (!user?.id || typeof window === 'undefined') {
        return
    }

    if (enabled && !(await waitForOneSignalDeferred())) {
        log('togglePush:abort', { reason: 'OneSignal not available on this page' })
        throw new Error(
            'Push isn’t available on this page yet — the app may not have browser notifications enabled. Reload after they’re turned on, or try again later.'
        )
    }

    if (!window.OneSignalDeferred) {
        if (!enabled) {
            await postPushStatus(false)
        } else {
            throw new Error(
                'Push isn’t available on this page yet — the app may not have browser notifications enabled. Reload after they’re turned on, or try again later.'
            )
        }
        return
    }

    await runOnOneSignal(async (OneSignal) => {
        await safeOneSignalInit(OneSignal)

        if (enabled) {
            const permission = typeof Notification !== 'undefined' ? Notification.permission : 'denied'
            let result = permission

            if (permission !== 'granted') {
                const req = await OneSignal.Notifications.requestPermission()
                result =
                    req === true || (typeof Notification !== 'undefined' && Notification.permission === 'granted')
                        ? 'granted'
                        : 'denied'
            }

            if (result === 'granted') {
                try {
                    await linkOneSignalExternalUser(OneSignal, user.id)
                } catch (e) {
                    logError('togglePush: link user failed', e)
                }
                await postPushStatus(true)
            }
        } else {
            try {
                if (OneSignal.User?.PushSubscription?.optOut) {
                    await OneSignal.User.PushSubscription.optOut()
                }
            } catch (e) {
                log('togglePush:optOut warning', e)
            }
            await postPushStatus(false)
        }
    })
}
