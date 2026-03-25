import { usePage } from '@inertiajs/react'
import { useEffect } from 'react'
import PushPermissionDialog from './PushPermissionDialog'
import { initPush, shouldShowPushPermissionDialog } from '../services/pushService'

const NS = '[JackpotPush]'

/**
 * First-time consent dialog when the server has push enabled and the user hasn’t completed this flow yet.
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
        console.log(NS, 'PushServiceInit mounted', {
            url: page.url,
            client_enabled: enabled,
        })
    }, [user?.id, page.url, enabled])

    useEffect(() => {
        if (!enabled || !user?.id) {
            return
        }

        initPush(user)
    }, [enabled, user?.id, user?.push_prompted_at, user?.push_enabled])

    const eligibleForConsent = Boolean(user && shouldShowPushPermissionDialog(user))
    const showPrePrompt = Boolean(enabled && eligibleForConsent)

    useEffect(() => {
        if (showPrePrompt && typeof console !== 'undefined' && console.log) {
            console.log(NS, 'Showing notification consent dialog')
        }
    }, [showPrePrompt])

    return showPrePrompt ? <PushPermissionDialog user={user} /> : null
}
