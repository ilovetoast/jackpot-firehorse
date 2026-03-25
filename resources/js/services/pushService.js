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

let initPushInFlight = null

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

function appId() {
    return (
        import.meta.env.VITE_ONESIGNAL_APP_ID ||
        document.querySelector('meta[name="onesignal-app-id"]')?.getAttribute('content') ||
        ''
    )
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
 * Blade loads OneSignal with `defer`; React often runs before `window.OneSignalDeferred` exists.
 */
async function waitForOneSignalDeferred(maxMs = 15000) {
    if (typeof window === 'undefined') {
        return false
    }
    const start = Date.now()
    while (!window.OneSignalDeferred) {
        if (Date.now() - start > maxMs) {
            log('waitForOneSignalDeferred:timeout', { maxMs })
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
                log('runOnOneSignal:error', e)
                reject(e)
            }
        })
    })
}

/**
 * True when `users.push_prompted_at` is null (no consent recorded in DB) and the browser has not
 * chosen allow/deny yet. Drives the first-load site dialog — not the master toggle on settings.
 */
export function shouldShowPushPermissionDialog(user) {
    if (!user?.id) {
        return false
    }
    if (user.push_prompted_at != null && user.push_prompted_at !== '') {
        return false
    }
    if (typeof Notification === 'undefined') {
        return false
    }
    return Notification.permission === 'default'
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

    initPushInFlight = (async () => {
        await runOnOneSignal(async (OneSignal) => {
            await OneSignal.init({
                appId: id,
                allowLocalhostAsSecureOrigin: import.meta.env.DEV,
                autoPrompt: false,
            })
            log('initPush:OneSignal.init complete')

            if (user.push_enabled) {
                await OneSignal.login(`user_${user.id}`)
                log('initPush:OneSignal.login (push_enabled)', { externalId: `user_${user.id}` })
            } else {
                log('initPush:skip login until user enables (push_enabled false)')
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
        await OneSignal.init({
            appId: id,
            allowLocalhostAsSecureOrigin: import.meta.env.DEV,
            autoPrompt: false,
        })

        const before = typeof Notification !== 'undefined' ? Notification.permission : 'denied'
        log('requestPushPermission:before requestPermission', { permission: before })

        const req = await OneSignal.Notifications.requestPermission()
        granted = req === true || (typeof Notification !== 'undefined' && Notification.permission === 'granted')
        log('requestPushPermission:after', { requestResult: req, notificationPermission: Notification?.permission, granted })

        await postPushStatus(granted)

        if (granted) {
            await OneSignal.login(`user_${user.id}`)
            log('requestPushPermission:OneSignal.login', { externalId: `user_${user.id}` })
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
        log('togglePush:abort', { reason: 'no SDK (timeout)' })
        return
    }

    if (!window.OneSignalDeferred) {
        if (!enabled) {
            await postPushStatus(false)
        }
        return
    }

    await runOnOneSignal(async (OneSignal) => {
        const id = appId()
        if (id) {
            await OneSignal.init({
                appId: id,
                allowLocalhostAsSecureOrigin: import.meta.env.DEV,
                autoPrompt: false,
            })
        }

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
                await OneSignal.login(`user_${user.id}`)
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
