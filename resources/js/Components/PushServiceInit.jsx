import { usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import PushPermissionDialog from './PushPermissionDialog'
import { initPush, shouldShowPushPermissionDialog } from '../services/pushService'

const NS = '[JackpotPush]'

/**
 * Runs after login: OneSignal init; optional pre-dialog before browser permission + server sync.
 */
export default function PushServiceInit() {
    const page = usePage()
    const { auth, oneSignal } = page.props
    const user = auth?.user
    const enabled = oneSignal?.client_enabled
    const cancelledRef = useRef(false)
    const [sdkReady, setSdkReady] = useState(false)

    useEffect(() => {
        cancelledRef.current = false
        if (!enabled || !user?.id) {
            setSdkReady(false)
            return
        }

        initPush(user).then((result) => {
            if (cancelledRef.current) {
                return
            }
            if (result?.ready) {
                setSdkReady(true)
            }
        })

        return () => {
            cancelledRef.current = true
        }
    }, [enabled, user?.id, user?.push_prompted_at, user?.push_enabled])

    const showPrePrompt = Boolean(enabled && sdkReady && user && shouldShowPushPermissionDialog(user))

    useEffect(() => {
        if (!enabled || !user?.id) {
            return
        }
        if (typeof console !== 'undefined' && console.log) {
            console.log(NS, 'PushServiceInit state', {
                client_enabled: enabled,
                sdkReady,
                showPrePrompt,
                push_prompted_at: user.push_prompted_at,
                push_enabled: user.push_enabled,
                notificationPermission: typeof Notification !== 'undefined' ? Notification.permission : 'n/a',
            })
        }
    }, [enabled, user?.id, user?.push_prompted_at, user?.push_enabled, sdkReady, showPrePrompt])

    return showPrePrompt ? <PushPermissionDialog user={user} /> : null
}
