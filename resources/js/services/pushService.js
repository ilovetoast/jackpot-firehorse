/**
 * OneSignal Web SDK (v16) — init, one-time browser permission after login, and settings toggle.
 * Requires {@see app.blade.php} to load OneSignalSDK.page.js and `window.OneSignalDeferred`.
 *
 * Future: email + push unified preferences; per-category device routing; multi-device subscription UI.
 */

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

function runOnOneSignal(callback) {
    if (typeof window === 'undefined' || !window.OneSignalDeferred) {
        return Promise.resolve()
    }
    return new Promise((resolve, reject) => {
        window.OneSignalDeferred.push(async function (OneSignal) {
            try {
                await callback(OneSignal)
                resolve()
            } catch (e) {
                reject(e)
            }
        })
    })
}

/**
 * Call once when a user session is active and push is enabled server-side.
 * One-time browser permission if never prompted; sync login when push_enabled.
 *
 * @returns {Promise<{ didPrompt: boolean }>}
 */
export async function initPush(user) {
    if (!user?.id || typeof window === 'undefined') {
        return { didPrompt: false }
    }
    if (!window.OneSignalDeferred) {
        return { didPrompt: false }
    }
    const id = appId()
    if (!id) {
        console.warn('[pushService] No OneSignal app id (VITE_ONESIGNAL_APP_ID or meta onesignal-app-id)')
        return { didPrompt: false }
    }

    if (initPushInFlight) {
        return initPushInFlight
    }

    initPushInFlight = (async () => {
        let didPrompt = false

        await runOnOneSignal(async (OneSignal) => {
            await OneSignal.init({
                appId: id,
                allowLocalhostAsSecureOrigin: import.meta.env.DEV,
                autoPrompt: false,
            })

            if (user.push_enabled) {
                await OneSignal.login(`user_${user.id}`)
            }

            const permission = typeof Notification !== 'undefined' ? Notification.permission : 'denied'

            if (!user.push_prompted_at && permission === 'default') {
                didPrompt = true
                const req = await OneSignal.Notifications.requestPermission()
                const granted =
                    req === true || (typeof Notification !== 'undefined' && Notification.permission === 'granted')

                await postPushStatus(granted)

                if (granted) {
                    await OneSignal.login(`user_${user.id}`)
                }
            }
        })

        return { didPrompt }
    })()

    try {
        return await initPushInFlight
    } finally {
        initPushInFlight = null
    }
}

/**
 * Settings: enable or disable push (opt-out on disable when SDK is available).
 */
export async function togglePush(user, enabled) {
    if (!user?.id || typeof window === 'undefined') {
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
                console.warn('[pushService] optOut', e)
            }
            await postPushStatus(false)
        }
    })
}
