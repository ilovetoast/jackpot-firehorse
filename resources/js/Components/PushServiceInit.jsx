import { usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import PushPermissionDialog from './PushPermissionDialog'
import { initPush, shouldShowPushPermissionDialog } from '../services/pushService'

const NS = '[JackpotPush]'

/**
 * Runs on authenticated Inertia pages (see app.jsx).
 * Site dialog when DB has no consent yet (`push_prompted_at` null) + browser permission still "default".
 * Does NOT wait for OneSignal init — that race was hiding the dialog; SDK is waited for inside pushService.
 */
export default function PushServiceInit() {
    const page = usePage()
    const { auth, oneSignal } = page.props
    const user = auth?.user
    const enabled = Boolean(oneSignal?.client_enabled)

    useEffect(() => {
        if (!user?.id) {
            return
        }
        if (typeof console === 'undefined' || !console.log) {
            return
        }
        console.log(NS, 'PushServiceInit mounted (logged-in shell)', {
            url: page.url,
            client_enabled: enabled,
            oneSignal_app_id_set: Boolean(oneSignal?.app_id),
        })
        if (!enabled) {
            console.warn(
                NS,
                'Push is OFF: oneSignal.client_enabled is false. Set PUSH_NOTIFICATIONS_ENABLED=true and ONESIGNAL_APP_ID in .env, then reload.'
            )
        }
    }, [user?.id, page.url, enabled, oneSignal?.app_id])

    useEffect(() => {
        if (!enabled || !user?.id) {
            return
        }

        initPush(user)
    }, [enabled, user?.id, user?.push_prompted_at, user?.push_enabled])

    const showPrePrompt = Boolean(enabled && user && shouldShowPushPermissionDialog(user))

    useEffect(() => {
        if (showPrePrompt && typeof console !== 'undefined' && console.log) {
            console.log(NS, 'Showing PushPermissionDialog (DB: push_prompted_at not set, Notification.permission=default)', {
                push_prompted_at: user?.push_prompted_at,
            })
        }
    }, [showPrePrompt, user?.push_prompted_at])

    useEffect(() => {
        if (!user?.id) {
            return
        }
        if (typeof console === 'undefined' || !console.log) {
            return
        }
        console.log(NS, 'PushServiceInit state', {
            client_enabled: enabled,
            showPrePrompt,
            push_prompted_at: user.push_prompted_at,
            push_enabled: user.push_enabled,
            notificationPermission: typeof Notification !== 'undefined' ? Notification.permission : 'n/a',
        })
    }, [user?.id, user?.push_prompted_at, user?.push_enabled, enabled, showPrePrompt])

    return showPrePrompt ? <PushPermissionDialog user={user} /> : null
}
