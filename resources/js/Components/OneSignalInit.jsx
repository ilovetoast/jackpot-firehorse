import { usePage } from '@inertiajs/react'
import { useEffect } from 'react'

/**
 * SDK load + init live in app.blade.php. This component only links the logged-in user to
 * OneSignal external_id so the REST API can target them (must match PushChannel: user_{id}).
 */
export default function OneSignalInit() {
    const page = usePage()
    const { auth, oneSignal } = page.props
    const appId = oneSignal?.app_id
    const enabled = oneSignal?.client_enabled
    const userId = auth?.user?.id

    useEffect(() => {
        if (!enabled || !appId || !userId) {
            return
        }

        window.OneSignalDeferred = window.OneSignalDeferred || []
        window.OneSignalDeferred.push(async function (OneSignal) {
            await OneSignal.login(`user_${userId}`)
        })
    }, [enabled, appId, userId])

    return null
}
