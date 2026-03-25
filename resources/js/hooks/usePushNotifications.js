import { useCallback, useMemo, useState } from 'react'
import { usePage, router } from '@inertiajs/react'

/**
 * OneSignal web push onboarding (soft prompt → user-initiated browser permission).
 *
 * Future: extend with a /settings/notifications page for per-channel toggles (email / push / in-app)
 * and merge tenant-level defaults or overrides from the server before calling OneSignal.
 */

function getBrowserNotificationPermission() {
    if (typeof window === 'undefined' || typeof Notification === 'undefined') {
        return 'unsupported'
    }
    return Notification.permission
}

async function postPushPreference(payload) {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
    const res = await fetch('/app/api/user/push-preference', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    })
    if (!res.ok) {
        const text = await res.text()
        throw new Error(text || `HTTP ${res.status}`)
    }
    return res.json()
}

function reloadAuth() {
    router.reload({ preserveScroll: true })
}

export function usePushNotifications() {
    const { auth, oneSignal } = usePage().props
    const user = auth?.user
    const clientEnabled = Boolean(oneSignal?.client_enabled)
    const [busy, setBusy] = useState(false)

    const showPrompt = useMemo(() => {
        if (!clientEnabled || !user?.id) {
            return false
        }
        // Treat null / undefined / '' as "never prompted" (omit key before migration = undefined).
        if (user.push_prompted_at) {
            return false
        }
        const perm = getBrowserNotificationPermission()
        if (perm !== 'default') {
            return false
        }
        return true
    }, [clientEnabled, user?.id, user?.push_prompted_at])

    const dismissNotNow = useCallback(async () => {
        if (busy) {
            return
        }
        setBusy(true)
        try {
            await postPushPreference({ prompted: true })
            reloadAuth()
        } catch (e) {
            console.error('[PushPrompt] dismiss failed', e)
        } finally {
            setBusy(false)
        }
    }, [busy])

    const enablePush = useCallback(async () => {
        if (busy) {
            return
        }
        setBusy(true)
        try {
            if (typeof window === 'undefined' || !window.OneSignalDeferred) {
                await postPushPreference({ prompted: true })
                reloadAuth()
                return
            }

            await new Promise((resolve, reject) => {
                window.OneSignalDeferred.push(async function (OneSignal) {
                    try {
                        await OneSignal.Notifications.requestPermission()
                    } catch (err) {
                        console.warn('[PushPrompt] requestPermission', err)
                    }

                    const perm =
                        typeof Notification !== 'undefined' ? Notification.permission : 'denied'

                    if (perm === 'granted') {
                        try {
                            await OneSignal.login(`user_${user.id}`)
                        } catch (err) {
                            console.warn('[PushPrompt] login', err)
                        }
                        await postPushPreference({ enabled: true, prompted: true })
                    } else {
                        await postPushPreference({ enabled: false, prompted: true })
                    }
                    resolve()
                })
            })

            reloadAuth()
        } catch (e) {
            console.error('[PushPrompt] enablePush failed', e)
        } finally {
            setBusy(false)
        }
    }, [busy, user?.id])

    return {
        showPrompt,
        enablePush,
        dismissNotNow,
        busy,
    }
}
