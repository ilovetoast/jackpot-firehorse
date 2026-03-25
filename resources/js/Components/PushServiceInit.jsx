import { usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import PushPermissionDialog from './PushPermissionDialog'
import { initPush, shouldShowPushPermissionDialog } from '../services/pushService'

const NS = '[JackpotPush]'
const UNAVAILABLE_DISMISS_KEY = 'jackpot_push_unavailable_notice_dismissed'

/**
 * First-time consent dialog when the server enables browser push (`oneSignal.client_enabled`).
 * If push isn’t enabled server-side, a short user-facing notice explains why there’s no prompt yet.
 */
export default function PushServiceInit() {
    const page = usePage()
    const { auth, oneSignal } = page.props
    const user = auth?.user
    const clientEnabled = Boolean(oneSignal?.client_enabled)

    const [unavailableDismissed, setUnavailableDismissed] = useState(() => {
        if (typeof sessionStorage === 'undefined') {
            return false
        }
        return sessionStorage.getItem(UNAVAILABLE_DISMISS_KEY) === '1'
    })

    useEffect(() => {
        if (!user?.id) {
            return
        }
        if (typeof console === 'undefined' || !console.log) {
            return
        }
        console.log(NS, 'mounted', {
            url: page.url,
            /** True when the server loads the push SDK + shows the consent flow. Requires PUSH_NOTIFICATIONS_ENABLED=true AND ONESIGNAL_APP_ID in .env */
            client_enabled: clientEnabled,
        })
    }, [user?.id, page.url, clientEnabled])

    useEffect(() => {
        if (!clientEnabled || !user?.id) {
            return
        }

        initPush(user)
    }, [clientEnabled, user?.id, user?.push_prompted_at, user?.push_enabled])

    const eligibleForConsent = Boolean(user && shouldShowPushPermissionDialog(user))
    const showPrePrompt = Boolean(clientEnabled && eligibleForConsent)

    const showUnavailableNotice = Boolean(
        user &&
            !clientEnabled &&
            eligibleForConsent &&
            !unavailableDismissed
    )

    useEffect(() => {
        if (showPrePrompt && typeof console !== 'undefined' && console.log) {
            console.log(NS, 'showing consent dialog')
        }
    }, [showPrePrompt])

    const dismissUnavailable = () => {
        try {
            sessionStorage.setItem(UNAVAILABLE_DISMISS_KEY, '1')
        } catch {
            // ignore
        }
        setUnavailableDismissed(true)
    }

    return (
        <>
            {showPrePrompt ? <PushPermissionDialog user={user} /> : null}
            {showUnavailableNotice ? (
                <div
                    className="pointer-events-auto fixed bottom-4 left-1/2 z-[59900] w-[max(280px,calc(100vw-2rem))] max-w-md -translate-x-1/2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-800 shadow-lg"
                    role="status"
                >
                    <p className="font-medium text-gray-900">Browser alerts aren’t on for this site yet</p>
                    <p className="mt-1 text-xs leading-relaxed text-gray-600">
                        You’re signed in, but this app hasn’t switched on push notifications, so we can’t ask your browser
                        yet. After they’re enabled on the server, reload the page and you’ll see the prompt here.
                    </p>
                    <button
                        type="button"
                        onClick={dismissUnavailable}
                        className="mt-2 text-xs font-medium text-indigo-600 hover:text-indigo-500"
                    >
                        Got it
                    </button>
                </div>
            ) : null}
        </>
    )
}
